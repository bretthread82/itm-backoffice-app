<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use CodeIgniter\Database\BaseConnection;

/**
 * Copies invoice history from the app database into the analytics database. Reads the app database,
 * writes only the analytics database, and is safe to run repeatedly: each touched invoice is rebuilt
 * from scratch (delete then insert), so a re-run or an overlapping window changes nothing.
 */
final class AnalyticsLoader
{
    private const CHUNK = 200;

    private InvoiceFactBuilder $builder;

    public function __construct(private ?BaseConnection $app = null, private ?BaseConnection $analytics = null, ?InvoiceFactBuilder $builder = null)
    {
        $this->app       ??= db_connect();
        $this->analytics ??= db_connect(AnalyticsMigrator::GROUP);
        $this->builder   = $builder ?? new InvoiceFactBuilder();
    }

    /**
     * Incremental load: invoices with new actions or a newer updated_at since the last run.
     *
     * @return array{touched: int, rebuilt: int, removed: int}
     */
    public function run(bool $dry = false, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');

        // Taken before reading so anything written while we work is picked up by the next run.
        $maxAction  = (int) ($this->app->table('invoice_actions')->selectMax('id', 'm')->get()->getRowArray()['m'] ?? 0);
        $maxUpdated = (string) ($this->app->table('invoices')->selectMax('updated_at', 'm')->get()->getRowArray()['m'] ?? '');
        $lastAction = $this->watermark('invoice_actions');
        $lastUpdate = $this->watermark('invoices');

        $ids = [];
        $q = $this->app->table('invoice_actions')->select('invoice_id')->distinct();
        if ($lastAction !== null) {
            $q->where('id >', (int) $lastAction);
        }
        foreach ($q->get()->getResultArray() as $r) {
            $ids[(int) $r['invoice_id']] = true;
        }
        $q = $this->app->table('invoices')->select('id');
        if ($lastUpdate !== null) {
            $q->where('updated_at >=', $lastUpdate);
        }
        foreach ($q->get()->getResultArray() as $r) {
            $ids[(int) $r['id']] = true;
        }
        $ids = array_keys($ids);

        $out = ['touched' => count($ids), 'rebuilt' => 0, 'removed' => 0];
        if ($dry) {
            return $out;
        }

        $this->syncDimensions();
        $out['rebuilt'] = $this->rebuild($ids, $now);
        $out['removed'] = $this->reconcileDeleted();

        $this->setWatermark('invoice_actions', (string) $maxAction, $out['rebuilt'], $now);
        $this->setWatermark('invoices', $maxUpdated !== '' ? $maxUpdated : null, $out['rebuilt'], $now);

        return $out;
    }

    /**
     * Rebuilds every invoice from the full history (the backfill, also a repair after a bug fix).
     *
     * @return array{touched: int, rebuilt: int, removed: int}
     */
    public function rebuildAll(bool $dry = false, ?string $now = null): array
    {
        $now ??= date('Y-m-d H:i:s');
        $ids = array_map(static fn (array $r): int => (int) $r['id'], $this->app->table('invoices')->select('id')->orderBy('id')->get()->getResultArray());
        if ($dry) {
            return ['touched' => count($ids), 'rebuilt' => 0, 'removed' => 0];
        }

        $maxAction  = (int) ($this->app->table('invoice_actions')->selectMax('id', 'm')->get()->getRowArray()['m'] ?? 0);
        $maxUpdated = (string) ($this->app->table('invoices')->selectMax('updated_at', 'm')->get()->getRowArray()['m'] ?? '');

        $this->syncDimensions();
        $rebuilt = $this->rebuild($ids, $now);
        $removed = $this->reconcileDeleted();
        $this->setWatermark('invoice_actions', (string) $maxAction, $rebuilt, $now);
        $this->setWatermark('invoices', $maxUpdated !== '' ? $maxUpdated : null, $rebuilt, $now);

        return ['touched' => count($ids), 'rebuilt' => $rebuilt, 'removed' => $removed];
    }

    /** When the last load finished, for "data as of" on the screens. */
    public function lastRun(): ?string
    {
        $row = $this->analytics->table('etl_state')->selectMax('last_run', 'm')->get()->getRowArray();

        return ($row['m'] ?? null) ?: null;
    }

    // ------------------------------------------------------------------ rebuild

    /**
     * @param list<int> $ids
     */
    public function rebuild(array $ids, string $now): int
    {
        $supplierCodes = $this->supplierCodes();
        $count = 0;

        foreach (array_chunk($ids, self::CHUNK) as $chunk) {
            $invoices = $this->app->table('invoices')->whereIn('id', $chunk)->get()->getResultArray();
            $actions  = $this->group($this->app->table('invoice_actions a')
                ->select('a.id, a.invoice_id, a.step_order, a.acted_by, a.acted_as, a.action, a.acted_at, u.display_name AS acted_by_name')
                ->join('users u', 'u.id = a.acted_by', 'left')
                ->whereIn('a.invoice_id', $chunk)->orderBy('a.id')->get()->getResultArray());
            $chases   = $this->group($this->app->table('step_escalations')->whereIn('invoice_id', $chunk)->get()->getResultArray());

            $facts = $steps = [];
            foreach ($invoices as $inv) {
                $id   = (int) $inv['id'];
                $code = $supplierCodes[mb_strtolower(trim((string) ($inv['supplier'] ?? '')))] ?? null;
                $built = $this->builder->build($inv, $actions[$id] ?? [], $chases[$id] ?? [], $code, $now);
                $facts[] = $built['fact'];
                array_push($steps, ...$built['steps']);
            }

            // Delete then insert in one transaction, so a reader never sees an invoice half-rebuilt.
            $this->analytics->transStart();
            $this->analytics->table('fact_step')->whereIn('invoice_id', $chunk)->delete();
            $this->analytics->table('fact_invoice')->whereIn('invoice_id', $chunk)->delete();
            if ($facts !== []) {
                $this->analytics->table('fact_invoice')->insertBatch($facts);
            }
            if ($steps !== []) {
                $this->analytics->table('fact_step')->insertBatch($steps);
            }
            $this->analytics->transComplete();
            if (! $this->analytics->transStatus()) {
                throw new \RuntimeException('Could not write to the analytics database.');
            }
            $count += count($facts);
        }

        return $count;
    }

    /** Removes facts for invoices that no longer exist in the app database. */
    private function reconcileDeleted(): int
    {
        $have = array_map(static fn (array $r): int => (int) $r['invoice_id'], $this->analytics->table('fact_invoice')->select('invoice_id')->get()->getResultArray());
        if ($have === []) {
            return 0;
        }
        $gone = [];
        foreach (array_chunk($have, 1000) as $chunk) {
            $present = array_map(static fn (array $r): int => (int) $r['id'], $this->app->table('invoices')->select('id')->whereIn('id', $chunk)->get()->getResultArray());
            array_push($gone, ...array_diff($chunk, $present));
        }
        foreach (array_chunk($gone, 1000) as $chunk) {
            $this->analytics->table('fact_step')->whereIn('invoice_id', $chunk)->delete();
            $this->analytics->table('fact_invoice')->whereIn('invoice_id', $chunk)->delete();
        }

        return count($gone);
    }

    // ------------------------------------------------------------------ dimensions

    public function syncDimensions(): void
    {
        $groups = [];
        foreach ($this->app->table('company_groups')->select('id, parent_id')->get()->getResultArray() as $g) {
            $groups[(int) $g['id']] = $g['parent_id'] !== null ? (int) $g['parent_id'] : null;
        }

        $companies = [];
        foreach ($this->app->table('companies')->select('id, code, name, group_id, active')->get()->getResultArray() as $c) {
            $path = [];
            $g = $c['group_id'] !== null ? (int) $c['group_id'] : null;
            for ($i = 0; $g !== null && array_key_exists($g, $groups) && $i < 50; $i++) {
                array_unshift($path, $g);
                $g = $groups[$g];
            }
            $companies[] = [
                'company_id' => (int) $c['id'], 'code' => (string) $c['code'], 'name' => (string) $c['name'],
                'group_id'   => $c['group_id'] !== null ? (int) $c['group_id'] : null,
                'group_path' => $path === [] ? null : ',' . implode(',', $path) . ',',
                'active'     => (int) $c['active'],
            ];
        }

        $types = array_map(static fn (array $t): array => [
            'invoice_type_id' => (int) $t['id'], 'company_id' => (int) $t['company_id'], 'name' => (string) $t['name'], 'high_priority' => (int) ($t['high_priority'] ?? 0),
        ], $this->app->table('invoice_types')->select('id, company_id, name, high_priority')->get()->getResultArray());

        $this->analytics->transStart();
        $this->analytics->table('dim_company')->emptyTable();
        $this->analytics->table('dim_invoice_type')->emptyTable();
        if ($companies !== []) {
            $this->analytics->table('dim_company')->insertBatch($companies);
        }
        if ($types !== []) {
            $this->analytics->table('dim_invoice_type')->insertBatch($types);
        }
        $this->analytics->transComplete();
    }

    // ------------------------------------------------------------------ helpers

    /** @return array<string, string> lower-cased supplier name => code */
    private function supplierCodes(): array
    {
        $map = [];
        foreach ($this->app->table('suppliers')->select('code, name')->get()->getResultArray() as $s) {
            $map[mb_strtolower(trim((string) $s['name']))] = (string) $s['code'];
        }

        return $map;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return array<int, list<array<string, mixed>>> rows grouped by invoice_id
     */
    private function group(array $rows): array
    {
        $out = [];
        foreach ($rows as $r) {
            $out[(int) $r['invoice_id']][] = $r;
        }

        return $out;
    }

    private function watermark(string $source): ?string
    {
        $row = $this->analytics->table('etl_state')->where('source', $source)->get()->getRowArray();

        return $row !== null && $row['watermark'] !== null && $row['watermark'] !== '' ? (string) $row['watermark'] : null;
    }

    private function setWatermark(string $source, ?string $value, int $rows, string $now): void
    {
        $this->analytics->table('etl_state')->where('source', $source)->delete();
        $this->analytics->table('etl_state')->insert(['source' => $source, 'watermark' => $value, 'last_run' => $now, 'rows_processed' => $rows]);
    }
}

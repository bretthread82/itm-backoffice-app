<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use App\Services\ReportService;
use App\Services\WorkflowEngine;

/**
 * Turns one invoice and its append-only history into analytics rows. Pure (no database), so the
 * rules for step visits, durations, returns and overrides are easy to test.
 *
 * Step visits are replayed from invoice_actions. Only the latest route snapshot survives a resubmit, so
 * step details for an earlier cycle are taken from that latest snapshot (best effort).
 */
final class InvoiceFactBuilder
{
    private const ESCALATION_RANK = ['reminder' => 1, 'escalation' => 2];

    /**
     * @param array<string, mixed>       $inv         the invoices row
     * @param list<array<string, mixed>> $actions     invoice_actions (acted_by_name joined), any order
     * @param list<array<string, mixed>> $escalations step_escalations rows (step_order, step_entered_at, level)
     *
     * @return array{fact: array<string, mixed>, steps: list<array<string, mixed>>}
     */
    public function build(array $inv, array $actions, array $escalations, ?string $supplierCode, string $now): array
    {
        usort($actions, static fn (array $a, array $b): int => (int) $a['id'] <=> (int) $b['id']);
        $steps = $this->actionableSteps($inv);

        $cycle = 0;
        $open = null;
        $visits = [];
        $returns = $resubmits = $overrides = 0;
        $firstSubmitted = null;

        foreach ($actions as $a) {
            $action = (string) $a['action'];
            $at     = (string) $a['acted_at'];
            $order  = $a['step_order'] !== null ? (int) $a['step_order'] : null;

            if ($action === 'submit' || $action === 'resubmit') {
                $cycle++;
                $resubmits += $action === 'resubmit' ? 1 : 0;
                $firstSubmitted ??= $at;
                $open = $this->enter($this->firstStep($steps), $at, $cycle);
                continue;
            }
            if (! in_array($action, ['approve', 'return', 'return_previous', 'reject'], true)) {
                continue; // claim, release, attach, cancel: not step transitions
            }

            if ($open !== null) {
                $visits[] = $this->close($open, $at, $action === 'approve' ? 'approved' : ($action === 'return' ? 'returned' : ($action === 'reject' ? 'rejected' : 'returned_previous')), $a);
            }
            $open = null;
            $overrides += ($a['acted_as'] ?? '') === 'group_level' ? 1 : 0;
            $returns   += in_array($action, ['return', 'return_previous'], true) ? 1 : 0;

            if ($action === 'approve' && $order !== null) {
                $open = $this->enter($this->stepAfter($steps, $order), $at, $cycle);
            } elseif ($action === 'return_previous' && $order !== null) {
                $open = $this->enter($this->stepBefore($steps, $order), $at, $cycle);
            }
        }

        // A visit still open belongs to a live invoice; for anything else it is a leftover and is dropped.
        if ($open !== null && in_array($inv['status'], WorkflowEngine::ACTIVE, true) && ($inv['current_step_order'] ?? null) !== null) {
            $visits[] = $this->finishOpen($open, $now);
        }

        foreach ($visits as &$v) {
            $v['invoice_id'] = (int) $inv['id'];
            $v['company_id'] = (int) $inv['company_id'];
            $v['escalation_level'] = $this->escalationFor($v, $escalations, $now);
        }
        unset($v);

        return ['fact' => $this->fact($inv, $supplierCode, $firstSubmitted, $returns, $resubmits, $overrides, count($visits), $now), 'steps' => $visits];
    }

    // ------------------------------------------------------------------ fact row

    /**
     * @param array<string, mixed> $inv
     *
     * @return array<string, mixed>
     */
    private function fact(array $inv, ?string $supplierCode, ?string $firstSubmitted, int $returns, int $resubmits, int $overrides, int $stepCount, string $now): array
    {
        $supplier = trim((string) ($inv['supplier'] ?? ''));

        return [
            'invoice_id'         => (int) $inv['id'],
            'company_id'         => (int) $inv['company_id'],
            'invoice_type_id'    => $inv['invoice_type_id'] !== null ? (int) $inv['invoice_type_id'] : null,
            'supplier_code'      => $supplierCode,
            'supplier_name'      => $supplier !== '' ? $supplier : null,
            'currency'           => (string) $inv['currency'],
            'amount'             => $inv['amount'] !== null ? $inv['amount'] : null,
            'status'             => (string) $inv['status'],
            'outstanding'        => ReportService::isOutstanding($inv) ? 1 : 0,
            'high_priority'      => (int) ($inv['high_priority'] ?? 0),
            'extra_approval'     => (int) ($inv['extra_approval'] ?? 0),
            'invoice_date'       => $inv['invoice_date'] ?: null,
            'due_date'           => $inv['due_date'] ?: null,
            'first_submitted_at' => $firstSubmitted ?? ($inv['submitted_at'] ?: null),
            'submitted_at'       => $inv['submitted_at'] ?: null,
            'processed_at'       => $inv['processed_at'] ?: null,
            'paid_at'            => $inv['paid_at'] ?: null,
            'ended_at'           => $inv['ended_at'] ?: null,
            'days_to_processed'  => $this->days($inv['submitted_at'] ?? null, $inv['processed_at'] ?? null),
            'days_to_paid'       => $this->days($inv['submitted_at'] ?? null, $inv['paid_at'] ?? null),
            'on_time'            => ! empty($inv['completed_at']) && ! empty($inv['due_date']) ? (substr((string) $inv['completed_at'], 0, 10) <= (string) $inv['due_date'] ? 1 : 0) : null,
            'return_count'       => $returns,
            'resubmit_count'     => $resubmits,
            'override_count'     => $overrides,
            'step_count'         => $stepCount,
            'loaded_at'          => $now,
        ];
    }

    private function days(?string $from, ?string $to): ?float
    {
        if (empty($from) || empty($to)) {
            return null;
        }

        return round(max(0, strtotime($to) - strtotime($from)) / 86400, 2);
    }

    // ------------------------------------------------------------------ step visits

    /**
     * @param array<string, mixed> $inv
     *
     * @return list<array<string, mixed>> approve/process/pay steps in order
     */
    private function actionableSteps(array $inv): array
    {
        $snap  = json_decode((string) ($inv['route_snapshot'] ?? ''), true);
        $steps = is_array($snap) ? ($snap['steps'] ?? []) : [];
        $steps = array_values(array_filter($steps, static fn (array $s): bool => in_array($s['step_type'] ?? '', WorkflowEngine::ACTION_STEPS, true)));
        usort($steps, static fn (array $a, array $b): int => (int) $a['order'] <=> (int) $b['order']);

        return $steps;
    }

    /** @param list<array<string, mixed>> $steps */
    private function firstStep(array $steps): ?array
    {
        return $steps[0] ?? null;
    }

    /** @param list<array<string, mixed>> $steps */
    private function stepAfter(array $steps, int $order): ?array
    {
        foreach ($steps as $s) {
            if ((int) $s['order'] > $order) {
                return $s;
            }
        }

        return null;
    }

    /** The nearest earlier approve/process step, as WorkflowEngine::previousStep. @param list<array<string, mixed>> $steps */
    private function stepBefore(array $steps, int $order): ?array
    {
        $found = null;
        foreach ($steps as $s) {
            if ((int) $s['order'] < $order && in_array($s['step_type'], ['approve', 'process'], true)) {
                $found = $s;
            }
        }

        return $found;
    }

    /**
     * @param array<string, mixed>|null $step
     *
     * @return array<string, mixed>|null
     */
    private function enter(?array $step, string $at, int $cycle): ?array
    {
        if ($step === null) {
            return null;
        }
        $label = trim((string) ($step['role'] ?? '') . ((string) ($step['label'] ?? '') !== '' ? ' (' . $step['label'] . ')' : ''));

        return [
            'cycle'          => max(1, $cycle),
            'step_order'     => (int) $step['order'],
            'step_type'      => (string) $step['step_type'],
            'assignee_kind'  => $step['assignee_kind'] ?? null,
            'team_id'        => $step['team_id'] ?? null,
            'user_id'        => $step['user_id'] ?? null,
            'assignee_label' => $label !== '' ? mb_substr($label, 0, 200) : null,
            'entered_at'     => $at,
            'sla_days'       => ($step['sla_days'] ?? null) !== null ? (int) $step['sla_days'] : null,
        ];
    }

    /**
     * @param array<string, mixed> $visit
     * @param array<string, mixed> $action
     *
     * @return array<string, mixed>
     */
    private function close(array $visit, string $at, string $outcome, array $action): array
    {
        $hours = round(max(0, strtotime($at) - strtotime((string) $visit['entered_at'])) / 3600, 2);

        return $visit + [
            'left_at'        => $at,
            'duration_hours' => $hours,
            'outcome'        => $outcome,
            'acted_by'       => $action['acted_by'] !== null ? (int) $action['acted_by'] : null,
            'acted_by_name'  => isset($action['acted_by_name']) ? mb_substr((string) $action['acted_by_name'], 0, 200) : null,
            'override'       => ($action['acted_as'] ?? '') === 'group_level' ? 1 : 0,
            'sla_breached'   => $visit['sla_days'] !== null && $visit['sla_days'] > 0 && $hours >= $visit['sla_days'] * 24 ? 1 : 0,
        ];
    }

    /**
     * @param array<string, mixed> $visit
     *
     * @return array<string, mixed>
     */
    private function finishOpen(array $visit, string $now): array
    {
        $hours = max(0, strtotime($now) - strtotime((string) $visit['entered_at'])) / 3600;

        return $visit + [
            'left_at' => null, 'duration_hours' => null, 'outcome' => 'open', 'acted_by' => null, 'acted_by_name' => null, 'override' => 0,
            'sla_breached' => $visit['sla_days'] !== null && $visit['sla_days'] > 0 && $hours >= $visit['sla_days'] * 24 ? 1 : 0,
        ];
    }

    /**
     * Highest reminder/escalation sent for this visit (a chase is keyed by step and the time the step was entered).
     *
     * @param array<string, mixed>       $visit
     * @param list<array<string, mixed>> $escalations
     */
    private function escalationFor(array $visit, array $escalations, string $now): ?string
    {
        $from = strtotime((string) $visit['entered_at']);
        $to   = strtotime((string) ($visit['left_at'] ?? $now));
        $best = null;
        foreach ($escalations as $e) {
            $when = strtotime((string) $e['step_entered_at']);
            if ((int) $e['step_order'] === $visit['step_order'] && $when >= $from && $when <= $to
                && (self::ESCALATION_RANK[$e['level']] ?? 0) > (self::ESCALATION_RANK[$best] ?? 0)) {
                $best = (string) $e['level'];
            }
        }

        return $best;
    }
}

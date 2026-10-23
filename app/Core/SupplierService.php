<?php

declare(strict_types=1);

namespace App\Core;

use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

/**
 * The global supplier list. Every module reads and maintains suppliers through this service
 * (service('suppliers')); nothing else touches the `suppliers` table. Reference a supplier by id; if a
 * record must never change when a supplier is renamed, also keep the name as a snapshot.
 */
class SupplierService
{
    public const MAX_CODE = 50;
    public const MAX_NAME = 200;

    private ConnectionInterface $db;

    public function __construct(?ConnectionInterface $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * Pick-list entries, ordered by name.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function options(bool $activeOnly = true): array
    {
        $b = $this->db->table('suppliers')->select('id, code, name')->orderBy('name');

        if ($activeOnly) {
            $b->where('active', 1);
        }

        return $this->shape($b->get()->getResultArray());
    }

    /**
     * Every supplier for the admin screen, with optional per-module usage counts.
     *
     * @param array<string, string> $usage alias => SQL counting uses of one supplier, with {id} standing for the
     *                                     supplier id, e.g. ['invoice_count' => 'SELECT COUNT(*) FROM inv_invoices WHERE supplier_id = {id}']
     *
     * @return list<array<string, mixed>>
     */
    public function all(array $usage = []): array
    {
        $extra = '';

        foreach ($usage as $alias => $sql) {
            $extra .= ', (' . str_replace('{id}', 's.id', $sql) . ') AS ' . preg_replace('/[^a-z0-9_]/i', '', (string) $alias);
        }

        return $this->db->query('SELECT s.id, s.code, s.name, s.active' . $extra . ' FROM suppliers s ORDER BY s.active DESC, s.name')->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->table('suppliers')->where('id', $id)->get()->getRowArray();
    }

    /** @return array<string, mixed>|null case-insensitive exact match */
    public function findByName(string $name): ?array
    {
        $name = trim($name);

        return $name === '' ? null : $this->db->table('suppliers')->where('LOWER(name)', mb_strtolower($name))->get()->getRowArray();
    }

    /** @return array<string, mixed>|null case-insensitive exact match */
    public function findByCode(string $code): ?array
    {
        $code = trim($code);

        return $code === '' ? null : $this->db->table('suppliers')->where('LOWER(code)', mb_strtolower($code))->get()->getRowArray();
    }

    public function isActive(int $id): bool
    {
        return $this->db->table('suppliers')->where(['id' => $id, 'active' => 1])->countAllResults() > 0;
    }

    /**
     * Active suppliers whose code or name contains $q (for autocomplete).
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        $b = $this->db->table('suppliers')->select('id, code, name')->where('active', 1)->orderBy('name')->limit(max(1, min(100, $limit)));

        if ($q !== '') {
            $b->groupStart()->like('name', $q)->orLike('code', $q)->groupEnd();
        }

        return $this->shape($b->get()->getResultArray());
    }

    /**
     * @param array{code?: string, name?: string, active?: bool|int} $data
     *
     * @throws RuntimeException with a message fit to show the user
     */
    public function create(array $data, ?int $actorId = null): int
    {
        [$code, $name] = $this->validated($data, null);
        $now           = date('Y-m-d H:i:s');

        $this->db->table('suppliers')->insert(['code' => $code, 'name' => $name, 'active' => ($data['active'] ?? 1) ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        service('audit')->log('supplier.created', 'supplier', $id, null, ['code' => $code, 'name' => $name], $actorId);

        return $id;
    }

    /**
     * @param array{code?: string, name?: string, active?: bool|int} $data
     *
     * @throws RuntimeException
     */
    public function update(int $id, array $data, ?int $actorId = null): void
    {
        $before        = $this->find($id) ?? throw new RuntimeException('Supplier not found.');
        [$code, $name] = $this->validated($data, $id);
        $active        = array_key_exists('active', $data) ? ($data['active'] ? 1 : 0) : (int) $before['active'];

        $this->db->table('suppliers')->where('id', $id)->update(['code' => $code, 'name' => $name, 'active' => $active, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->log(
            'supplier.updated',
            'supplier',
            $id,
            ['code' => $before['code'], 'name' => $before['name'], 'active' => (int) $before['active']],
            ['code' => $code, 'name' => $name, 'active' => $active],
            $actorId,
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{0: string, 1: string} trimmed code and name
     *
     * @throws RuntimeException
     */
    private function validated(array $data, ?int $exceptId): array
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ($code === '' || mb_strlen($code) > self::MAX_CODE) {
            throw new RuntimeException('Enter a supplier code of up to ' . self::MAX_CODE . ' characters.');
        }

        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new RuntimeException('Enter a supplier name of up to ' . self::MAX_NAME . ' characters.');
        }

        if ($this->taken('code', $code, $exceptId)) {
            throw new RuntimeException('Another supplier already has that code.');
        }

        if ($this->taken('name', $name, $exceptId)) {
            throw new RuntimeException('Another supplier already has that name.');
        }

        return [$code, $name];
    }

    private function taken(string $field, string $value, ?int $exceptId): bool
    {
        $q = $this->db->table('suppliers')->where('LOWER(' . $field . ')', mb_strtolower($value));

        if ($exceptId !== null) {
            $q->where('id !=', $exceptId);
        }

        return $q->countAllResults() > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function shape(array $rows): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name']], $rows);
    }
}

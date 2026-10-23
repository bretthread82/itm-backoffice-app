<?php

declare(strict_types=1);

namespace App\Models;

/**
 * Route variants (Standard plus any admin-defined extras). routes.variant stores variant_key.
 * Lookups are cached per instance only; create a fresh one per request or test.
 */
class RouteVariantModel
{
    public const STANDARD_KEY = 'default';

    /** @var list<array<string, mixed>>|null */
    private ?array $rows = null;

    /**
     * @return list<array<string, mixed>> ordered: standard first, then by sort_order, name
     */
    public function all(): array
    {
        return $this->rows ??= db_connect()->table('route_variants')
            ->orderBy('is_standard', 'DESC')->orderBy('sort_order')->orderBy('name')
            ->get()->getResultArray();
    }

    /**
     * @return array<string, string> variant_key => name
     */
    public function labels(): array
    {
        return array_column($this->all(), 'name', 'variant_key');
    }

    /**
     * Variants that can be chosen for a new route.
     *
     * @return list<array<string, mixed>>
     */
    public function active(): array
    {
        return array_values(array_filter($this->all(), static fn (array $v): bool => (int) $v['active'] === 1));
    }

    /**
     * @return array<string, int> variant_key => sort_order, for ordering triggered routes
     */
    public function sortOrders(): array
    {
        return array_map('intval', array_column($this->all(), 'sort_order', 'variant_key'));
    }

    public function name(string $key): string
    {
        return $this->labels()[$key] ?? $key;
    }

    public function isStandard(string $key): bool
    {
        return $key === self::STANDARD_KEY;
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return db_connect()->table('route_variants')->where('id', $id)->get()->getRowArray();
    }
}

<?php

namespace App\Core;

use Config\Backoffice;

/**
 * Enables and disables optional modules. A module is optional when its registry entry says
 * 'optional' => true; everything else is core and always on. A disabled module is hidden from the
 * menu and every permission it owns stops working for everyone (including Super Admins), so its
 * routes are refused by the `can:` filter. Data is kept, so re-enabling restores everything.
 */
class ModuleService
{
    /** @var array<string, string>|null permission key => module key */
    private ?array $owners = null;

    /** @var list<string>|null */
    private ?array $disabled = null;

    /**
     * @return array<string, array<string, mixed>> optional modules keyed by module key
     */
    public function optional(): array
    {
        return array_filter(config(Backoffice::class)->modules, static fn (array $m): bool => ! empty($m['optional']));
    }

    public function isOptional(string $key): bool
    {
        return isset($this->optional()[$key]);
    }

    public function enabled(string $key): bool
    {
        return ! in_array($key, $this->disabledKeys(), true);
    }

    /**
     * True when the permission belongs to a module that is switched off.
     */
    public function blocks(string $permission): bool
    {
        $owner = $this->owners()[$permission] ?? null;

        return $owner !== null && ! $this->enabled($owner);
    }

    public function setEnabled(string $key, bool $enabled): void
    {
        if (! $this->isOptional($key)) {
            throw new \RuntimeException('That module is part of the core and cannot be switched off.');
        }

        $off = array_values(array_diff($this->disabledKeys(), [$key]));

        if (! $enabled) {
            $off[] = $key;
        }

        setting()->set('ModuleState.disabled', array_values(array_unique($off)));
        $this->disabled = null;

        service('audit')->log($enabled ? 'module.enabled' : 'module.disabled', 'module', $key);
    }

    /**
     * @return list<string>
     */
    private function disabledKeys(): array
    {
        return $this->disabled ??= array_values(array_map('strval', (array) (setting('ModuleState.disabled') ?? [])));
    }

    /**
     * @return array<string, string>
     */
    private function owners(): array
    {
        if ($this->owners === null) {
            $this->owners = [];

            foreach (config(Backoffice::class)->modules as $key => $module) {
                foreach (array_keys($module['permissions']) as $permission) {
                    $this->owners[$permission] = (string) $key;
                }
            }
        }

        return $this->owners;
    }
}

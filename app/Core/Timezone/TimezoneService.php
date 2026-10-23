<?php

namespace App\Core\Timezone;

use Config\App;
use DateTimeImmutable;
use DateTimeZone;

/**
 * Stored timestamps are UTC. This works out which zone to show them in:
 * the user's own, else the active company's, else their first company's, else the app default.
 */
final class TimezoneService
{
    /** @var array<int, string> */
    private array $userCache = [];

    /** @var array<int, string|null> */
    private array $companyCache = [];

    public function appDefault(): string
    {
        return config(App::class)->appTimezone ?: 'UTC';
    }

    public function isValid(?string $tz): bool
    {
        return $tz !== null && $tz !== '' && in_array($tz, DateTimeZone::listIdentifiers(), true);
    }

    /**
     * @return array<string, list<string>> identifiers grouped by region
     */
    public function options(): array
    {
        $grouped = [];
        foreach (DateTimeZone::listIdentifiers() as $id) {
            $region = str_contains($id, '/') ? strstr($id, '/', true) : 'Other';
            $grouped[$region][] = $id;
        }

        return $grouped;
    }

    public function forCompany(?int $companyId): string
    {
        return $this->companyOwn($companyId) ?? $this->appDefault();
    }

    /**
     * The zone set on the company itself, or null when it has none.
     */
    private function companyOwn(?int $companyId): ?string
    {
        if ($companyId === null) {
            return null;
        }
        if (! array_key_exists($companyId, $this->companyCache)) {
            $row = db_connect()->table('companies')->select('timezone')->where('id', $companyId)->get()->getRowArray();
            $this->companyCache[$companyId] = $row['timezone'] ?? null;
        }
        $tz = $this->companyCache[$companyId];

        return $this->isValid($tz) ? $tz : null;
    }

    /**
     * The profile timezone only, without any fallback.
     */
    public function profileOwn(?int $userId): ?string
    {
        if ($userId === null) {
            return null;
        }
        $row = db_connect()->table('staff_profiles')->select('timezone')->where('user_id', $userId)->get()->getRowArray();
        $tz  = $row['timezone'] ?? null;

        return $this->isValid($tz) ? $tz : null;
    }

    /**
     * The zone that applies when the user has not set their own.
     */
    public function companyDefaultFor(?int $userId): string
    {
        if ($userId === null) {
            return $this->appDefault();
        }
        $active = service('activeCompany')->current($userId);
        if ($active !== null && ($tz = $this->companyOwn((int) $active)) !== null) {
            return $tz;
        }
        foreach (array_keys(service('memberships')->forUser($userId)) as $companyId) {
            if (($tz = $this->companyOwn((int) $companyId)) !== null) {
                return $tz;
            }
        }

        return $this->appDefault();
    }

    public function forUser(?int $userId): string
    {
        $key = $userId ?? 0;

        return $this->userCache[$key] ??= $this->profileOwn($userId) ?? $this->companyDefaultFor($userId);
    }

    public function forgetCache(): void
    {
        $this->userCache = $this->companyCache = [];
    }

    public function toLocal(?string $utc, ?string $tz = null, string $format = 'j M, H:i'): string
    {
        if ($utc === null || $utc === '') {
            return '';
        }
        $tz ??= $this->forUser($this->currentUserId());
        $dt  = new DateTimeImmutable($utc, new DateTimeZone('UTC'));

        return $dt->setTimezone(new DateTimeZone($tz))->format($format);
    }

    /**
     * Turns a wall-clock value entered in $tz (e.g. a datetime-local field) into a UTC 'Y-m-d H:i:s'.
     */
    public function toUtc(string $local, ?string $tz = null): string
    {
        $tz ??= $this->forUser($this->currentUserId());
        $dt  = new DateTimeImmutable($local, new DateTimeZone($tz));

        return $dt->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
    }

    /**
     * Today's date ('Y-m-d') on a company's calendar; the signed-in user's when no company is given.
     */
    public function todayFor(?int $companyId = null): string
    {
        $tz = $companyId !== null ? $this->forCompany($companyId) : $this->forUser($this->currentUserId());

        return (new DateTimeImmutable('now', new DateTimeZone($tz)))->format('Y-m-d');
    }

    private function currentUserId(): ?int
    {
        $id = function_exists('auth') ? auth()->id() : null;

        return $id === null ? null : (int) $id;
    }
}

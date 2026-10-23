<?php

declare(strict_types=1);

namespace App\Core\Tenancy;

/**
 * The company a user has chosen to work in. It only ever narrows what AccessScope allows:
 * a stored choice the user can no longer see is ignored. UI only; never use it in an API.
 *
 * Mode: none (no access), single (static label), multi (dropdown). Company id 0 = all.
 */
final class ActiveCompany
{
    public const SESSION_KEY = 'active_company';

    public function __construct(private ?AccessScope $scope = null)
    {
        $this->scope ??= new AccessScope();
    }

    /** @return list<array<string, mixed>> */
    public function available(int $userId): array
    {
        return $this->scope->visibleCompanies($userId);
    }

    /** @return 'none'|'single'|'multi' */
    public function mode(int $userId): string
    {
        $n = count($this->available($userId));

        return match (true) {
            $n === 0 => 'none',
            $n === 1 && ! $this->scope->isGroupLevel($userId) => 'single',
            default => 'multi',
        };
    }

    /** Company id the user is narrowed to, or null for everything they may see. */
    public function current(int $userId): ?int
    {
        $id = (int) (session()->get(self::SESSION_KEY) ?? 0);

        if ($id === 0) {
            return null;
        }

        return $this->scope->canViewCompany($userId, $id) ? $id : null;
    }

    /** @return bool false when the user may not view that company */
    public function set(int $userId, int $companyId): bool
    {
        if ($companyId === 0) {
            session()->remove(self::SESSION_KEY);

            return true;
        }

        if (! $this->scope->canViewCompany($userId, $companyId)) {
            return false;
        }

        session()->set(self::SESSION_KEY, $companyId);

        return true;
    }
}

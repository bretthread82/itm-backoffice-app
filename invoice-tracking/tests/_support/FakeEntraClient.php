<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Entra\EntraClient;
use App\Services\Entra\EntraException;
use App\Services\Entra\EntraIdentity;

/**
 * Stands in for Microsoft in feature tests. The test sets what "Microsoft" will say about the person.
 */
final class FakeEntraClient implements EntraClient
{
    public ?EntraIdentity $identity = null;
    public bool $failExchange       = false;

    /** @var list<array{code: string, nonce: string, pkce: string|null}> */
    public array $completed = [];

    public function begin(): array
    {
        return ['url' => 'https://login.microsoftonline.test/authorize?state=state-abc', 'state' => 'state-abc', 'nonce' => 'nonce-xyz', 'pkce' => 'pkce-verifier'];
    }

    public function complete(string $code, string $nonce, ?string $pkce): EntraIdentity
    {
        $this->completed[] = ['code' => $code, 'nonce' => $nonce, 'pkce' => $pkce];

        if ($this->failExchange || $this->identity === null) {
            throw new EntraException('Simulated Microsoft failure');
        }

        return $this->identity;
    }
}

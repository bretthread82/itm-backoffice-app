<?php

declare(strict_types=1);

namespace App\Services\Entra;

/**
 * The two Microsoft round trips. Behind an interface so tests never touch the network.
 */
interface EntraClient
{
    /**
     * @return array{url: string, state: string, nonce: string, pkce: string|null}
     */
    public function begin(): array;

    /**
     * Exchanges the authorisation code and returns the validated identity.
     *
     * @throws EntraException for anything wrong with the response or token
     */
    public function complete(string $code, string $nonce, ?string $pkce): EntraIdentity;
}

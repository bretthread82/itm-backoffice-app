<?php

declare(strict_types=1);

namespace App\Filters;

use CodeIgniter\Filters\FilterInterface;
use CodeIgniter\HTTP\RequestInterface;
use CodeIgniter\HTTP\ResponseInterface;

/**
 * Personal access token sign-in for /api. Only a bearer token authenticates here: a browser session
 * cookie never does. The token's owner must be an active user and the token must carry the scope
 * given as the argument (`api-auth:api.read`). Failures are JSON in the API's error shape.
 */
class ApiAuth implements FilterInterface
{
    public function before(RequestInterface $request, $arguments = null)
    {
        $authenticator = auth('tokens')->getAuthenticator();
        $result        = $authenticator->attempt(['token' => $request->getHeaderLine('Authorization')]);

        if (! $result->isOK()) {
            return $this->fail(401, 'unauthorized', 'Send a valid personal access token as "Authorization: Bearer <token>".')
                ->setHeader('WWW-Authenticate', 'Bearer');
        }

        $user = $authenticator->getUser();
        if ($user === null || ! $user->active) {
            $authenticator->logout();

            return $this->fail(403, 'account_disabled', 'This account is not active.');
        }

        $scope = (string) ($arguments[0] ?? '');
        if ($scope !== '' && $user->tokenCant($scope)) {
            return $this->fail(403, 'insufficient_scope', 'This token does not carry the "' . $scope . '" scope.');
        }

        return null;
    }

    public function after(RequestInterface $request, ResponseInterface $response, $arguments = null): void
    {
    }

    private function fail(int $status, string $code, string $message): ResponseInterface
    {
        return service('response')->setStatusCode($status)->setJSON(['error' => ['code' => $code, 'message' => $message]]);
    }
}

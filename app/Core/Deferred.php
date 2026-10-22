<?php

namespace App\Core;

use Throwable;

/**
 * Runs work after the HTTP response has been delivered, so that slow or
 * data-dependent work (looking up an account, sending email) cannot be
 * observed through response timing. Under PHP-FPM the client is released with
 * fastcgi_finish_request(); on the built-in dev server it simply runs last.
 */
class Deferred
{
    /** @var list<callable> */
    private static array $queue = [];
    private static bool $registered = false;

    public static function add(callable $job): void
    {
        self::$queue[] = $job;

        if (! self::$registered && ENVIRONMENT !== 'testing') {
            self::$registered = true;
            register_shutdown_function([self::class, 'flush']);
        }
    }

    public static function flush(): void
    {
        if (self::$queue !== [] && function_exists('fastcgi_finish_request')) {
            @fastcgi_finish_request();
        }

        while (($job = array_shift(self::$queue)) !== null) {
            try {
                $job();
            } catch (Throwable $e) {
                log_message('error', 'Deferred job failed: ' . $e->getMessage());
            }
        }
    }
}

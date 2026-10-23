<?php

declare(strict_types=1);

namespace App\Services\Analytics;

use CodeIgniter\Database\BaseConnection;
use CodeIgniter\Database\MigrationRunner;

/**
 * Runs the analytics-only migrations (app/AnalyticsDb/Migrations) against the `analytics` connection.
 *
 * Deliberately not a registered namespace, so `php spark migrate --all` and the PHPUnit refresh of the
 * app database never see these files; the history lives in the analytics database's own `migrations` table.
 */
final class AnalyticsMigrator extends MigrationRunner
{
    public const GROUP = 'analytics';

    public function __construct()
    {
        parent::__construct(config('Migrations'), self::GROUP);
        $this->path      = APPPATH . 'AnalyticsDb' . DIRECTORY_SEPARATOR . 'Migrations';
        $this->namespace = 'AnalyticsDb';
    }

    /** Brings the analytics schema up to date. @return list<string> progress messages */
    public function upgrade(): array
    {
        $this->setSilent(false)->latest(self::GROUP);

        return $this->getCliMessages();
    }

    /** Drops every analytics table (used by tests to start clean). Only ever touches the analytics connection. */
    public static function wipe(): void
    {
        /** @var BaseConnection $db */
        $db = db_connect(self::GROUP);
        $db->disableForeignKeyChecks();
        foreach ($db->listTables() as $table) {
            $db->query('DROP TABLE IF EXISTS ' . $db->escapeIdentifiers($table));
        }
        $db->enableForeignKeyChecks();
        $db->resetDataCache(); // raw DROPs bypass the forge, which would otherwise keep a stale table list
    }
}

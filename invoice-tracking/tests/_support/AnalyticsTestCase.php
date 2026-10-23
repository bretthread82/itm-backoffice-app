<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Services\Analytics\AnalyticsMigrator;
use CodeIgniter\Database\BaseConnection;

/**
 * App schema (AppTestCase) plus a freshly migrated analytics database. Under PHPUnit the `analytics`
 * group is swapped for `analyticsTests` (invoicing_test_analytics), so real analytics data is never touched.
 */
abstract class AnalyticsTestCase extends AppTestCase
{
    protected BaseConnection $analytics;

    protected function setUp(): void
    {
        parent::setUp();
        AnalyticsMigrator::wipe();
        (new AnalyticsMigrator())->upgrade(); // a fresh runner: it caches whether its migrations table exists
        $this->analytics = db_connect(AnalyticsMigrator::GROUP);
    }
}

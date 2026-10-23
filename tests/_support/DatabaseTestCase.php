<?php

declare(strict_types=1);

namespace Tests\Support;

use App\Database\Seeds\RbacSeeder;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use CodeIgniter\Test\Fabricator;

/**
 * Database tests. The trait's refresh rolls every migration back and forward before each test,
 * which is very slow, so this base class works differently:
 *
 * - Schema: built once per PHPUnit run. It is reused when every migration is already recorded,
 *   otherwise the test database is emptied and migrated (so an interrupted run cannot leave it
 *   half built). Set TESTS_REBUILD=1 to force a rebuild, for example after editing a migration.
 * - Data: before each test every table is emptied in one transaction, except the system role
 *   tables, which are re-seeded only when a test changed them.
 *
 * Tests must not change the schema, and must not rely on auto-increment ids restarting at 1.
 */
abstract class DatabaseTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate   = false;   // handled in setUpDatabase()
    protected $refresh   = false;
    protected $namespace = null;
    protected $seed      = '';      // handled in setUpDatabase()

    /** Tables holding the seeded system roles; kept between tests while unchanged. */
    private const RBAC_TABLES = ['permissions', 'roles', 'role_permissions'];

    private static bool $ready = false;

    /** @var list<string> */
    private static array $tables = [];

    private static string $rbacChecksum = '';

    protected function setUpDatabase()
    {
        $this->loadDependencies();

        if (! self::$ready) {
            $this->prepareSchema();
            $this->seedRoles();
            self::$ready = true;
        } else {
            $this->clearData();
        }

        Fabricator::resetCounts();
    }

    private function prepareSchema(): void
    {
        $name = (string) $this->db->getDatabase();

        if (! str_ends_with($name, '_test')) {
            throw new \RuntimeException("Refusing to touch '{$name}': the tests database name must end in _test.");
        }

        $this->migrations->setNamespace(null);

        if (getenv('TESTS_REBUILD') === '1' || ! $this->migrationsCurrent()) {
            $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

            // The migration log table stays (the runner only creates it once per process); its rows go.
            foreach ($this->db->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name <> 'migrations'")->getResultArray() as $row) {
                $this->db->query(($row['k'] === 'VIEW' ? 'DROP VIEW IF EXISTS `' : 'DROP TABLE IF EXISTS `') . $row['t'] . '`');
            }

            $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

            if ($this->db->tableExists('migrations')) {
                $this->db->query('DELETE FROM `migrations`');
            }

            $this->migrations->latest('tests');
        }

        self::$tables = $this->baseTables();
        $this->db->query("ALTER TABLE `migrations` COMMENT = 'tests schema: " . count(self::$tables) . " tables'");
        $this->clearData(true);
    }

    private function migrationsCurrent(): bool
    {
        try {
            $recorded = $this->db->tableExists('migrations') ? $this->db->table('migrations')->countAllResults() : 0;

            $comment  = (string) $this->db->query("SELECT table_comment AS c FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name = 'migrations'")->getRow('c');

            // The recorded table count catches a table dropped behind the migration log's back.
            return $recorded > 0
                && $recorded === count($this->migrations->findMigrations())
                && $comment === 'tests schema: ' . count($this->baseTables()) . ' tables';
        } catch (\Throwable) {
            return false;
        }
    }

    /**
     * @return list<string>
     */
    private function baseTables(): array
    {
        return array_column(
            $this->db->query("SELECT table_name AS t FROM information_schema.tables WHERE table_schema = DATABASE() AND table_type = 'BASE TABLE'")->getResultArray(),
            't'
        );
    }

    /**
     * @param bool $everything also empty the system role tables
     */
    private function clearData(bool $everything = false): void
    {
        if (! $everything && $this->rbacChecksum() !== self::$rbacChecksum) {
            $everything = true;
        }

        $this->db->query('SET FOREIGN_KEY_CHECKS = 0');

        // TRUNCATE (it commits implicitly, so it runs first) so a re-seed gives the roles the same ids again: code caches them.
        if ($everything) {
            foreach (self::RBAC_TABLES as $table) {
                $this->db->query('TRUNCATE TABLE `' . $table . '`');
            }
        }

        $this->db->transStart();

        foreach (self::$tables as $table) {
            if ($table !== 'migrations' && ! in_array($table, self::RBAC_TABLES, true)) {
                $this->db->query('DELETE FROM `' . $table . '`');
            }
        }

        $this->db->transComplete();
        $this->db->query('SET FOREIGN_KEY_CHECKS = 1');

        if ($everything && self::$ready) {
            $this->seedRoles();
        }
    }

    private function seedRoles(): void
    {
        $this->seeder->setSilent(true);
        $this->seeder->call(RbacSeeder::class);
        self::$rbacChecksum = $this->rbacChecksum();
    }

    private function rbacChecksum(): string
    {
        $out = '';

        foreach ($this->db->query('CHECKSUM TABLE `' . implode('`, `', self::RBAC_TABLES) . '`')->getResultArray() as $row) {
            $out .= $row['Checksum'] . ',';
        }

        return $out;
    }
}

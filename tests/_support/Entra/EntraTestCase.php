<?php

namespace Tests\Support\Entra;

use App\Core\Entra\EntraClient;
use App\Database\Seeds\RbacSeeder;
use CodeIgniter\Config\Factories;
use CodeIgniter\Test\CIUnitTestCase;
use CodeIgniter\Test\DatabaseTestTrait;
use Config\Entra;

/**
 * Shared setup: fresh schema + system roles, and an Entra config that is
 * "ready" with obviously fake values (no network is ever touched).
 */
abstract class EntraTestCase extends CIUnitTestCase
{
    use DatabaseTestTrait;

    protected $migrate     = true;
    protected $migrateOnce = false;
    protected $refresh     = true;
    protected $namespace   = null;
    protected $seed        = RbacSeeder::class;

    public const TENANT = '11111111-1111-1111-1111-111111111111';
    public const CLIENT = '22222222-2222-2222-2222-222222222222';

    protected Entra $entra;

    protected function setUp(): void
    {
        parent::setUp();

        $this->entra               = new Entra();
        $this->entra->enabled      = true;
        $this->entra->tenantId     = self::TENANT;
        $this->entra->clientId     = self::CLIENT;
        $this->entra->clientSecret = 'fake-secret';

        Factories::injectMock('config', Entra::class, $this->entra);
        cache()->clean();
        // The permissions service memoises per user id, and ids restart every test.
        \Config\Services::resetSingle('permissions');
    }

    /**
     * A client whose Graph reads come from fixtures instead of the network.
     *
     * @param list<array<string, mixed>>        $users
     * @param array<string, list<string>>       $groupMembers group id => member oids
     */
    protected function fakeClient(array $users, array $groupMembers = []): EntraClient
    {
        return new class ($this->entra, $users, $groupMembers) extends EntraClient {
            public function __construct(Entra $config, private array $users, private array $groups)
            {
                parent::__construct($config);
            }

            public function graphPaged(string $path, array $query = []): \Generator
            {
                if ($path === '/users') {
                    yield from $this->users;

                    return;
                }

                if (preg_match('#^/groups/([^/]+)/transitiveMembers#', $path, $m)) {
                    if (! isset($this->groups[$m[1]])) {
                        throw new \RuntimeException('Microsoft Graph error: group not found');
                    }

                    foreach ($this->groups[$m[1]] as $oid) {
                        yield ['id' => $oid];
                    }
                }
            }
        };
    }

    /**
     * @return array<string, mixed>
     */
    protected function entraUser(string $oid, string $mail, array $extra = []): array
    {
        return $extra + [
            'id' => $oid, 'mail' => $mail, 'userPrincipalName' => $mail, 'givenName' => 'Given', 'surname' => 'Sur',
            'displayName' => 'Given Sur', 'accountEnabled' => true, 'userType' => 'Member',
        ];
    }

    protected function profileByOid(string $oid): ?array
    {
        return db_connect()->table('staff_profiles')->where('entra_object_id', $oid)->get()->getRowArray();
    }

    protected function roleKeys(int $userId): array
    {
        $rows = db_connect()->table('user_roles ur')->select('r.key')->join('roles r', 'r.id = ur.role_id')
            ->where('ur.user_id', $userId)->get()->getResultArray();
        $keys = array_column($rows, 'key');
        sort($keys);

        return $keys;
    }
}

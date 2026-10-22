<?php

namespace Tests\Entra;

use Tests\Support\Entra\EntraTestCase;
use App\Core\Entra\EntraUserService;
use App\Core\UserService;

final class EntraUserServiceTest extends EntraTestCase
{
    private function localUser(string $email): int
    {
        // UserService::create audits against the current actor; none is signed in, which is fine.
        return (new UserService())->create(['email' => $email, 'first_name' => 'Local', 'last_name' => 'User'], [])['id'];
    }

    public function testCreatesAnEntraAccountWithDefaultRoles(): void
    {
        $r = (new EntraUserService())->resolve(['oid' => 'OID-1', 'email' => 'New.Person@Example.com', 'first_name' => 'New', 'last_name' => 'Person'], true);

        $this->assertTrue($r['created']);
        $p = $this->profileByOid('oid-1');
        $this->assertSame('entra', $p['source']);
        $this->assertSame(['staff'], $this->roleKeys((int) $p['user_id']));
        $this->seeInDatabase('auth_identities', ['secret' => 'new.person@example.com']);
    }

    public function testDoesNotCreateWhenProvisioningIsOff(): void
    {
        $this->assertNull((new EntraUserService())->resolve(['oid' => 'oid-2', 'email' => 'nobody@example.com'], false));
        $this->assertNull($this->profileByOid('oid-2'));
    }

    public function testLinksAnUnlinkedLocalAccountByEmail(): void
    {
        $id = $this->localUser('jane@example.com');

        $r = (new EntraUserService())->resolve(['oid' => 'oid-3', 'email' => 'JANE@example.com'], false);

        $this->assertTrue($r['linked']);
        $this->assertSame($id, $r['user_id']);
        $this->assertSame('entra', $this->profileByOid('oid-3')['source']);
    }

    public function testNeverRelinksAnAccountOwnedByAnotherEntraIdentity(): void
    {
        $svc = new EntraUserService();
        $svc->resolve(['oid' => 'oid-owner', 'email' => 'shared@example.com'], true);

        // A different Entra object presenting the same email must not take the account over.
        $this->assertNull($svc->resolve(['oid' => 'oid-attacker', 'email' => 'shared@example.com'], true));
        $this->assertNull($this->profileByOid('oid-attacker'));
        $this->assertNotNull($this->profileByOid('oid-owner'));
    }

    public function testEmailLinkingCanBeDisabled(): void
    {
        $this->entra->linkExistingByEmail = false;
        $this->localUser('kim@example.com');

        $this->assertNull((new EntraUserService())->resolve(['oid' => 'oid-4', 'email' => 'kim@example.com'], false));
    }

    public function testSecondSignInMatchesByOidEvenIfEmailChanged(): void
    {
        $svc   = new EntraUserService();
        $first = $svc->resolve(['oid' => 'oid-5', 'email' => 'old@example.com'], true);
        $again = $svc->resolve(['oid' => 'oid-5', 'email' => 'renamed@example.com'], false);

        $this->assertSame($first['user_id'], $again['user_id']);
        $this->assertFalse($again['created']);
    }

    public function testDirectoryFieldsOverwriteButContactFieldsOnlyFillGaps(): void
    {
        $svc = new EntraUserService();
        $r   = $svc->resolve(['oid' => 'oid-6', 'email' => 'p@example.com', 'job_title' => 'Analyst', 'phone' => '111'], true);
        db_connect()->table('staff_profiles')->where('user_id', $r['user_id'])->update(['phone' => 'my-own-number']);

        $svc->resolve(['oid' => 'oid-6', 'email' => 'p@example.com', 'job_title' => 'Senior Analyst', 'phone' => '999'], false);

        $p = $this->profileByOid('oid-6');
        $this->assertSame('Senior Analyst', $p['job_title']);
        $this->assertSame('my-own-number', $p['phone']);
    }

    public function testSuperAdminIsNeverDeactivated(): void
    {
        $svc = new EntraUserService();
        $r   = $svc->resolve(['oid' => 'oid-7', 'email' => 'root@example.com'], true);
        $superId = db_connect()->table('roles')->where('key', 'super_admin')->get()->getRow('id');
        db_connect()->table('user_roles')->insert(['user_id' => $r['user_id'], 'role_id' => $superId]);
        service('permissions')->forget($r['user_id']);

        $this->assertFalse($svc->deactivate($r['user_id']));
        $this->assertSame('active', $this->profileByOid('oid-7')['status']);
    }

    public function testOnlyEntraDeactivatedAccountsAreAutoReactivated(): void
    {
        $svc = new EntraUserService();
        $a   = $svc->resolve(['oid' => 'oid-8', 'email' => 'a@example.com'], true)['user_id'];
        $b   = $svc->resolve(['oid' => 'oid-9', 'email' => 'b@example.com'], true)['user_id'];

        $this->assertTrue($svc->deactivate($a), 'deactivate() should succeed');   // by the integration
        $row = db_connect()->table('users')->where('id', $a)->get()->getRowArray();
        $this->assertSame('banned', $row['status'], 'user row: ' . json_encode($row));
        (new UserService())->setStatus($b, 'inactive');          // by an admin (no actor => not self)

        $this->assertTrue($svc->reactivateIfEntraDisabled($a));
        $this->assertFalse($svc->reactivateIfEntraDisabled($b), 'An admin deactivation must stick.');
        $this->assertSame('inactive', $this->profileByOid('oid-9')['status']);
    }
}

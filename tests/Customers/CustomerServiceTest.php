<?php

declare(strict_types=1);

namespace Tests\Customers;

use CodeIgniter\Test\FeatureTestTrait;
use Config\Services;
use RuntimeException;
use Tests\Support\Mfa\MfaTestCase;

/**
 * @internal
 */
final class CustomerServiceTest extends MfaTestCase
{
    use FeatureTestTrait;

    protected bool $useMockSession = false;

    public function testCreateFindAndOptions(): void
    {
        $svc = service('customers');
        $id  = $svc->create(['code' => 'C1', 'name' => 'Harbour Foods', 'email' => 'a@example.test', 'payment_terms_days' => '30', 'default_currency' => 'usd']);
        $svc->create(['code' => 'C2', 'name' => 'Beta Retail', 'active' => false]);

        $row = $svc->find($id);
        $this->assertSame('Harbour Foods', $row['name']);
        $this->assertSame(30, (int) $row['payment_terms_days']);
        $this->assertSame('USD', $row['default_currency']);
        $this->assertSame($id, (int) $svc->findByName('  harbour FOODS ')['id']);
        $this->assertSame($id, (int) $svc->findByCode('c1')['id']);
        $this->assertSame(['Harbour Foods'], array_column($svc->options(), 'name'), 'Inactive customers are not offered.');
        $this->assertCount(2, $svc->options(false));
        $this->assertSame([$id], array_column($svc->search('harb'), 'id'));
        $this->assertTrue($svc->isActive($id));
    }

    public function testBadInputIsRefused(): void
    {
        $svc = service('customers');
        $svc->create(['code' => 'C1', 'name' => 'Acme']);

        $bad = [
            ['code' => 'C1', 'name' => 'Other'],
            ['code' => 'c9', 'name' => 'acme'],
            ['code' => '', 'name' => 'X'],
            ['code' => 'C3', 'name' => ' '],
            ['code' => 'C4', 'name' => 'Y', 'email' => 'not-an-email'],
            ['code' => 'C5', 'name' => 'Z', 'payment_terms_days' => '-3'],
            ['code' => 'C6', 'name' => 'W', 'default_currency' => 'DOLLARS'],
        ];

        foreach ($bad as $data) {
            try {
                $svc->create($data);
                $this->fail('Expected a RuntimeException for ' . json_encode($data));
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function testUpdateKeepsActiveWhenOmittedAndCanSwitchOff(): void
    {
        $svc = service('customers');
        $id  = $svc->create(['code' => 'C1', 'name' => 'Acme']);

        $svc->update($id, ['code' => 'C1', 'name' => 'Acme Ltd']);
        $this->assertTrue($svc->isActive($id));

        $svc->update($id, ['code' => 'C1', 'name' => 'Acme Ltd', 'active' => false]);
        $this->assertFalse($svc->isActive($id));
        $this->assertSame('Acme Ltd', $svc->find($id)['name']);
    }

    public function testContactsKeepOnePrimary(): void
    {
        $svc = service('customers');
        $id  = $svc->create(['code' => 'C1', 'name' => 'Acme']);

        $first  = $svc->addContact($id, ['name' => 'Ann']);
        $second = $svc->addContact($id, ['name' => 'Bob', 'is_primary' => true]);

        $contacts = $svc->contacts($id);
        $this->assertSame('Bob', $contacts[0]['name']);
        $this->assertCount(1, array_filter($contacts, static fn (array $c): bool => (int) $c['is_primary'] === 1));

        $svc->removeContact($id, $second);
        $left = $svc->contacts($id);
        $this->assertCount(1, $left);
        $this->assertSame($first, (int) $left[0]['id']);
        $this->assertSame(1, (int) $left[0]['is_primary'], 'Removing the primary promotes another contact.');
    }

    public function testOnlyOneContactReceivesNotifications(): void
    {
        $svc = service('customers');
        $id  = $svc->create(['code' => 'C1', 'name' => 'Acme']);

        $this->assertNull($svc->notificationContact($id));

        $ann = $svc->addContact($id, ['name' => 'Ann', 'email' => 'ann@example.com', 'is_notify' => true]);
        $bob = $svc->addContact($id, ['name' => 'Bob', 'email' => 'bob@example.com']);
        $this->assertSame($ann, (int) $svc->notificationContact($id)['id']);

        $svc->setNotificationContact($id, $bob);
        $this->assertSame($bob, (int) $svc->notificationContact($id)['id']);
        $this->assertCount(1, array_filter($svc->contacts($id), static fn (array $c): bool => (int) $c['is_notify'] === 1));

        $svc->clearNotificationContact($id);
        $this->assertNull($svc->notificationContact($id));
    }

    public function testNotificationContactNeedsAnEmail(): void
    {
        $svc = service('customers');
        $id  = $svc->create(['code' => 'C1', 'name' => 'Acme']);
        $cid = $svc->addContact($id, ['name' => 'Ann']);

        $this->expectException(RuntimeException::class);
        $svc->setNotificationContact($id, $cid);
    }

    public function testContactOfAnotherCustomerCannotBeRemoved(): void
    {
        $svc = service('customers');
        $a   = $svc->create(['code' => 'C1', 'name' => 'Acme']);
        $b   = $svc->create(['code' => 'C2', 'name' => 'Beta']);
        $cid = $svc->addContact($a, ['name' => 'Ann']);

        $this->expectException(RuntimeException::class);
        $svc->removeContact($b, $cid);
    }

    public function testUsageCountsAreInjectedWithoutNamingTables(): void
    {
        $svc = service('customers');
        $id  = $svc->create(['code' => 'C1', 'name' => 'Acme']);
        $svc->addContact($id, ['name' => 'Ann']);

        $rows = $svc->all(['contact_count' => 'SELECT COUNT(*) FROM customer_contacts WHERE customer_id = {id}']);

        $this->assertSame(1, (int) $rows[0]['contact_count']);
    }

    public function testManageScreensAreGatedByPermission(): void
    {
        Services::resetSingle('session');
        session();

        $manager = $this->makeUser('cm@example.test', [$this->roleId('admin')]);
        $staff   = $this->makeUser('st@example.test', [$this->roleId('staff')]);

        foreach ([$manager, $staff] as $uid) {
            $this->db->table('auth_identities')->where('user_id', $uid)->update(['force_reset' => 0]);
        }

        $as = function (int $uid, string $path) {
            Services::resetSingle('auth');
            Services::resetSingle('authentication');
            Services::resetSingle('session');
            session();

            return $this->withSession(['user' => ['id' => $uid]])->get($path);
        };

        foreach (['admin/customers', 'admin/customers/upload'] as $path) {
            $this->assertTrue($as($manager, $path)->isOK(), "Admin should open {$path}");
            $this->assertFalse($as($staff, $path)->isOK(), "Staff must not open {$path}");
        }
    }
}

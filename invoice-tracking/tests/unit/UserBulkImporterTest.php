<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Models\UserModel;
use App\Services\AccessScope;
use App\Services\AdminAccess;
use App\Services\UserBulkImporter;
use RuntimeException;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class UserBulkImporterTest extends AppTestCase
{
    private UserBulkImporter $importer;
    private int $adminId;
    private int $acme;
    private int $beta;

    protected function setUp(): void
    {
        parent::setUp();
        $this->importer = new UserBulkImporter($this->db);
        $this->adminId  = $this->makeUser('Admin', true);
        $this->acme     = $this->makeCompany('ACME');
        $this->beta     = $this->makeCompany('BETA');
    }

    private function csv(string $body): string
    {
        $path = tempnam(sys_get_temp_dir(), 'bulk') . '.csv';
        file_put_contents($path, $body);

        return $path;
    }

    private function rows(string $body): array
    {
        return $this->importer->parse($this->csv($body));
    }

    private function userId(string $email): ?int
    {
        $u = model(UserModel::class)->findByCredentials(['email' => $email]);

        return $u?->id;
    }

    public function testValidFileCreatesUsersAndMemberships(): void
    {
        $rows = $this->rows(
            "email,display_name,company_codes,job_title,can_submit,can_approve,group_level,is_admin,active\n"
            . "jo@example.test,Jo Smith,ACME;BETA,Accountant,Y,,,\n"
            . "al@example.test,Al Jones,acme,,N,,,\n",
        );

        $result = $this->importer->commit($rows, $this->adminId);

        $this->assertSame(2, $result['created']);
        $jo = $this->userId('jo@example.test');
        $this->assertNotNull($jo);
        $scope = new AccessScope($this->db);
        $this->assertEqualsCanonicalizing([$this->acme, $this->beta], $scope->memberCompanyIds($jo));
        $this->assertSame([$this->acme], $scope->memberCompanyIds($this->userId('al@example.test')));
    }

    public function testFileWithAnyErrorWritesNothing(): void
    {
        $rows = $this->rows(
            "email,display_name,company_codes,can_submit\n"
            . "good@example.test,Good User,ACME,Y\n"
            . "bad@example.test,Bad User,NOPE,Y\n"
            . "not-an-email,Someone,ACME,Y\n"
            . "flag@example.test,Flag User,ACME,maybe\n",
        );

        $preview = $this->importer->validate($rows);
        $this->assertFalse($preview['ok']);
        $this->assertSame(3, $preview['counts']['error']);

        try {
            $this->importer->commit($rows, $this->adminId);
            $this->fail('Expected the commit to be refused.');
        } catch (RuntimeException) {
            $this->assertNull($this->userId('good@example.test'));
        }
    }

    public function testDuplicateEmailsInFileAreFlagged(): void
    {
        $rows    = $this->rows("email,display_name\na@example.test,A One\nA@example.test,A Two\n");
        $preview = $this->importer->validate($rows);

        $this->assertSame('error', $preview['rows'][1]['action']);
        $this->assertStringContainsString('Duplicate', $preview['rows'][1]['errors'][0]);
    }

    public function testReuploadIsIdempotentAndUpdates(): void
    {
        $first = "email,display_name,company_codes,can_submit\nre@example.test,Re Peat,ACME,N\n";
        $this->importer->commit($this->rows($first), $this->adminId);

        $second  = "email,display_name,company_codes,can_submit\nre@example.test,Re Peated,BETA,Y\n";
        $preview = $this->importer->validate($this->rows($second));
        $this->assertSame('update', $preview['rows'][0]['action']);

        $result = $this->importer->commit($this->rows($second), $this->adminId);
        $this->assertSame(0, $result['created']);
        $this->assertSame(1, $result['updated']);

        $id = $this->userId('re@example.test');
        $scope = new AccessScope($this->db);
        // Additive by default: both companies now.
        $this->assertEqualsCanonicalizing([$this->acme, $this->beta], $scope->memberCompanyIds($id));
        $this->assertSame('Re Peated', $this->db->table('users')->where('id', $id)->get()->getRow()->display_name);
        $this->assertSame(1, (int) $this->db->table('user_companies')->where(['user_id' => $id, 'company_id' => $this->beta])->get()->getRow()->can_submit);
    }

    public function testReplaceModeReplacesMemberships(): void
    {
        $this->importer->commit($this->rows("email,display_name,company_codes\nrp@example.test,Rep Lace,ACME\n"), $this->adminId);
        $this->importer->commit($this->rows("email,display_name,company_codes\nrp@example.test,Rep Lace,BETA\n"), $this->adminId, true);

        $scope = new AccessScope($this->db);
        $this->assertSame([$this->beta], $scope->memberCompanyIds($this->userId('rp@example.test')));
    }

    public function testGroupLevelAndAdminColumnsApplyForAdminActor(): void
    {
        $rows = $this->rows("email,display_name,group_level,is_admin\ngl@example.test,Group Lead,Y,Y\n");
        $this->importer->commit($rows, $this->adminId);

        $id = $this->userId('gl@example.test');
        $this->assertTrue((new AccessScope($this->db))->isGroupLevel($id));
        $this->assertTrue((new AdminAccess($this->db))->isAdmin($id));
    }

    public function testNonAdminActorCannotImport(): void
    {
        $plain = $this->makeUser('Plain');
        $this->expectException(RuntimeException::class);
        $this->importer->commit($this->rows("email,display_name\nx@example.test,X\n"), $plain);
    }

    public function testMissingEmailColumnIsRejected(): void
    {
        $this->expectException(RuntimeException::class);
        $this->rows("name,company\nA,B\n");
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\AdminAccess;
use App\Services\LogoStore;
use CodeIgniter\Exceptions\PageNotFoundException;
use CodeIgniter\Shield\Entities\User;
use Config\Services;
use Tests\Support\FakeMailTransport;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class LogoScreensTest extends FeatureTestCase
{
    private int $acme;
    private int $beta;
    private User $acmeUser;
    private User $gary;
    private User $nobody;

    /** @var list<string> */
    private array $logos = [];

    protected function setUp(): void
    {
        parent::setUp();
        Services::injectMock('mailer', new FakeMailTransport());

        $admin          = $this->shieldUser('admin@example.test', 'Admin', true);
        $this->acmeUser = $this->shieldUser('acme@example.test', 'Acme Member');
        $this->gary     = $this->shieldUser('gary@example.test', 'Gary Group');
        $this->nobody   = $this->shieldUser('nobody@example.test', 'No Companies');

        $this->acme = $this->makeCompany('ACME');
        $this->beta = $this->makeCompany('BETA');
        $this->join((int) $this->acmeUser->id, $this->acme, true);
        (new AdminAccess($this->db))->grantGroupLevel((int) $admin->id, (int) $this->gary->id);
    }

    protected function tearDown(): void
    {
        foreach ($this->logos as $name) {
            (new LogoStore())->delete($name);
        }
        setting()->set('AppSettings.groupLogoFile', '');
        setting()->set('AppSettings.groupLogoMime', '');
        Services::resetSingle('mailer');
        parent::tearDown();
    }

    /** Puts a real PNG in the logo folder and returns its stored name. */
    private function logoFile(): string
    {
        $dir = LogoStore::directory();
        if (! is_dir($dir)) {
            mkdir($dir, 0750, true);
        }
        $name = bin2hex(random_bytes(16)) . '.png';
        file_put_contents($dir . '/' . $name, self::pngBytes());

        return $this->logos[] = $name;
    }

    /** The test harness raises the 404 instead of returning it. */
    private function assertNotFound(callable $request): void
    {
        try {
            $request()->assertStatus(404);
        } catch (PageNotFoundException) {
            $this->addToAssertionCount(1);
        }
    }

    private function companyLogo(int $company): string
    {
        $name = $this->logoFile();
        $this->db->table('companies')->where('id', $company)->update(['logo_file' => $name, 'logo_mime' => 'image/png']);

        return $name;
    }

    private function groupLogo(): void
    {
        setting()->set('AppSettings.groupLogoFile', $this->logoFile());
        setting()->set('AppSettings.groupLogoMime', 'image/png');
    }

    public function testCompanyLogoIsServedOnlyToPeopleWhoCanSeeTheCompany(): void
    {
        $this->companyLogo($this->acme);
        $this->companyLogo($this->beta);

        $ok = $this->actingAs($this->acmeUser)->get('logo/company/' . $this->acme);
        $ok->assertStatus(200);
        $this->assertSame('image/png', $ok->response()->getHeaderLine('Content-Type'));
        $this->assertSame('nosniff', $ok->response()->getHeaderLine('X-Content-Type-Options'));

        $this->assertNotFound(fn () => $this->actingAs($this->acmeUser)->get('logo/company/' . $this->beta));
        $this->assertNotFound(fn () => $this->actingAs($this->nobody)->get('logo/company/' . $this->acme));
        $this->actingAs($this->gary)->get('logo/company/' . $this->beta)->assertStatus(200);
    }

    public function testCompanyWithoutALogoIs404(): void
    {
        $this->assertNotFound(fn () => $this->actingAs($this->acmeUser)->get('logo/company/' . $this->acme));
    }

    public function testGroupLogoIsServedToAnyoneSignedIn(): void
    {
        $this->assertNotFound(fn () => $this->actingAs($this->nobody)->get('logo/group'));

        $this->groupLogo();
        $this->actingAs($this->nobody)->get('logo/group')->assertStatus(200);
    }

    public function testDashboardShowsTheSelectedCompanyLogoOrTheGroupLogo(): void
    {
        $this->companyLogo($this->acme);
        $this->groupLogo();

        // A group-level user: all companies shows the group logo, choosing a company shows its own.
        $body = (string) $this->actingAs($this->gary)->get('/')->getBody();
        $this->assertStringContainsString('logo/group', $body);
        $this->assertStringNotContainsString('logo/company/', $body);

        session();
        $this->withSession(['active_company' => $this->acme]);
        $body = (string) $this->actingAs($this->gary)->get('/')->getBody();
        $this->assertStringContainsString('logo/company/' . $this->acme, $body);
        $this->assertStringNotContainsString('logo/group', $body);

        // Company chosen but it has no logo of its own: fall back to the group logo.
        session();
        $this->withSession(['active_company' => $this->beta]);
        $body = (string) $this->actingAs($this->gary)->get('/')->getBody();
        $this->assertStringContainsString('logo/group', $body);
    }

    public function testDashboardWithNoLogosShowsNoTileAndHasTheActionRow(): void
    {
        $body = (string) $this->actingAs($this->acmeUser)->get('/')->getBody();

        $this->assertStringNotContainsString('hero-logo', $body);
        $this->assertStringContainsString('dashboard-actions', $body);
        $this->assertStringContainsString('New invoice', $body);
        $this->assertStringContainsString('Reports', $body);

        $viewer = (string) $this->actingAs($this->gary)->get('/')->getBody();  // group-level but no submit rights
        $this->assertStringContainsString('dashboard-actions', $viewer);
        $this->assertStringNotContainsString('btn-lg" href="' . site_url('invoices/new'), $viewer);
    }

    public function testASingleCompanyUserSeesTheirCompanyLogo(): void
    {
        $this->companyLogo($this->acme);
        $this->groupLogo();

        $body = (string) $this->actingAs($this->acmeUser)->get('/')->getBody();

        $this->assertStringContainsString('logo/company/' . $this->acme, $body);
    }
}

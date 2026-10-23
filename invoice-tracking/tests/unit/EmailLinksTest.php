<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\InvitationService;
use App\Services\NotificationService;
use CodeIgniter\Shield\Entities\User;
use Config\Services;
use Tests\Support\AppTestCase;
use Tests\Support\FakeMailTransport;

/**
 * Links in emails must be real, clickable addresses. (Escaping a whole URL as if it were a query-string
 * value turns "https://host/x?y=1" into "https%3A%2F%2Fhost%2Fx%3Fy%3D1", which no mail client can open.)
 *
 * @internal
 */
final class EmailLinksTest extends AppTestCase
{
    private FakeMailTransport $mail;

    protected function setUp(): void
    {
        parent::setUp();
        $this->mail = new FakeMailTransport();
        Services::injectMock('mailer', $this->mail);
    }

    protected function tearDown(): void
    {
        Services::resetSingle('mailer');
        parent::tearDown();
    }

    /** @return list<string> every href in the html */
    private function hrefs(string $html): array
    {
        preg_match_all('/href="([^"]*)"/', $html, $m);

        return array_map('html_entity_decode', $m[1]);
    }

    public function testNotificationEmailLinksToTheInvoice(): void
    {
        $co  = $this->makeCompany('ACME');
        $by  = $this->makeUser('Sue');
        $to  = $this->makeUserWithEmail('Alan', 'alan@example.test');
        $inv = $this->makeInvoice($co, $by);

        (new NotificationService($this->db))->dispatch('invoice.submitted', $inv, [$to], ['actor_id' => 0, 'actor_name' => 'Sue']);

        $links = $this->hrefs($this->mail->to('alan@example.test')[0]->html);
        $this->assertContains(site_url('invoices/' . $inv), $links);
        foreach ($links as $l) {
            $this->assertStringNotContainsString('%3A%2F%2F', $l);
        }
    }

    public function testDigestEmailLinksAreIntact(): void
    {
        $to = $this->makeUserWithEmail('Alan', 'alan@example.test');

        (new NotificationService($this->db))->dispatchDigest($to, 'Daily summary', [
            ['heading' => 'Waiting', 'items' => [['text' => '#A1 · ACME', 'url' => site_url('invoices/7?x=1&y=2')]]],
        ]);

        $links = $this->hrefs($this->mail->to('alan@example.test')[0]->html);
        $this->assertContains(site_url('invoices/7?x=1&y=2'), $links);
        $this->assertContains(site_url('approvals'), $links);
    }

    public function testInvitationEmailKeepsTheSignInTokenIntact(): void
    {
        $link = site_url('login/verify-magic-link') . '?token=AbCdEf123456&x=1';

        $html = view('emails/invitation', ['name' => 'New Hire', 'link' => $link, 'expires' => 3]);

        $this->assertContains($link, $this->hrefs($html));
        $this->assertStringContainsString('3 days', $html);
    }

    public function testRealInvitationLinkIsClickable(): void
    {
        $id = $this->makeUserWithEmail('Invitee', 'invitee@example.test');
        $this->db->table('auth_identities')->where('user_id', $id)->update(['type' => 'email_password']);
        $user = model(\App\Models\UserModel::class)->findById($id);
        $this->assertInstanceOf(User::class, $user);

        $link = (new InvitationService())->createLink($user);

        $this->assertStringStartsWith('http', $link);
        $this->assertStringContainsString('?token=', $link);
        $this->assertContains($link, $this->hrefs(view('emails/invitation', ['name' => 'Invitee', 'link' => $link, 'expires' => 3])));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Feature;

use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class RoutePayStepScreensTest extends FeatureTestCase
{
    private \CodeIgniter\Shield\Entities\User $admin;
    private int $routeId;
    private int $alan;
    private int $pat;
    private int $nobody;

    protected function setUp(): void
    {
        parent::setUp();
        $this->admin = $this->shieldUser('boss@example.test', 'The Admin', true);

        $company      = $this->makeCompany('ACME');
        $this->alan   = $this->makeUserWithEmail('Alan', 'alan@example.test');
        $this->pat    = $this->makeUserWithEmail('Pat', 'pat@example.test');
        $this->nobody = $this->makeUserWithEmail('Nobody', 'nobody@example.test');
        $this->join($this->alan, $company);
        $this->join($this->pat, $company);
        $this->db->table('user_companies')->insert(['user_id' => $this->nobody, 'company_id' => $company, 'can_submit' => 0, 'can_approve' => 0, 'created_at' => date('Y-m-d H:i:s')]);

        $this->routeId = $this->makeRoute($company, [['type' => 'approve', 'user' => $this->alan]], 'Standard', 'default', false)['route'];
    }

    /** @param list<array{0: string, 1: int}> $steps type and user id */
    private function save(array $steps): void
    {
        $post = ['active' => '1', 'variant_trigger' => 'none', 'steps' => []];
        foreach ($steps as $i => [$type, $user]) {
            $post['steps']['s' . $i] = ['step_type' => $type, 'job_title' => 'X', 'assignee_kind' => 'user', 'user_id' => (string) $user];
        }
        $this->actingAs($this->admin)->post('admin/routes/' . $this->routeId, $post);
    }

    /** @return list<string> */
    private function types(): array
    {
        return array_column($this->db->table('route_steps')->where('route_id', $this->routeId)->orderBy('step_order')->get()->getResultArray(), 'step_type');
    }

    public function testAPayStepAfterTheProcessStepIsSaved(): void
    {
        $this->save([['approve', $this->alan], ['process', $this->pat], ['pay', $this->pat], ['notify', $this->alan]]);

        $this->assertSame(['approve', 'process', 'pay', 'notify'], $this->types());
    }

    public function testOnlyOnePayStepAndItMustComeAfterEveryApprovalAndProcessStep(): void
    {
        $this->save([['approve', $this->alan], ['pay', $this->pat], ['pay', $this->pat]]);
        $this->assertSame(['approve'], $this->types(), 'two pay steps are refused');
        $this->assertStringContainsString('only one pay step', (string) session()->getFlashdata('error'));

        $this->save([['pay', $this->pat], ['process', $this->pat]]);
        $this->assertSame(['approve'], $this->types(), 'a process step after the pay step is refused');
        $this->assertStringContainsString('must come after', (string) session()->getFlashdata('error'));
    }

    public function testThePayeeMustBeAllowedToApprove(): void
    {
        $this->save([['approve', $this->alan], ['pay', $this->nobody]]);

        $this->assertSame(['approve'], $this->types());
        $this->assertStringContainsString('not allowed to approve', (string) session()->getFlashdata('error'));
    }
}

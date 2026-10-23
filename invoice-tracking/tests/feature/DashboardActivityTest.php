<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\WorkflowEngine;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class DashboardActivityTest extends FeatureTestCase
{
    public function testRecentActivityShowsFiveAndExpandsTheRest(): void
    {
        $sue     = $this->shieldUser('sue@example.test', 'Sue Submitter');
        $company = $this->makeCompany('ACME');
        $this->join((int) $sue->id, $company, true);
        $id = $this->makeInvoice($company, (int) $sue->id);
        $engine = new WorkflowEngine($this->db);
        for ($i = 0; $i < 7; $i++) {
            $engine->addNote($id, (int) $sue->id, 'note ' . $i);
        }

        $body = $this->actingAs($sue)->get('/')->getBody();

        $this->assertStringContainsString('View more (2)', $body);
        $this->assertSame(7, substr_count($body, 'Commented on'));
    }

    public function testNoViewMoreWhenFiveOrFewer(): void
    {
        $sue     = $this->shieldUser('sue@example.test', 'Sue Submitter');
        $company = $this->makeCompany('ACME');
        $this->join((int) $sue->id, $company, true);
        $id = $this->makeInvoice($company, (int) $sue->id);
        (new WorkflowEngine($this->db))->addNote($id, (int) $sue->id, 'hello');

        $this->assertStringNotContainsString('View more', $this->actingAs($sue)->get('/')->getBody());
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Controllers\Api\V1\Spec;
use CodeIgniter\Test\CIUnitTestCase as Base;

/**
 * Keeps the hand-written OpenAPI description honest: valid, and in step with the real routes.
 *
 * @internal
 */
final class OpenApiSpecTest extends Base
{
    /** @return array<string, mixed> */
    private function spec(): array
    {
        $spec = json_decode((string) file_get_contents(Spec::FILE), true);
        $this->assertIsArray($spec, 'app/Docs/openapi.json must be valid JSON');

        return $spec;
    }

    /** @return array<string, string> "/path/{id}" => handler, for every GET route under api/v1 */
    private function routes(): array
    {
        $out = [];
        $routes = \Config\Services::routes(true)->loadRoutes();
        foreach ($routes->getRoutes('GET') as $path => $handler) {
            // Skip anything outside the API and the catch-all that answers unknown paths with a JSON 404/405.
            if (! str_starts_with($path, 'api/v1/') || $path === 'api/v1/(.*)') {
                continue;
            }
            $templated = str_replace('([0-9]+)', '{id}', substr($path, strlen('api/v1')));
            $out[(string) $templated] = is_string($handler) ? $handler : 'closure';
        }

        return $out;
    }

    public function testItIsAnOpenApi31DocumentWithTheBasics(): void
    {
        $spec = $this->spec();

        $this->assertStringStartsWith('3.1', $spec['openapi']);
        $this->assertNotSame('', $spec['info']['title']);
        $this->assertArrayHasKey('bearerAuth', $spec['components']['securitySchemes']);
        $this->assertSame([['bearerAuth' => []]], $spec['security']);
    }

    public function testEveryRealRouteIsDocumentedAndNothingElse(): void
    {
        // Routes are compared by shape: every placeholder counts as {id} (a path with two ids names them {id} and {docId}).
        $documented = array_map(static fn (string $p): string => (string) preg_replace('/\{\w+\}/', '{id}', $p), array_keys($this->spec()['paths']));
        $real       = array_keys($this->routes());

        sort($documented);
        sort($real);
        $this->assertSame($real, $documented, 'Routes under api/v1 and paths in app/Docs/openapi.json must match.');
    }

    public function testOnlyGetIsDocumentedAndEveryOperationIsComplete(): void
    {
        $ids = [];
        foreach ($this->spec()['paths'] as $path => $methods) {
            $this->assertSame(['get'], array_keys($methods), "{$path}: the API is read-only");
            $op = $methods['get'];
            $this->assertNotEmpty($op['operationId'] ?? null, "{$path}: operationId");
            $this->assertNotContains($op['operationId'], $ids, "{$path}: operationId must be unique");
            $ids[] = $op['operationId'];
            $this->assertNotEmpty($op['summary'] ?? null, "{$path}: summary");
            $this->assertArrayHasKey('200', $op['responses'], "{$path}: 200 response");
            if ($path !== '/openapi.json') {
                $this->assertArrayHasKey('401', $op['responses'], "{$path}: 401 response");
            }
        }
    }

    public function testEveryReferenceResolves(): void
    {
        $spec = $this->spec();
        $json = json_encode($spec, JSON_UNESCAPED_SLASHES);
        preg_match_all('/"\$ref":"#\/components\/(\w+)\/(\w+)"/', (string) $json, $m, PREG_SET_ORDER);

        $this->assertNotEmpty($m);
        foreach ($m as [, $section, $name]) {
            $this->assertArrayHasKey($name, $spec['components'][$section] ?? [], "dangling \$ref to components/{$section}/{$name}");
        }
    }

    public function testEverySchemaPropertyTheApiEmitsIsDocumented(): void
    {
        // The presenter's output keys must appear in the description so clients are not surprised.
        $invoice = $this->spec()['components']['schemas']['Invoice']['properties'];
        $row     = [
            'id' => 1, 'invoice_number' => 'A', 'supplier' => 'S', 'company_id' => 1, 'company_code' => 'X', 'type_name' => 'T',
            'invoice_date' => null, 'due_date' => null, 'amount' => '1', 'currency' => 'USD', 'po_reference' => null, 'status' => 'draft',
            'extra_approval' => 0, 'submitter' => 'N', 'submitted_at' => null, 'completed_at' => null, 'payment_reference' => null, 'payment_date' => null,
        ];

        $emitted = array_keys(\App\Services\ApiPresenter::invoice($row));

        sort($emitted);
        $documented = array_keys($invoice);
        sort($documented);
        $this->assertSame($documented, $emitted);
    }
}

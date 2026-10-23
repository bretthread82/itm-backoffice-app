<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\SharePoint\GraphClient;
use App\Services\SharePoint\SharePointException;
use CodeIgniter\Test\CIUnitTestCase;
use GuzzleHttp\Client;
use GuzzleHttp\Handler\MockHandler;
use GuzzleHttp\HandlerStack;
use GuzzleHttp\Promise\Create;
use GuzzleHttp\Psr7\Response;
use Psr\Http\Message\RequestInterface;

/**
 * The real Graph code with SharePoint replaced by an in-memory fake that records every request.
 *
 * @internal
 */
final class GraphClientTest extends CIUnitTestCase
{
    /** @var list<array{method: string, url: string, auth: string, headers: array<string, string>, body: string}> */
    private array $log = [];

    /** @var array<string, callable(RequestInterface): Response> per-test overrides keyed by "METHOD substring" */
    private array $overrides = [];

    /** @var list<string> */
    private array $columns = ['Title', 'Supplier', 'Amount', 'FileLink'];

    /** @var list<array{id: string, name: string}> */
    private array $drives = [['id' => 'drive-1', 'name' => 'Documents']];

    private array $savedFiles = [];

    protected function tearDown(): void
    {
        foreach ($this->savedFiles as $f) {
            @unlink($f);
        }
        parent::tearDown();
    }

    // -------------------------------------------------------------- fake SharePoint

    private function http(): Client
    {
        $handler = function (RequestInterface $r) {
            $url  = (string) $r->getUri();
            $this->log[] = [
                'method'  => $r->getMethod(), 'url' => $url, 'auth' => $r->getHeaderLine('Authorization'),
                'headers' => array_map(static fn (array $v): string => implode(',', $v), $r->getHeaders()), 'body' => (string) $r->getBody(),
            ];

            foreach ($this->overrides as $key => $fn) {
                [$method, $needle] = explode(' ', $key, 2);
                if ($r->getMethod() === $method && str_contains($url, $needle)) {
                    return Create::promiseFor($fn($r));
                }
            }

            return Create::promiseFor($this->route($r, $url));
        };

        return new Client(['handler' => HandlerStack::create($handler)]);
    }

    private function json(array $data, int $status = 200): Response
    {
        return new Response($status, ['Content-Type' => 'application/json'], (string) json_encode($data));
    }

    private function route(RequestInterface $r, string $url): Response
    {
        $m = $r->getMethod();

        if (str_contains($url, '/oauth2/v2.0/token')) {
            return $this->json(['access_token' => 'tok-' . count($this->log), 'expires_in' => 3600]);
        }
        if ($m === 'GET' && str_contains($url, '/sites/contoso.sharepoint.com:')) {
            return $this->json(['id' => 'contoso.sharepoint.com,guid1,guid2', 'displayName' => 'Invoice Approval']);
        }
        if ($m === 'GET' && str_contains($url, '/drives?')) {
            return $this->json(['value' => $this->drives]);
        }
        if ($m === 'GET' && str_contains($url, '/columns?')) {
            return $this->json(['value' => array_map(static fn (string $n): array => ['name' => $n], $this->columns)]);
        }
        if ($m === 'GET' && str_contains($url, '/lists?')) {
            return $this->json(['value' => [['id' => 'list-1', 'displayName' => 'Completed Invoices']]]);
        }
        if ($m === 'PUT' && str_contains($url, ':/content')) {
            return $this->json(['id' => 'file-77', 'webUrl' => 'https://contoso.sharepoint.com/sites/Invoices/Shared%20Documents/file.pdf']);
        }
        if ($m === 'POST' && str_contains($url, '/createUploadSession')) {
            return $this->json(['uploadUrl' => 'https://upload.sharepoint.test/session/abc']);
        }
        if ($m === 'PUT' && str_contains($url, 'upload.sharepoint.test')) {
            preg_match('#bytes (\d+)-(\d+)/(\d+)#', $r->getHeaderLine('Content-Range'), $b);

            return (int) $b[2] + 1 >= (int) $b[3]
                ? $this->json(['id' => 'file-big', 'webUrl' => 'https://contoso.sharepoint.com/sites/Invoices/big.pdf'], 201)
                : $this->json(['nextExpectedRanges' => [($b[2] + 1) . '-']], 202);
        }
        if ($m === 'POST' && str_contains($url, '/items')) {
            return $this->json(['id' => '42', 'webUrl' => 'https://contoso.sharepoint.com/sites/Invoices/Lists/Completed/42'], 201);
        }

        return $this->json(['error' => ['code' => 'itemNotFound', 'message' => 'Unexpected test request ' . $m . ' ' . $url]], 404);
    }

    /** @param array<string, mixed> $over */
    private function conn(array $over = []): array
    {
        return $over + [
            'id' => 1, 'name' => 'Main', 'tenant' => '11111111-2222-3333-4444-555555555555', 'client_id' => 'client-1', 'secret' => 'the-secret',
            'site_url' => 'https://contoso.sharepoint.com/sites/Invoices', 'library' => 'Documents', 'list' => 'Completed Invoices',
            'folder' => 'Invoices', 'mapping' => ['Title' => 'invoice_number', 'Supplier' => 'supplier', 'Amount' => 'amount', 'FileLink' => 'file_url'],
        ];
    }

    /** @return array<string, mixed> */
    private function payload(): array
    {
        return ['invoice_id' => 5, 'invoice_number' => 'INV-9', 'supplier' => "O'Brien & Sons", 'amount' => '1250.50', 'currency' => 'USD', 'invoice_date' => '2026-09-01', 'company_code' => 'ACME', 'po_reference' => ''];
    }

    private function pdf(int $bytes = 200): string
    {
        $p = tempnam(sys_get_temp_dir(), 'gc') . '.pdf';
        file_put_contents($p, '%PDF-1.4' . str_repeat('x', max(0, $bytes - 8)));
        $this->savedFiles[] = $p;

        return $p;
    }

    /** @return list<array{method: string, url: string}> */
    private function calls(string $method, string $needle): array
    {
        return array_values(array_filter($this->log, static fn (array $c): bool => $c['method'] === $method && str_contains($c['url'], $needle)));
    }

    // -------------------------------------------------------------- test()

    public function testTestReportsSiteLibraryAndList(): void
    {
        $r = (new GraphClient($this->http()))->test($this->conn());

        $this->assertSame(['site' => 'Invoice Approval', 'library' => 'Documents', 'list' => 'Completed Invoices'], $r);
    }

    public function testTestChangesNothingInSharePoint(): void
    {
        (new GraphClient($this->http()))->test($this->conn());

        foreach ($this->log as $c) {
            $this->assertTrue($c['method'] === 'GET' || str_contains($c['url'], '/oauth2/v2.0/token'), $c['method'] . ' ' . $c['url']);
        }
    }

    public function testTestWithoutAListChecksOnlyTheLibrary(): void
    {
        $r = (new GraphClient($this->http()))->test($this->conn(['list' => '', 'mapping' => []]));

        $this->assertNull($r['list']);
        $this->assertSame([], $this->calls('GET', '/lists?'));
    }

    public function testMissingColumnsAreNamed(): void
    {
        $this->columns = ['Title'];

        $this->expectException(SharePointException::class);
        $this->expectExceptionMessage('Supplier, Amount, FileLink');
        (new GraphClient($this->http()))->test($this->conn());
    }

    public function testUnknownLibraryListsWhatExists(): void
    {
        $this->drives = [['id' => 'd1', 'name' => 'Documents'], ['id' => 'd2', 'name' => 'Archive']];

        try {
            (new GraphClient($this->http()))->test($this->conn(['library' => 'Invoices Library']));
            $this->fail('Expected an exception.');
        } catch (SharePointException $e) {
            $this->assertStringContainsString('"Invoices Library" was not found', $e->getMessage());
            $this->assertStringContainsString('"Documents", "Archive"', $e->getMessage());
        }
    }

    public function testLibraryNameIsMatchedIgnoringCase(): void
    {
        $this->assertSame('Documents', (new GraphClient($this->http()))->test($this->conn(['library' => 'documents']))['library']);
    }

    public function testAQuoteInTheListNameIsEscapedInTheFilter(): void
    {
        (new GraphClient($this->http()))->test($this->conn(['list' => "Bob's Invoices", 'mapping' => []]));

        $url = $this->calls('GET', '/lists?')[0]['url'];
        $this->assertStringContainsString(rawurlencode("displayName eq 'Bob''s Invoices'"), $url);
    }

    // -------------------------------------------------------------- authentication

    public function testRequestsUseAClientCredentialsTokenForTheRightTenantAndScope(): void
    {
        (new GraphClient($this->http()))->test($this->conn());

        $token = $this->calls('POST', '/oauth2/v2.0/token');
        $this->assertCount(1, $token);                                        // fetched once and reused
        $this->assertStringContainsString('/11111111-2222-3333-4444-555555555555/', $token[0]['url']);
        parse_str($token[0]['body'], $form);
        $this->assertSame('client_credentials', $form['grant_type']);
        $this->assertSame('https://graph.microsoft.com/.default', $form['scope']);
        $this->assertSame('client-1', $form['client_id']);
        $this->assertSame('the-secret', $form['client_secret']);

        foreach ($this->log as $c) {
            if (str_contains($c['url'], 'graph.microsoft.com')) {
                $this->assertStringStartsWith('Bearer tok-', $c['auth']);
            }
        }
    }

    public function testATokenFailureExplainsAndNeverEchoesTheSecret(): void
    {
        $this->overrides['POST /oauth2/v2.0/token'] = fn () => $this->json(['error' => 'invalid_client', 'error_description' => "AADSTS7000215: Invalid client secret provided.\r\nTrace ID: abc"], 401);

        try {
            (new GraphClient($this->http()))->test($this->conn());
            $this->fail('Expected an exception.');
        } catch (SharePointException $e) {
            $this->assertStringContainsString('Invalid client secret', $e->getMessage());
            $this->assertStringNotContainsString('the-secret', $e->getMessage());
            $this->assertStringNotContainsString('Trace ID', $e->getMessage());
        }
    }

    public function testAMissingSecretIsReportedWithoutCallingMicrosoft(): void
    {
        $this->expectException(SharePointException::class);
        $this->expectExceptionMessage('client secret is missing');

        try {
            (new GraphClient($this->http()))->test($this->conn(['secret' => '']));
        } finally {
            $this->assertSame([], $this->log);
        }
    }

    public function testForbiddenExplainsTheSitesSelectedGrant(): void
    {
        $this->overrides['GET /sites/contoso'] = fn () => $this->json(['error' => ['code' => 'accessDenied', 'message' => 'Access denied']], 403);

        try {
            (new GraphClient($this->http()))->test($this->conn());
            $this->fail('Expected an exception.');
        } catch (SharePointException $e) {
            $this->assertStringContainsString('HTTP 403', $e->getMessage());
            $this->assertStringContainsString('Sites.Selected', $e->getMessage());
        }
    }

    public function testNetworkFailureIsAnExceptionNotACrash(): void
    {
        $client = new Client(['handler' => HandlerStack::create(new MockHandler([new \GuzzleHttp\Exception\ConnectException('offline', new \GuzzleHttp\Psr7\Request('POST', 'https://login.microsoftonline.com'))]))]);

        $this->expectException(SharePointException::class);
        (new GraphClient($client))->test($this->conn());
    }

    public function testTheSiteUrlMustBeHttps(): void
    {
        $this->expectException(SharePointException::class);
        (new GraphClient($this->http()))->test($this->conn(['site_url' => 'http://contoso.sharepoint.com/sites/Invoices']));
    }

    // -------------------------------------------------------------- store()

    public function testStoreUploadsThePdfThenCreatesTheListItem(): void
    {
        $progress = [];
        $result   = (new GraphClient($this->http()))->store(
            $this->conn(), $this->payload(), $this->pdf(), 'INV-9_Supplier_5.pdf', null, null,
            static function (string $stage, string $value) use (&$progress): void {
                $progress[$stage] = $value;
            },
        );

        $this->assertSame('https://contoso.sharepoint.com/sites/Invoices/Shared%20Documents/file.pdf', $result->fileUrl);
        $this->assertSame('42', $result->itemId);
        $this->assertSame(['file' => $result->fileUrl, 'item' => '42'], $progress);

        $put = $this->calls('PUT', ':/content');
        $this->assertCount(1, $put);
        $this->assertStringContainsString('/drives/drive-1/root:/Invoices/ACME/2026/INV-9_Supplier_5.pdf:/content', $put[0]['url']);
        $this->assertStringContainsString('conflictBehavior=replace', $put[0]['url']);   // a retry replaces, never duplicates
        $this->assertSame('application/pdf', $put[0]['headers']['Content-Type']);
        $this->assertStringStartsWith('%PDF', $put[0]['body']);
    }

    public function testTheListItemCarriesTheMappedFieldsWithTheRightTypes(): void
    {
        (new GraphClient($this->http()))->store($this->conn(), $this->payload(), $this->pdf(), 'f.pdf', null, null, static function (): void {
        });

        $item = $this->calls('POST', '/items');
        $this->assertCount(1, $item);
        $this->assertStringContainsString('/sites/contoso.sharepoint.com%2Cguid1%2Cguid2/lists/list-1/items', $item[0]['url']);
        $body = json_decode($item[0]['body'], true);

        $this->assertSame("O'Brien & Sons", $body['fields']['Supplier']);
        $this->assertSame('INV-9', $body['fields']['Title']);
        $this->assertSame(1250.5, $body['fields']['Amount']);                      // a number, not text
        $this->assertSame('https://contoso.sharepoint.com/sites/Invoices/Shared%20Documents/file.pdf', $body['fields']['FileLink']);
        $this->assertCount(4, $body['fields']);
    }

    public function testEmptyValuesAreNotSent(): void
    {
        (new GraphClient($this->http()))->store(
            $this->conn(['mapping' => ['Title' => 'invoice_number', 'Supplier' => 'po_reference']]),
            $this->payload(), $this->pdf(), 'f.pdf', null, null, static function (): void {
            },
        );

        $body = json_decode($this->calls('POST', '/items')[0]['body'], true);
        $this->assertSame(['Title' => 'INV-9'], $body['fields']);
    }

    public function testFolderAndFileNamesAreEncoded(): void
    {
        (new GraphClient($this->http()))->store(
            $this->conn(['folder' => 'Finance Invoices/Completed']), ['company_code' => 'A&B Ltd', 'invoice_date' => '2025-12-31'] + $this->payload(),
            $this->pdf(), 'My invoice #1.pdf', null, null, static function (): void {
            },
        );

        $url = $this->calls('PUT', ':/content')[0]['url'];
        $this->assertStringContainsString('root:/Finance%20Invoices/Completed/A_B_Ltd/2025/My%20invoice%20%231.pdf:/content', $url);
    }

    public function testALargePdfUsesAnUploadSessionInChunksWithoutOurToken(): void
    {
        $size = GraphClient::CHUNK * 2 + 1000;                 // three chunks
        $this->assertGreaterThan(GraphClient::SIMPLE_UPLOAD_MAX, $size);

        $result = (new GraphClient($this->http()))->store($this->conn(['list' => '', 'mapping' => []]), $this->payload(), $this->pdf($size), 'big.pdf', null, null, static function (): void {
        });

        $this->assertSame('https://contoso.sharepoint.com/sites/Invoices/big.pdf', $result->fileUrl);
        $this->assertCount(1, $this->calls('POST', '/createUploadSession'));
        $this->assertSame([], $this->calls('PUT', ':/content'));

        $chunks = $this->calls('PUT', 'upload.sharepoint.test');
        $this->assertCount(3, $chunks);
        $this->assertSame('bytes 0-' . (GraphClient::CHUNK - 1) . '/' . $size, $this->log[array_search($chunks[0], $this->log, true)]['headers']['Content-Range']);
        foreach ($chunks as $c) {
            $this->assertSame('', $c['auth'], 'The pre-authorised upload URL must not receive our access token.');
        }
        $this->assertSame($size, array_sum(array_map(static fn (array $c): int => strlen($c['body']), $chunks)));
    }

    // -------------------------------------------------------------- retries resume instead of repeating

    public function testAnAlreadyStoredFileIsNotUploadedAgain(): void
    {
        $result = (new GraphClient($this->http()))->store($this->conn(), $this->payload(), $this->pdf(), 'f.pdf', 'https://already.example/f.pdf', null, static function (): void {
        });

        $this->assertSame([], $this->calls('PUT', ':/content'));
        $this->assertSame([], $this->calls('GET', '/drives?'));
        $this->assertSame('https://already.example/f.pdf', $result->fileUrl);
        $body = json_decode($this->calls('POST', '/items')[0]['body'], true);
        $this->assertSame('https://already.example/f.pdf', $body['fields']['FileLink']);
    }

    public function testAnAlreadyCreatedItemIsNotCreatedAgain(): void
    {
        $result = (new GraphClient($this->http()))->store($this->conn(), $this->payload(), $this->pdf(), 'f.pdf', 'https://already.example/f.pdf', '42', static function (): void {
        });

        $this->assertSame([], $this->calls('POST', '/items'));
        $this->assertSame('42', $result->itemId);
    }

    public function testProgressIsReportedEvenWhenTheItemStepFails(): void
    {
        $this->overrides['POST /items'] = fn () => $this->json(['error' => ['code' => 'invalidRequest', 'message' => 'Field "Supplier" is not recognized']], 400);
        $progress                       = [];

        try {
            (new GraphClient($this->http()))->store($this->conn(), $this->payload(), $this->pdf(), 'f.pdf', null, null, static function (string $s, string $v) use (&$progress): void {
                $progress[$s] = $v;
            });
            $this->fail('Expected an exception.');
        } catch (SharePointException $e) {
            $this->assertStringContainsString('Field "Supplier" is not recognized', $e->getMessage());
        }

        $this->assertArrayHasKey('file', $progress);       // the upload that worked is not lost
        $this->assertArrayNotHasKey('item', $progress);
    }

    public function testWithoutAListTheFileIsTheWholeJob(): void
    {
        $progress = [];
        $result   = (new GraphClient($this->http()))->store($this->conn(['list' => '', 'mapping' => []]), $this->payload(), $this->pdf(), 'f.pdf', null, null, static function (string $s, string $v) use (&$progress): void {
            $progress[$s] = $v;
        });

        $this->assertSame('file:file-77', $result->itemId);
        $this->assertSame('file:file-77', $progress['item']);
        $this->assertSame([], $this->calls('POST', '/items'));
    }
}

<?php

declare(strict_types=1);

namespace Tests\Unit;

use App\Services\FilePurger;
use App\Services\InvoiceFiles;
use Tests\Support\AppTestCase;

/**
 * @internal
 */
final class FilePurgerTest extends AppTestCase
{
    private int $user;
    private int $company;
    private FilePurger $purger;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user    = $this->makeUserWithEmail('Sue', 'sue@example.test');
        $this->company = $this->makeCompany('ACME');
        $this->purger  = new FilePurger($this->db);
    }

    protected function tearDown(): void
    {
        $this->trackStoredFiles();
        parent::tearDown();
    }

    /** An invoice with a stored PDF. Returns [invoiceId, absolute file path, file row id]. */
    private function invoiceWithFile(string $status, array $over = []): array
    {
        $id = $this->makeInvoice($this->company, $this->user, $status);
        if ($over !== []) {
            $this->db->table('invoices')->where('id', $id)->update($over);
        }
        $fileId = (new InvoiceFiles($this->db))->store($id, $this->user, $this->pdfFile('f' . $id), 'inv.pdf');
        $row    = $this->db->table('invoice_files')->where('id', $fileId)->get()->getRowArray();

        return [$id, InvoiceFiles::directory() . '/' . $row['stored_name'], $fileId];
    }

    private function ago(int $days): string
    {
        return date('Y-m-d H:i:s', strtotime("-{$days} days"));
    }

    private function synced(int $completedDaysAgo, int $syncedDaysAgo): array
    {
        return [
            'completed_at' => $this->ago($completedDaysAgo), 'ended_at' => $this->ago($completedDaysAgo), 'sync_status' => 'synced',
            'synced_at' => $this->ago($syncedDaysAgo), 'sharepoint_item_id' => '42', 'sharepoint_url' => 'https://contoso.sharepoint.com/f.pdf',
        ];
    }

    public function testCompletedAndSyncedFileIsDeletedAfterRetentionOnly(): void
    {
        [, $oldPath, $oldFile] = $this->invoiceWithFile('paid', $this->synced(40, 35));
        [, $youngPath]         = $this->invoiceWithFile('paid', $this->synced(40, 5));
        [, $recentPath]        = $this->invoiceWithFile('paid', $this->synced(10, 10));

        $r = $this->purger->run(false, 30);

        $this->assertSame(1, $r['deleted']);
        $this->assertFileDoesNotExist($oldPath);
        $this->assertFileExists($youngPath, '30 days run from the later of completion and confirmed sync');
        $this->assertFileExists($recentPath);
        $this->assertNotNull($this->db->table('invoice_files')->where('id', $oldFile)->get()->getRowArray()['purged_at']);
        $this->assertSame('file.purged', $this->db->table('audit_log')->orderBy('id', 'DESC')->get()->getRowArray()['event']);
    }

    public function testNeverPurgedWithoutConfirmedSharePointReceipt(): void
    {
        $paths = [];
        foreach (['pending', 'failed', 'not_configured', 'syncing', null] as $sync) {
            [, $paths[]] = $this->invoiceWithFile('paid', ['completed_at' => $this->ago(400), 'ended_at' => $this->ago(400), 'sync_status' => $sync, 'synced_at' => null]);
        }
        // "synced" with no item or link recorded is not confirmation either.
        [, $paths[]] = $this->invoiceWithFile('paid', ['completed_at' => $this->ago(400), 'sync_status' => 'synced', 'synced_at' => $this->ago(400)]);

        $r = $this->purger->run(false, 30);

        $this->assertSame(0, $r['eligible']);
        foreach ($paths as $p) {
            $this->assertFileExists($p);
        }
    }

    public function testInFlightInvoicesAreNeverTouched(): void
    {
        $paths = [];
        foreach (['draft', 'in_approval', 'returned', 'approved'] as $status) {
            [, $paths[]] = $this->invoiceWithFile($status, ['ended_at' => $this->ago(400), 'updated_at' => $this->ago(400)]);
        }

        $this->assertSame(0, $this->purger->run(false, 30)['eligible']);
        foreach ($paths as $p) {
            $this->assertFileExists($p);
        }
    }

    public function testRejectedAndCancelledPurgedAfterTheyEnd(): void
    {
        [, $rej]   = $this->invoiceWithFile('rejected', ['ended_at' => $this->ago(31)]);
        [, $can]   = $this->invoiceWithFile('cancelled', ['ended_at' => $this->ago(31)]);
        [, $fresh] = $this->invoiceWithFile('rejected', ['ended_at' => $this->ago(3)]);

        $r = $this->purger->run(false, 30);

        $this->assertSame(2, $r['deleted']);
        $this->assertFileDoesNotExist($rej);
        $this->assertFileDoesNotExist($can);
        $this->assertFileExists($fresh);
    }

    public function testOlderReplacedFilesGoWithTheInvoice(): void
    {
        [$id, $first] = $this->invoiceWithFile('paid', $this->synced(60, 60));
        (new InvoiceFiles($this->db))->store($id, $this->user, $this->pdfFile('second'), 'again.pdf');

        $r = $this->purger->run(false, 30);

        $this->assertSame(2, $r['deleted']);
        $this->assertFileDoesNotExist($first);
        $this->assertSame(0, $this->db->table('invoice_files')->where('invoice_id', $id)->where('purged_at', null)->countAllResults());
    }

    public function testDryRunChangesNothing(): void
    {
        [, $path, $fileId] = $this->invoiceWithFile('paid', $this->synced(60, 60));

        $r = $this->purger->run(true, 30);

        $this->assertSame(1, $r['eligible']);
        $this->assertSame(0, $r['deleted']);
        $this->assertFileExists($path);
        $this->assertNull($this->db->table('invoice_files')->where('id', $fileId)->get()->getRowArray()['purged_at']);
    }

    public function testAlreadyMissingFileIsRecordedAndRunIsRepeatable(): void
    {
        [, $path, $fileId] = $this->invoiceWithFile('paid', $this->synced(60, 60));
        unlink($path);

        $first  = $this->purger->run(false, 30);
        $second = $this->purger->run(false, 30);

        $this->assertSame(1, $first['missing']);
        $this->assertSame(0, $second['eligible']);
        $this->assertNotNull($this->db->table('invoice_files')->where('id', $fileId)->get()->getRowArray()['purged_at']);
    }

    public function testRetentionDaysAreHonoured(): void
    {
        [, $path] = $this->invoiceWithFile('paid', $this->synced(10, 10));

        $this->assertSame(0, $this->purger->run(true, 30)['eligible']);
        $this->assertSame(1, $this->purger->run(true, 7)['eligible']);
        $this->assertFileExists($path);
    }
}

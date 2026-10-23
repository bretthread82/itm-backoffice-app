<?php

declare(strict_types=1);

namespace Tests\Feature;

use App\Services\InvoiceQuery;
use CodeIgniter\Shield\Entities\User;
use Tests\Support\FeatureTestCase;

/**
 * @internal
 */
final class InvoiceSummaryCardTest extends FeatureTestCase
{
    private int $acme;
    private int $beta;
    private User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = $this->shieldUser('sue@example.test', 'Sue');
        $this->acme = $this->makeCompany('ACME');
        $this->beta = $this->makeCompany('BETA');
        $this->join((int) $this->user->id, $this->acme, true);
    }

    /** $waitingToPay: a processed invoice that still has its pay step ahead of it. */
    private function invoice(int $company, string $status, string $amount, string $currency = 'USD', string $supplier = 'Acme Supplies', bool $waitingToPay = false): void
    {
        $id = $this->makeInvoice($company, (int) $this->user->id, $status);
        $this->db->table('invoices')->where('id', $id)->update([
            'amount' => $amount, 'currency' => $currency, 'supplier' => $supplier, 'current_step_order' => $waitingToPay ? 3 : null,
        ]);
    }

    /** @return array<string, mixed> */
    private function summary(array $get = []): array
    {
        return (new InvoiceQuery())->paidUnpaid((int) $this->user->id, $get);
    }

    public function testSplitsPaidFromUnpaidAndTotalsThem(): void
    {
        $this->invoice($this->acme, 'paid', '100.00');
        $this->invoice($this->acme, 'paid', '40.00');
        $this->invoice($this->acme, 'in_approval', '30.00');
        $this->invoice($this->acme, 'returned', '5.50');
        $this->invoice($this->acme, 'processed', '20.00', 'USD', 'Acme Supplies', true);   // processed, waiting to be paid
        $this->invoice($this->acme, 'processed', '999.00');                                  // processed on a route with no pay step: neither
        $this->invoice($this->acme, 'draft', '888.00');
        $this->invoice($this->acme, 'rejected', '777.00');
        $this->invoice($this->acme, 'cancelled', '666.00');
        $this->invoice($this->beta, 'in_approval', '5000.00');                               // not theirs

        $s = $this->summary();

        $this->assertSame(['count' => 2, 'amount' => ['USD' => 140.0]], $s['paid']);
        $this->assertSame(['count' => 3, 'amount' => ['USD' => 55.5]], $s['unpaid']);
        $this->assertSame(['count' => 5, 'amount' => ['USD' => 195.5]], $s['total']);
    }

    public function testCurrenciesStaySeparate(): void
    {
        $this->invoice($this->acme, 'paid', '10.00', 'USD');
        $this->invoice($this->acme, 'in_approval', '20.00', 'BBD');
        $this->invoice($this->acme, 'in_approval', '1.00', 'USD');

        $s = $this->summary();

        $this->assertEqualsCanonicalizing(['USD' => 11.0, 'BBD' => 20.0], $s['total']['amount']);
        $this->assertSame(['USD' => 10.0], $s['paid']['amount']);
        $this->assertEqualsCanonicalizing(['USD' => 1.0, 'BBD' => 20.0], $s['unpaid']['amount']);
    }

    public function testFiltersNarrowTheCardButTheStatusFilterIsIgnored(): void
    {
        $this->invoice($this->acme, 'paid', '100.00', 'USD', 'Alpha Ltd');
        $this->invoice($this->acme, 'in_approval', '30.00', 'USD', 'Alpha Ltd');
        $this->invoice($this->acme, 'in_approval', '700.00', 'USD', 'Beta Ltd');

        $bySupplier = $this->summary(['q' => 'Alpha']);
        $this->assertSame(['USD' => 100.0], $bySupplier['paid']['amount']);
        $this->assertSame(['USD' => 30.0], $bySupplier['unpaid']['amount']);

        $statusIgnored = $this->summary(['q' => 'Alpha', 'status' => 'paid']);
        $this->assertSame($bySupplier, $statusIgnored);

        $byAmount = $this->summary(['min' => '50']);
        $this->assertSame(['USD' => 100.0], $byAmount['paid']['amount']);
        $this->assertSame(['USD' => 700.0], $byAmount['unpaid']['amount']);
    }

    public function testNoInvoicesGivesEmptyTotals(): void
    {
        $s = $this->summary();

        $this->assertSame(['count' => 0, 'amount' => []], $s['total']);
    }

    public function testRegisterShowsTheThreeCards(): void
    {
        $this->invoice($this->acme, 'paid', '100.00');
        $this->invoice($this->acme, 'in_approval', '30.00');

        $res = $this->actingAs($this->user)->get('invoices?status=paid');
        $res->assertStatus(200);
        $res->assertSee('Paid');
        $res->assertSee('Unpaid');
        $res->assertSee('Total amount');
        $res->assertSee('USD 100.00');
        $res->assertSee('USD 30.00');
        $res->assertSee('USD 130.00');
    }

    public function testRegisterFiltersByProcessedPaidOutstandingAndTheOldCompletedName(): void
    {
        $this->invoice($this->acme, 'paid', '1.00', 'USD', 'Paid Co');
        $this->invoice($this->acme, 'processed', '2.00', 'USD', 'Processed Co');
        $this->invoice($this->acme, 'processed', '3.00', 'USD', 'Waiting Co', true);
        $this->invoice($this->acme, 'in_approval', '4.00', 'USD', 'Open Co');

        $shown = function (string $status): array {
            $body = $this->actingAs($this->user)->get('invoices', ['status' => $status])->getBody();

            return array_values(array_filter(['Paid Co', 'Processed Co', 'Waiting Co', 'Open Co'], static fn (string $n): bool => str_contains($body, $n)));
        };

        $this->assertSame(['Paid Co'], $shown('paid'));
        $this->assertSame(['Processed Co', 'Waiting Co'], $shown('processed'));
        $this->assertSame(['Paid Co', 'Processed Co', 'Waiting Co'], $shown('completed'));
        $this->assertSame(['Waiting Co', 'Open Co'], $shown('outstanding'));
    }
}

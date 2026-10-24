<?php

declare(strict_types=1);

if (! function_exists('ar_status_badge')) {
    /**
     * Status chip for a customer invoice. "Overdue" is not a status: pass the days past due and an issued or part-paid
     * invoice is shown as overdue. Colour is never the only signal; the label says it.
     */
    function ar_status_badge(string $status, int $daysOverdue = 0): string
    {
        $map = [
            'draft'       => ['Draft', 'draft'],
            'in_approval' => ['In approval', 'warning'],
            'approved'    => ['Approved, to be issued', 'info'],
            'issued'      => ['Issued', 'info'],
            'part_paid'   => ['Part paid', 'warning'],
            'paid'        => ['Paid', 'success'],
            'returned'    => ['Returned for changes', 'danger'],
            'rejected'    => ['Rejected', 'danger'],
            'cancelled'   => ['Cancelled', 'dark'],
            'written_off' => ['Written off', 'dark'],
        ];
        [$label, $colour] = $map[$status] ?? [ucfirst($status), 'draft'];

        if ($daysOverdue > 0 && in_array($status, ['issued', 'part_paid'], true)) {
            $label  = $label . ', overdue';
            $colour = 'danger';
        }

        return '<span class="badge-status st-' . $colour . '">' . esc($label) . '</span>';
    }
}

if (! function_exists('ar_status_label')) {
    function ar_status_label(string $status): string
    {
        return strip_tags(ar_status_badge($status));
    }
}

if (! function_exists('ar_days_overdue')) {
    /**
     * Days past the due date on the invoice's company calendar (0 when not late or not being collected).
     *
     * @param array<string, mixed> $inv an ar_invoices row
     */
    function ar_days_overdue(array $inv): int
    {
        return \App\Core\Receivables\Collections::daysOverdue($inv, service('timezone')->todayFor((int) $inv['company_id']));
    }
}

if (! function_exists('ar_route_step_row')) {
    /**
     * HTML for one step in the customer invoice route builder, used for each saved step and, with idx "__IDX__", for the
     * "add step" template. A plain function so it can be called from inside a layout section.
     *
     * @param array<string, mixed>       $step
     * @param list<array<string, mixed>> $users
     * @param list<array<string, mixed>> $teams
     */
    function ar_route_step_row(string $idx, array $step, array $users, array $teams = []): string
    {
        ob_start();
        include APPPATH . 'Helpers/ar_route_step_row.view.php';

        return (string) ob_get_clean();
    }
}

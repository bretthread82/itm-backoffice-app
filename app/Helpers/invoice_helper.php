<?php

declare(strict_types=1);

if (! function_exists('invoice_status_badge')) {
    function invoice_status_badge(string $status): string
    {
        $map = [
            'draft'       => ['Draft', 'draft'],
            'in_approval' => ['In approval', 'warning'],
            'approved'    => ['Approved, being processed', 'info'],
            'processed'   => ['Processed', 'info'],
            'paid'        => ['Paid', 'success'],
            'completed'   => ['Completed', 'success'],   // only seen on data that predates processed/paid
            'returned'    => ['Returned for changes', 'danger'],
            'rejected'    => ['Rejected', 'danger'],
            'cancelled'   => ['Cancelled', 'dark'],
        ];
        [$label, $colour] = $map[$status] ?? [ucfirst($status), 'draft'];

        return '<span class="badge-status st-' . $colour . '">' . esc($label) . '</span>';
    }
}

if (! function_exists('invoice_priority_badge')) {
    /** Red "High priority" badge for invoices of a high priority type; empty string otherwise. */
    function invoice_priority_badge(array $invoice): string
    {
        return ! empty($invoice['high_priority']) ? '<span class="badge text-bg-danger">High priority</span>' : '';
    }
}

if (! function_exists('invoice_status_label')) {
    function invoice_status_label(string $status): string
    {
        return strip_tags(invoice_status_badge($status));
    }
}

if (! function_exists('invoice_currencies')) {
    /**
     * Currencies offered on the invoice form (ISO 4217 code => name). Edit this list to add or remove options.
     *
     * @return array<string, string>
     */
    function invoice_currencies(): array
    {
        return [
            'BBD' => 'Barbados dollar', 'USD' => 'US dollar', 'EUR' => 'Euro', 'GBP' => 'Pound sterling',
            'CAD' => 'Canadian dollar', 'XCD' => 'East Caribbean dollar', 'TTD' => 'Trinidad and Tobago dollar',
            'JMD' => 'Jamaican dollar', 'BSD' => 'Bahamian dollar', 'BZD' => 'Belize dollar', 'GYD' => 'Guyanese dollar',
            'SRD' => 'Surinamese dollar', 'KYD' => 'Cayman Islands dollar', 'HTG' => 'Haitian gourde', 'DOP' => 'Dominican peso',
            'AWG' => 'Aruban florin', 'ANG' => 'Netherlands Antillean guilder', 'AUD' => 'Australian dollar', 'CHF' => 'Swiss franc',
            'CNY' => 'Chinese yuan', 'INR' => 'Indian rupee', 'JPY' => 'Japanese yen', 'MXN' => 'Mexican peso', 'NZD' => 'New Zealand dollar',
        ];
    }
}

if (! function_exists('invoice_money')) {
    function invoice_money(mixed $amount, ?string $currency): string
    {
        return $amount === null || $amount === '' ? '—' : esc(($currency ?: '') . ' ' . number_format((float) $amount, 2));
    }
}

if (! function_exists('invoice_age_badge')) {
    /** Colour-coded days past due: current, warning (1-60), critical (61+). */
    function invoice_age_badge(int $daysPastDue): string
    {
        if ($daysPastDue <= 0) {
            return '';
        }
        $colour = $daysPastDue > 60 ? 'danger' : 'warning';
        $label  = $daysPastDue > 60 ? 'Critical' : 'Overdue';

        return '<span class="badge text-bg-' . $colour . '" title="Days past due">' . $label . ' · ' . $daysPastDue . 'd</span>';
    }
}

if (! function_exists('time_ago')) {
    function time_ago(?string $datetime): string
    {
        if (! $datetime) {
            return '';
        }
        $diff = time() - (int) strtotime($datetime);
        if ($diff < 60) {
            return 'just now';
        }
        if ($diff < 3600) {
            return (int) floor($diff / 60) . ' min ago';
        }
        if ($diff < 86400) {
            return (int) floor($diff / 3600) . ' h ago';
        }
        if ($diff < 86400 * 14) {
            return (int) floor($diff / 86400) . ' d ago';
        }

        return local_time($datetime, 'j M Y');
    }
}

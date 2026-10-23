<?php

declare(strict_types=1);

namespace App\Services\Routing;

/**
 * Turns the rows of the SharePoint "Approval Routing" export into clean routes.
 * Pure: no database, no files. Everything it skips, fixes or flags is reported as an issue
 * so an administrator can review it before anything is written.
 *
 * Rules (see the project plan):
 *  - test data is excluded;
 *  - a blank company is inferred from the invoice type when that is unambiguous;
 *  - obvious typos in role names are corrected;
 *  - exact duplicate steps are dropped, conflicting steps block the route;
 *  - "<type> - Yes" becomes an inactive extra-approval variant of <type>;
 *  - where an "HC <type>" exists, the plain <type> is the legacy copy and is archived (inactive);
 *  - steps are renumbered 1..n and given an inferred step type.
 */
final class RoutingNormalizer
{
    private const TEST_COMPANIES = ['companyb'];
    private const TEST_TYPE_PATTERN = '/^(type\d+|test[_ ]invoice|testing launch)/i';

    /** Names that are mailboxes/generic accounts, not people. */
    private const SHARED_MAILBOXES = ['central processing clerk 1', 'imagine finance', 'frontdesk', 'bpci workshop', 'safety', 'support'];

    /** Outside parties (no user account). */
    private const EXTERNAL_PARTIES = ['aspireqs'];

    /** Typos we are certain about (lower case). */
    private const KNOWN_TYPOS = [
        'gneral manager'            => 'General Manager',
        'cntral processing clerk 1' => 'Central Processing Clerk 1',
    ];

    private const SUBMIT_PATTERN  = '/(central processing clerk|ap clerk|accounts clerk|payables clerk|admin associate|office coordinator|initiator|submitter|finance email|accounts assistant|accounts payment process)/i';
    private const PROCESS_PATTERN = '/(ap processing clerk|accounts payment process|payb|finance email|accounts clerk|payables clerk)/i';

    /**
     * @param list<list<mixed>> $sheet header row first
     *
     * @return array{routes: array<string, array<string, mixed>>, issues: list<array<string, mixed>>, stats: array<string, int>, has_emails: bool}
     */
    public function normalise(array $sheet): array
    {
        [$records, $hasEmails] = $this->readRecords($sheet);
        $issues = [];

        $records = $this->inferBlankCompanies($records, $issues);
        $records = $this->dropUnusable($records, $issues);
        $records = $this->fixRoles($records, $issues);

        $routes = $this->buildRoutes($records, $issues);
        $this->archiveLegacyHcCopies($routes, $issues);
        $this->checkVariantsHaveBase($routes, $issues);

        $stats = [
            'rows'      => count($records),
            'routes'    => count($routes),
            'active'    => count(array_filter($routes, static fn (array $r): bool => $r['active'] && ! $r['blocked'])),
            'archived'  => count(array_filter($routes, static fn (array $r): bool => $r['archived'])),
            'variants'  => count(array_filter($routes, static fn (array $r): bool => $r['variant'] !== 'default')),
            'blocked'   => count(array_filter($routes, static fn (array $r): bool => $r['blocked'])),
            'companies' => count(array_unique(array_column($routes, 'company_code'))),
            'steps'     => array_sum(array_map(static fn (array $r): int => count($r['steps']), $routes)),
        ];

        return ['routes' => $routes, 'issues' => $issues, 'stats' => $stats, 'has_emails' => $hasEmails];
    }

    public static function companyCode(string $company): string
    {
        $code = strtoupper(trim((string) preg_replace('/[^A-Za-z0-9]+/', '_', $company), '_'));

        return $code === '' ? 'UNKNOWN' : $code;
    }

    public static function personKey(string $name): string
    {
        $name = strtolower(self::clean($name));

        return trim((string) preg_replace('/[^a-z0-9 ]+/', '', $name));
    }

    public static function clean(?string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', (string) $value));
    }

    // ------------------------------------------------------------------ reading

    /**
     * @param list<list<mixed>> $sheet
     *
     * @return array{0: list<array<string, mixed>>, 1: bool}
     */
    private function readRecords(array $sheet): array
    {
        if ($sheet === []) {
            return [[], false];
        }

        $header = array_map(static fn ($h): string => strtolower((string) preg_replace('/[^a-z0-9]/i', '', (string) $h)), array_shift($sheet));
        $find   = static function (array $names) use ($header): ?int {
            foreach ($names as $n) {
                $i = array_search($n, $header, true);
                if ($i !== false) {
                    return (int) $i;
                }
            }

            return null;
        };

        $col = [
            'company' => $find(['company']),
            'type'    => $find(['invoicetype', 'type']),
            'title'   => $find(['title']),
            'order'   => $find(['roleorder', 'steporder', 'order']),
            'role'    => $find(['role', 'jobtitle']),
            'person'  => $find(['person', 'approver', 'name']),
            'email'   => $find(['personemail', 'email', 'emailaddress', 'personupn', 'upn']),
            'modified' => $find(['modified']),
        ];

        $get = static fn (array $cells, ?int $i): string => $i === null ? '' : self::clean(isset($cells[$i]) ? (string) $cells[$i] : '');

        $records = [];
        foreach ($sheet as $n => $cells) {
            $type = $get($cells, $col['type']);
            if ($type === '') {
                $type = $get($cells, $col['title']);
            }

            $record = [
                'line'    => $n + 2,
                'company' => $get($cells, $col['company']),
                'type'    => $type,
                'order'   => $this->parseOrder($get($cells, $col['order'])),
                'role'    => $get($cells, $col['role']),
                'person'  => $get($cells, $col['person']),
                'email'   => strtolower($get($cells, $col['email'])),
            ];

            if (implode('', [$record['company'], $record['type'], $record['role'], $record['person']]) === '' && $record['order'] === null) {
                continue; // empty line
            }
            $records[] = $record;
        }

        return [$records, $col['email'] !== null];
    }

    private function parseOrder(string $value): ?int
    {
        if ($value === '' || ! is_numeric($value)) {
            return null;
        }
        $n = (int) round((float) $value);

        return $n >= 1 ? $n : null;
    }

    // ------------------------------------------------------------------ cleaning

    /**
     * @param list<array<string, mixed>> $records
     * @param list<array<string, mixed>> $issues
     *
     * @return list<array<string, mixed>>
     */
    private function inferBlankCompanies(array $records, array &$issues): array
    {
        $companiesByType = [];
        foreach ($records as $r) {
            if ($r['company'] !== '' && $r['type'] !== '') {
                $companiesByType[strtolower($r['type'])][$r['company']] = true;
            }
        }

        foreach ($records as &$r) {
            if ($r['company'] !== '' || $r['type'] === '') {
                continue;
            }
            $known = array_keys($companiesByType[strtolower($r['type'])] ?? []);
            if (count($known) === 1) {
                $r['company'] = $known[0];
                $issues[]     = $this->issue('warning', $r['line'], 'Blank company filled in as "' . $known[0] . '" because every other row for "' . $r['type'] . '" uses it.');
            }
        }

        return $records;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param list<array<string, mixed>> $issues
     *
     * @return list<array<string, mixed>>
     */
    private function dropUnusable(array $records, array &$issues): array
    {
        $kept = [];
        foreach ($records as $r) {
            if (in_array(strtolower($r['company']), self::TEST_COMPANIES, true) || preg_match(self::TEST_TYPE_PATTERN, $r['type']) === 1) {
                $issues[] = $this->issue('info', $r['line'], 'Test data skipped: ' . $r['company'] . ' / ' . $r['type'] . '.');
                continue;
            }
            if ($r['company'] === '' || $r['type'] === '') {
                $issues[] = $this->issue('attention', $r['line'], 'Skipped: company or invoice type is missing and could not be worked out.');
                continue;
            }
            if ($r['role'] === '' && $r['person'] === '') {
                $issues[] = $this->issue('attention', $r['line'], 'Skipped: no role and no person for "' . $r['type'] . '".');
                continue;
            }
            if ($r['order'] === null) {
                $issues[] = $this->issue('attention', $r['line'], 'Skipped: no role order for "' . $r['type'] . '" (' . ($r['role'] ?: $r['person']) . '). Add this step in the route builder.');
                continue;
            }
            $kept[] = $r;
        }

        return $kept;
    }

    /**
     * @param list<array<string, mixed>> $records
     * @param list<array<string, mixed>> $issues
     *
     * @return list<array<string, mixed>>
     */
    private function fixRoles(array $records, array &$issues): array
    {
        $freq = [];
        foreach ($records as $r) {
            if ($r['role'] !== '') {
                $freq[$r['role']] = ($freq[$r['role']] ?? 0) + 1;
            }
        }
        $common = array_keys(array_filter($freq, static fn (int $n): bool => $n >= 5));

        foreach ($records as &$r) {
            if ($r['role'] === '' && $r['person'] !== '') {
                $r['role'] = $r['person'];
                $issues[]  = $this->issue('warning', $r['line'], 'Role was blank for "' . $r['type'] . '"; used "' . $r['person'] . '" as the role.');
            }

            foreach (['role', 'person'] as $field) {
                $known = self::KNOWN_TYPOS[strtolower($r[$field])] ?? null;
                if ($known !== null && $known !== $r[$field]) {
                    $issues[] = $this->issue('warning', $r['line'], 'Typo corrected: "' . $r[$field] . '" -> "' . $known . '".');
                    $r[$field] = $known;
                }
            }

            if ($r['role'] !== '' && ($freq[$r['role']] ?? 0) === 1) {
                foreach ($common as $c) {
                    if (str_starts_with(strtolower($r['role']), strtolower($c) . ' ')) {
                        $issues[] = $this->issue('warning', $r['line'], 'Unusual role "' . $r['role'] . '" looks like "' . $c . '" with extra text. Left unchanged; please review.');
                        break;
                    }
                }
            }

            if ($r['role'] !== '' && ($freq[$r['role']] ?? 0) <= 2) {
                foreach ($common as $c) {
                    if ($c !== $r['role'] && levenshtein(strtolower($r['role']), strtolower($c)) === 1
                        && preg_replace('/\D/', '', $r['role']) === preg_replace('/\D/', '', $c)) {
                        $issues[] = $this->issue('warning', $r['line'], 'Probable typo corrected: "' . $r['role'] . '" -> "' . $c . '".');
                        $r['role'] = $c;
                        break;
                    }
                }
            }
        }

        return $records;
    }

    // ------------------------------------------------------------------ routes

    /**
     * @param list<array<string, mixed>> $records
     * @param list<array<string, mixed>> $issues
     *
     * @return array<string, array<string, mixed>>
     */
    private function buildRoutes(array $records, array &$issues): array
    {
        $routes = [];

        foreach ($records as $r) {
            $variant = 'default';
            $base    = $r['type'];
            if (preg_match('/^(.*?)\s*-\s*yes$/i', $r['type'], $m) === 1 && trim($m[1]) !== '') {
                $variant = 'extra_approval';
                $base    = trim($m[1]);
            }

            $code = self::companyCode($r['company']);
            $key  = $code . '|' . strtolower($base) . '|' . $variant;

            $routes[$key] ??= [
                'key'          => $key,
                'company_code' => $code,
                'company_name' => $r['company'],
                'type_name'    => $base,
                'variant'      => $variant,
                'active'       => $variant === 'default',
                'archived'     => false,
                'blocked'      => false,
                'source_title' => $r['type'],
                'steps'        => [],
                '_raw'         => [],
            ];

            $routes[$key]['_raw'][] = $r;
        }

        foreach ($routes as $key => &$route) {
            $route['steps'] = $this->buildSteps($route, $issues);
            unset($route['_raw']);

            if ($route['steps'] === []) {
                unset($routes[$key]);
            }
        }

        ksort($routes);

        return $routes;
    }

    /**
     * @param array<string, mixed>       $route
     * @param list<array<string, mixed>> $issues
     *
     * @return list<array<string, mixed>>
     */
    private function buildSteps(array &$route, array &$issues): array
    {
        $byOrder = [];
        foreach ($route['_raw'] as $r) {
            $byOrder[$r['order']][] = $r;
        }
        ksort($byOrder);

        $steps = [];
        foreach ($byOrder as $order => $group) {
            $first = $group[0];
            foreach (array_slice($group, 1) as $other) {
                $same = strtolower($other['role']) === strtolower($first['role'])
                    && self::personKey($other['person']) === self::personKey($first['person']);

                if ($same) {
                    $issues[] = $this->issue('warning', $other['line'], 'Duplicate step ' . $order . ' dropped for "' . $route['source_title'] . '" (same as line ' . $first['line'] . ').', $route['key']);
                } else {
                    $route['blocked'] = true;
                    $issues[]         = $this->issue('attention', $other['line'], 'Conflicting steps at order ' . $order . ' for "' . $route['source_title'] . '": "' . $first['role'] . ' / ' . $first['person'] . '" (line ' . $first['line'] . ') vs "' . $other['role'] . ' / ' . $other['person'] . '". This route will not be imported until fixed.', $route['key']);
                }
            }

            $email = '';
            foreach ($group as $g) {
                if ($g['email'] !== '') {
                    $email = $g['email'];
                    break;
                }
            }

            $steps[] = [
                'order'  => $order,
                'role'   => $first['role'],
                'person' => $first['person'],
                'email'  => $email,
                'lines'  => array_column($group, 'line'),
            ];
        }

        $orders = array_column($steps, 'order');
        if ($orders !== range(1, count($orders)) && ! $route['blocked']) {
            $issues[] = $this->issue('warning', null, 'Role orders for "' . $route['source_title'] . '" are ' . implode(',', $orders) . ' (gaps); renumbered 1..' . count($orders) . '.', $route['key']);
        }

        $count = count($steps);
        foreach ($steps as $i => &$s) {
            $s['order']         = $i + 1;
            $s['assignee_kind'] = $this->assigneeKind($s['person']);
            $s['step_type']     = $this->stepType($s, $i, $count);
        }

        return $steps;
    }

    private function assigneeKind(string $person): string
    {
        $key = self::personKey($person);

        return match (true) {
            in_array($key, self::EXTERNAL_PARTIES, true) => 'external',
            in_array($key, self::SHARED_MAILBOXES, true), str_contains($key, 'central processing clerk') => 'team',
            default => 'user',
        };
    }

    /**
     * @param array<string, mixed> $step
     */
    private function stepType(array $step, int $index, int $count): string
    {
        if ($count < 2) {
            return 'approve';
        }
        if ($index === 0 && (preg_match(self::SUBMIT_PATTERN, $step['role']) === 1 || $step['assignee_kind'] !== 'user')) {
            return 'submit';
        }
        if ($index === $count - 1 && preg_match(self::PROCESS_PATTERN, $step['role']) === 1) {
            return 'process';
        }

        return 'approve';
    }

    /**
     * Where "HC <type>" exists, the plain "<type>" is the legacy copy: keep it, inactive.
     *
     * @param array<string, array<string, mixed>> $routes
     * @param list<array<string, mixed>>          $issues
     */
    private function archiveLegacyHcCopies(array &$routes, array &$issues): void
    {
        foreach ($routes as $r) {
            if (preg_match('/^hc\s+(.+)$/i', $r['type_name'], $m) !== 1) {
                continue;
            }
            $legacy = strtolower(trim($m[1]));
            foreach ($routes as $lkey => &$candidate) {
                if ($candidate['company_code'] === $r['company_code'] && strtolower($candidate['type_name']) === $legacy && ! $candidate['archived']) {
                    $candidate['archived'] = true;
                    $candidate['active']   = false;
                    $issues[]              = $this->issue('info', null, 'Legacy route "' . $candidate['source_title'] . '" archived (inactive) because "HC ' . $m[1] . '" replaces it.', $lkey);
                }
            }
            unset($candidate);
        }
    }

    /**
     * @param array<string, array<string, mixed>> $routes
     * @param list<array<string, mixed>>          $issues
     */
    private function checkVariantsHaveBase(array $routes, array &$issues): void
    {
        foreach ($routes as $r) {
            if ($r['variant'] === 'default') {
                continue;
            }
            $baseKey = $r['company_code'] . '|' . strtolower($r['type_name']) . '|default';
            if (! isset($routes[$baseKey])) {
                $issues[] = $this->issue('warning', null, '"' . $r['source_title'] . '" has no matching base route "' . $r['type_name'] . '".', $r['key']);
            } else {
                $issues[] = $this->issue('info', null, '"' . $r['source_title'] . '" imported as an inactive extra-approval variant (trigger to be defined).', $r['key']);
            }
        }
    }

    /**
     * @return array<string, mixed>
     */
    private function issue(string $level, ?int $line, string $message, ?string $route = null): array
    {
        return ['level' => $level, 'line' => $line, 'message' => $message, 'route' => $route];
    }
}

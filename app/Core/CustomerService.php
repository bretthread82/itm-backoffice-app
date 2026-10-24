<?php

declare(strict_types=1);

namespace App\Core;

use CodeIgniter\Database\ConnectionInterface;
use RuntimeException;

/**
 * The global customer list. Every module reads and maintains customers through this service
 * (service('customers')); nothing else touches the `customers` or `customer_contacts` tables. Reference a
 * customer by id; if a record must never change when a customer is renamed, also keep the name as a snapshot.
 */
class CustomerService
{
    public const MAX_CODE = 50;
    public const MAX_NAME = 200;

    /** Optional text fields and their maximum lengths. */
    private const TEXT_FIELDS = [
        'legal_name' => 200, 'tax_id' => 50, 'email' => 255, 'phone' => 50, 'address_line1' => 200, 'address_line2' => 200,
        'city' => 100, 'region' => 100, 'postal_code' => 20, 'country' => 100, 'notes' => 5000,
    ];

    private ConnectionInterface $db;

    public function __construct(?ConnectionInterface $db = null)
    {
        $this->db = $db ?? db_connect();
    }

    /**
     * Pick-list entries, ordered by name.
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function options(bool $activeOnly = true): array
    {
        $b = $this->db->table('customers')->select('id, code, name')->orderBy('name');

        if ($activeOnly) {
            $b->where('active', 1);
        }

        return $this->shape($b->get()->getResultArray());
    }

    /**
     * Every customer for the admin screen, with optional per-module usage counts.
     *
     * @param array<string, string> $usage alias => SQL counting uses of one customer, with {id} standing for the
     *                                     customer id, e.g. ['invoice_count' => 'SELECT COUNT(*) FROM ar_invoices WHERE customer_id = {id}']
     *
     * @return list<array<string, mixed>>
     */
    public function all(array $usage = []): array
    {
        $extra = '';

        foreach ($usage as $alias => $sql) {
            $extra .= ', (' . str_replace('{id}', 'c.id', $sql) . ') AS ' . preg_replace('/[^a-z0-9_]/i', '', (string) $alias);
        }

        return $this->db->query('SELECT c.id, c.code, c.name, c.email, c.phone, c.payment_terms_days, c.active' . $extra . ' FROM customers c ORDER BY c.active DESC, c.name')->getResultArray();
    }

    /** @return array<string, mixed>|null */
    public function find(int $id): ?array
    {
        return $this->db->table('customers')->where('id', $id)->get()->getRowArray();
    }

    /** @return array<string, mixed>|null case-insensitive exact match */
    public function findByName(string $name): ?array
    {
        $name = trim($name);

        return $name === '' ? null : $this->db->table('customers')->where('LOWER(name)', mb_strtolower($name))->get()->getRowArray();
    }

    /** @return array<string, mixed>|null case-insensitive exact match */
    public function findByCode(string $code): ?array
    {
        $code = trim($code);

        return $code === '' ? null : $this->db->table('customers')->where('LOWER(code)', mb_strtolower($code))->get()->getRowArray();
    }

    public function isActive(int $id): bool
    {
        return $this->db->table('customers')->where(['id' => $id, 'active' => 1])->countAllResults() > 0;
    }

    /**
     * Active customers whose code or name contains $q (for autocomplete).
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    public function search(string $q, int $limit = 20): array
    {
        $q = trim($q);
        $b = $this->db->table('customers')->select('id, code, name')->where('active', 1)->orderBy('name')->limit(max(1, min(100, $limit)));

        if ($q !== '') {
            $b->groupStart()->like('name', $q)->orLike('code', $q)->groupEnd();
        }

        return $this->shape($b->get()->getResultArray());
    }

    /**
     * @param array<string, mixed> $data code and name are required; see TEXT_FIELDS for the optional details,
     *                                   plus payment_terms_days, default_currency and active
     *
     * @throws RuntimeException with a message fit to show the user
     */
    public function create(array $data, ?int $actorId = null): int
    {
        $row = $this->validated($data, null);
        $now = date('Y-m-d H:i:s');

        $this->db->table('customers')->insert($row + ['active' => ($data['active'] ?? 1) ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        service('audit')->log('customer.created', 'customer', $id, null, ['code' => $row['code'], 'name' => $row['name']], $actorId);

        return $id;
    }

    /**
     * @param array<string, mixed> $data as for create(); an omitted "active" leaves the flag as it is
     *
     * @throws RuntimeException
     */
    public function update(int $id, array $data, ?int $actorId = null): void
    {
        $before = $this->find($id) ?? throw new RuntimeException('Customer not found.');
        $row    = $this->validated($data, $id);
        $active = array_key_exists('active', $data) ? ($data['active'] ? 1 : 0) : (int) $before['active'];

        $this->db->table('customers')->where('id', $id)->update($row + ['active' => $active, 'updated_at' => date('Y-m-d H:i:s')]);
        service('audit')->log(
            'customer.updated',
            'customer',
            $id,
            ['code' => $before['code'], 'name' => $before['name'], 'active' => (int) $before['active']],
            ['code' => $row['code'], 'name' => $row['name'], 'active' => $active],
            $actorId,
        );
    }

    /** @return list<array<string, mixed>> primary contact first */
    public function contacts(int $customerId): array
    {
        return $this->db->table('customer_contacts')->where('customer_id', $customerId)->orderBy('is_primary', 'DESC')->orderBy('name')->get()->getResultArray();
    }

    /**
     * The contact who receives notifications sent to this customer, or null when none is set. Callers decide
     * what to do then (for example fall back to the customer's own email).
     *
     * @return array<string, mixed>|null
     */
    public function notificationContact(int $customerId): ?array
    {
        return $this->db->table('customer_contacts')->where(['customer_id' => $customerId, 'is_notify' => 1])->get()->getRowArray();
    }

    /**
     * Makes one contact the customer's notification contact, replacing any other. The contact needs an email address.
     *
     * @throws RuntimeException
     */
    public function setNotificationContact(int $customerId, int $contactId, ?int $actorId = null): void
    {
        $contact = $this->db->table('customer_contacts')->where(['id' => $contactId, 'customer_id' => $customerId])->get()->getRowArray()
            ?? throw new RuntimeException('Contact not found.');

        if (empty($contact['email'])) {
            throw new RuntimeException('Add an email address to this contact before choosing it for notifications.');
        }

        $before = $this->notificationContact($customerId);

        $this->db->transStart();
        $this->db->table('customer_contacts')->where('customer_id', $customerId)->update(['is_notify' => 0]);
        $this->db->table('customer_contacts')->where('id', $contactId)->update(['is_notify' => 1, 'updated_at' => date('Y-m-d H:i:s')]);
        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            throw new RuntimeException('The notification contact could not be saved.');
        }

        service('audit')->log('customer.notification_contact_set', 'customer', $customerId, $before ? ['contact' => $before['name']] : null, ['contact' => $contact['name']], $actorId);
    }

    /** Clears the notification contact; the customer then has none. */
    public function clearNotificationContact(int $customerId, ?int $actorId = null): void
    {
        $before = $this->notificationContact($customerId);

        if ($before === null) {
            return;
        }

        $this->db->table('customer_contacts')->where('customer_id', $customerId)->update(['is_notify' => 0]);
        service('audit')->log('customer.notification_contact_cleared', 'customer', $customerId, ['contact' => $before['name']], null, $actorId);
    }

    /**
     * Adds a contact. Marking it primary clears the flag on the customer's other contacts; the same goes for
     * is_notify, which also needs an email address.
     *
     * @param array{name?: string, email?: string, phone?: string, role?: string, is_primary?: bool|int, is_notify?: bool|int} $data
     *
     * @throws RuntimeException
     */
    public function addContact(int $customerId, array $data, ?int $actorId = null): int
    {
        $this->find($customerId) ?? throw new RuntimeException('Customer not found.');
        $row = $this->validatedContact($data);
        $now = date('Y-m-d H:i:s');

        $notify = ! empty($data['is_notify']);
        if ($notify && $row['email'] === null) {
            throw new RuntimeException('Add an email address to this contact before choosing it for notifications.');
        }

        $this->db->transStart();
        $primary = ! empty($data['is_primary']) || $this->contacts($customerId) === [];
        if ($primary) {
            $this->db->table('customer_contacts')->where('customer_id', $customerId)->update(['is_primary' => 0]);
        }
        if ($notify) {
            $this->db->table('customer_contacts')->where('customer_id', $customerId)->update(['is_notify' => 0]);
        }
        $this->db->table('customer_contacts')->insert($row + ['customer_id' => $customerId, 'is_primary' => $primary ? 1 : 0, 'is_notify' => $notify ? 1 : 0, 'created_at' => $now, 'updated_at' => $now]);
        $id = (int) $this->db->insertID();
        $this->db->transComplete();

        if (! $this->db->transStatus()) {
            throw new RuntimeException('The contact could not be saved.');
        }

        service('audit')->log('customer.contact_added', 'customer', $customerId, null, ['contact' => $row['name']], $actorId);

        return $id;
    }

    /** @throws RuntimeException */
    public function removeContact(int $customerId, int $contactId, ?int $actorId = null): void
    {
        $contact = $this->db->table('customer_contacts')->where(['id' => $contactId, 'customer_id' => $customerId])->get()->getRowArray()
            ?? throw new RuntimeException('Contact not found.');

        $this->db->transStart();
        $this->db->table('customer_contacts')->where('id', $contactId)->delete();

        // Keep one primary contact while any remain.
        if ((int) $contact['is_primary'] === 1) {
            $next = $this->db->table('customer_contacts')->where('customer_id', $customerId)->orderBy('name')->get(1)->getRowArray();
            if ($next !== null) {
                $this->db->table('customer_contacts')->where('id', $next['id'])->update(['is_primary' => 1]);
            }
        }
        $this->db->transComplete();

        service('audit')->log('customer.contact_removed', 'customer', $customerId, ['contact' => $contact['name']], null, $actorId);
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array<string, mixed> the columns to write
     *
     * @throws RuntimeException
     */
    private function validated(array $data, ?int $exceptId): array
    {
        $code = trim((string) ($data['code'] ?? ''));
        $name = trim((string) ($data['name'] ?? ''));

        if ($code === '' || mb_strlen($code) > self::MAX_CODE) {
            throw new RuntimeException('Enter a customer code of up to ' . self::MAX_CODE . ' characters.');
        }

        if ($name === '' || mb_strlen($name) > self::MAX_NAME) {
            throw new RuntimeException('Enter a customer name of up to ' . self::MAX_NAME . ' characters.');
        }

        if ($this->taken('code', $code, $exceptId)) {
            throw new RuntimeException('Another customer already has that code.');
        }

        if ($this->taken('name', $name, $exceptId)) {
            throw new RuntimeException('Another customer already has that name.');
        }

        $row = ['code' => $code, 'name' => $name];

        foreach (self::TEXT_FIELDS as $field => $max) {
            $value = trim((string) ($data[$field] ?? ''));

            if (mb_strlen($value) > $max) {
                throw new RuntimeException('"' . str_replace('_', ' ', $field) . '" is longer than ' . $max . ' characters.');
            }

            $row[$field] = $value === '' ? null : $value;
        }

        if ($row['email'] !== null && filter_var($row['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Enter a valid email address.');
        }

        $terms = trim((string) ($data['payment_terms_days'] ?? ''));
        if ($terms !== '' && (! ctype_digit($terms) || (int) $terms > 365)) {
            throw new RuntimeException('Payment terms must be a whole number of days, 0 to 365.');
        }
        $row['payment_terms_days'] = $terms === '' ? null : (int) $terms;

        $currency = strtoupper(trim((string) ($data['default_currency'] ?? '')));
        if ($currency !== '' && ! preg_match('/^[A-Z]{3}$/', $currency)) {
            throw new RuntimeException('Currency must be a three-letter code such as USD.');
        }
        $row['default_currency'] = $currency === '' ? null : $currency;

        return $row;
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return array{name: string, email: ?string, phone: ?string, role: ?string}
     *
     * @throws RuntimeException
     */
    private function validatedContact(array $data): array
    {
        $name = trim((string) ($data['name'] ?? ''));
        if ($name === '' || mb_strlen($name) > 200) {
            throw new RuntimeException('Enter a contact name of up to 200 characters.');
        }

        $out = ['name' => $name];

        foreach (['email' => 255, 'phone' => 50, 'role' => 100] as $field => $max) {
            $value = trim((string) ($data[$field] ?? ''));

            if (mb_strlen($value) > $max) {
                throw new RuntimeException('Contact ' . $field . ' is longer than ' . $max . ' characters.');
            }

            $out[$field] = $value === '' ? null : $value;
        }

        if ($out['email'] !== null && filter_var($out['email'], FILTER_VALIDATE_EMAIL) === false) {
            throw new RuntimeException('Enter a valid contact email address.');
        }

        return $out;
    }

    private function taken(string $field, string $value, ?int $exceptId): bool
    {
        $q = $this->db->table('customers')->where('LOWER(' . $field . ')', mb_strtolower($value));

        if ($exceptId !== null) {
            $q->where('id !=', $exceptId);
        }

        return $q->countAllResults() > 0;
    }

    /**
     * @param list<array<string, mixed>> $rows
     *
     * @return list<array{id: int, code: string, name: string}>
     */
    private function shape(array $rows): array
    {
        return array_map(static fn (array $r): array => ['id' => (int) $r['id'], 'code' => (string) $r['code'], 'name' => (string) $r['name']], $rows);
    }
}

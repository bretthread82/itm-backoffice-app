<?php

declare(strict_types=1);

namespace App\Core\Backups;

use RuntimeException;
use Throwable;

/**
 * Saved backup destinations. Settings are stored encrypted; callers outside this class only ever
 * see secrets as "set / not set".
 */
class DestinationService
{
    public function __construct(
        private readonly ?SecretBox $box = null,
        private readonly ?DestinationFactory $factory = null,
    ) {
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function all(): array
    {
        return db_connect()->table('bk_destinations')->where('deleted_at', null)->orderBy('name')->get()->getResultArray();
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function enabled(?int $onlyId = null): array
    {
        $b = db_connect()->table('bk_destinations')->where('deleted_at', null)->where('enabled', 1)->orderBy('id');

        if ($onlyId !== null) {
            $b->where('id', $onlyId);
        }

        return $b->get()->getResultArray();
    }

    /**
     * @return array<string, mixed>|null
     */
    public function find(int $id): ?array
    {
        return db_connect()->table('bk_destinations')->where('id', $id)->where('deleted_at', null)->get()->getRowArray();
    }

    /**
     * Saved settings with secrets decrypted. For building a destination only; never show these.
     *
     * @param array<string, mixed> $row
     *
     * @return array<string, mixed>
     */
    public function settings(array $row): array
    {
        return $this->box()->open($row['settings'] ?? null);
    }

    /**
     * Form values for editing: secrets are blanked and reported separately.
     *
     * @param array<string, mixed> $row
     *
     * @return array{values: array<string, mixed>, secretsSet: list<string>}
     */
    public function formValues(array $row): array
    {
        $settings = $this->settings($row);
        $fields   = DestinationFactory::types()[$row['type']]['fields'] ?? [];
        $set      = [];

        foreach ($fields as $key => $def) {
            if (($def['kind'] ?? '') === 'password') {
                if (($settings[$key] ?? '') !== '') {
                    $set[] = $key;
                }

                unset($settings[$key]);
            }
        }

        return ['values' => $settings, 'secretsSet' => $set];
    }

    /**
     * Validation rules for a type's form, built from its field list.
     *
     * @return array<string, string>
     */
    public function rules(string $type, bool $editing): array
    {
        $rules = ['name' => 'required|max_length[120]', 'keep_last' => 'required|is_natural_no_zero|less_than_equal_to[3650]'];

        foreach (DestinationFactory::types()[$type]['fields'] ?? [] as $key => $def) {
            $kind = $def['kind'];

            if ($kind === 'checkbox') {
                continue;
            }

            $required = ! empty($def['required']) && ! ($kind === 'password' && $editing);
            $rule     = $required ? 'required' : 'permit_empty';

            $rules['s_' . $key] = $kind === 'select' ? $rule . '|in_list[' . implode(',', array_keys($def['options'])) . ']' : $rule . '|max_length[500]';
        }

        return $rules;
    }

    /**
     * @param array<string, mixed> $input  posted fields: name, keep_last, enabled, s_<setting>
     */
    public function create(string $type, array $input, ?int $actor): int
    {
        if (! isset(DestinationFactory::types()[$type])) {
            throw new RuntimeException('Unknown destination type.');
        }

        $settings = $this->collect($type, $input, []);
        $this->check($type, $settings);

        $now = date('Y-m-d H:i:s');
        $db  = db_connect();
        $db->table('bk_destinations')->insert([
            'name'       => trim((string) $input['name']),
            'type'       => $type,
            'settings'   => $this->box()->seal($settings),
            'enabled'    => ! empty($input['enabled']) ? 1 : 0,
            'keep_last'  => (int) $input['keep_last'],
            'created_at' => $now,
            'updated_at' => $now,
        ]);
        $id = (int) $db->insertID();

        service('audit')->log('backups.destination_added', 'backups', $id, null, ['name' => trim((string) $input['name']), 'type' => $type], $actor);

        return $id;
    }

    /**
     * @param array<string, mixed> $input
     */
    public function update(int $id, array $input, ?int $actor): void
    {
        $row = $this->find($id) ?? throw new RuntimeException('That destination no longer exists.');

        $settings = $this->collect($row['type'], $input, $this->settings($row));
        $this->check($row['type'], $settings);

        db_connect()->table('bk_destinations')->where('id', $id)->update([
            'name'            => trim((string) $input['name']),
            'settings'        => $this->box()->seal($settings),
            'enabled'         => ! empty($input['enabled']) ? 1 : 0,
            'keep_last'       => (int) $input['keep_last'],
            'last_test_at'    => null,
            'last_test_ok'    => null,
            'last_test_error' => null,
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        service('audit')->log('backups.destination_changed', 'backups', $id, ['name' => $row['name']], ['name' => trim((string) $input['name']), 'enabled' => ! empty($input['enabled'])], $actor);
    }

    public function delete(int $id, ?int $actor): void
    {
        $row = $this->find($id);

        if (! $row) {
            return;
        }

        db_connect()->table('bk_destinations')->where('id', $id)->update(['deleted_at' => date('Y-m-d H:i:s'), 'enabled' => 0]);
        service('audit')->log('backups.destination_removed', 'backups', $id, ['name' => $row['name'], 'type' => $row['type']], null, $actor);
    }

    /**
     * Tries the destination and remembers the outcome.
     *
     * @return string|null  null on success, otherwise a message safe to show
     */
    public function test(int $id): ?string
    {
        $row = $this->find($id) ?? throw new RuntimeException('That destination no longer exists.');

        try {
            $this->build($row)->test();
            $error = null;
        } catch (Throwable $e) {
            $error = mb_strimwidth($e->getMessage(), 0, 480, '…');
        }

        db_connect()->table('bk_destinations')->where('id', $id)->update([
            'last_test_at'    => date('Y-m-d H:i:s'),
            'last_test_ok'    => $error === null ? 1 : 0,
            'last_test_error' => $error,
        ]);

        return $error;
    }

    /**
     * @param array<string, mixed> $row
     */
    public function build(array $row): Destinations\Destination
    {
        return ($this->factory ?? new DestinationFactory())->make($row['type'], $this->settings($row));
    }

    /**
     * Merges posted values over the existing settings; a blank secret keeps the stored one.
     *
     * @param array<string, mixed> $input
     * @param array<string, mixed> $existing
     *
     * @return array<string, mixed>
     */
    private function collect(string $type, array $input, array $existing): array
    {
        $out = [];

        foreach (DestinationFactory::types()[$type]['fields'] as $key => $def) {
            $posted = $input['s_' . $key] ?? null;

            $out[$key] = match ($def['kind']) {
                'checkbox' => ! empty($posted),
                'password' => ($posted !== null && $posted !== '') ? (string) $posted : (string) ($existing[$key] ?? ''),
                default    => trim((string) $posted),
            };
        }

        return $out;
    }

    /**
     * Rejects settings the destination class itself refuses (bad address, unsafe folder...) before saving.
     *
     * @param array<string, mixed> $settings
     */
    private function check(string $type, array $settings): void
    {
        ($this->factory ?? new DestinationFactory())->make($type, $settings);
    }

    private function box(): SecretBox
    {
        return $this->box ?? new SecretBox();
    }
}

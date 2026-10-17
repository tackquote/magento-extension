<?php
/**
 * A TackQuote wholesale form definition, and the answers a submission makes of it.
 *
 * The definition comes from `GET /storefront/v1/wholesale-signup[/<slug>]`
 * (tack WholesaleFormsService::getPublic): `{name, description, fields, successMessage}`.
 * Each field is `{key, type, label, required?, options?, placeholder?, help?, showIf?,
 * accept?, maxSizeMb?}`. The server validates every answer again (wholesale-form-schema.ts);
 * this class mirrors what can be checked here so the shopper hears about a missing answer
 * before anything is sent. It is a port of the WooCommerce plugin's
 * Tack_Storefront_Forms::collect_answers()/coerce_answer()/is_shown(), so the two stores
 * build the same payload from the same form.
 *
 * Pure: no I/O, no Magento runtime, fully unit-testable.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

class FormDefinition
{
    /** Field types this module renders; any other type renders as a single-line input. */
    public const TYPES = [
        'text', 'email', 'tel', 'number', 'date', 'tax_id', 'textarea',
        'select', 'multiselect', 'checkbox', 'address', 'file',
    ];

    /** Address parts the server stores, in display order. */
    public const ADDRESS_PARTS = ['line1', 'line2', 'city', 'region', 'postalCode', 'country'];

    /** Address parts the server requires once an address is answered. */
    public const ADDRESS_REQUIRED = ['line1', 'city', 'postalCode', 'country'];

    /** Longest single-line answer (the server's MAX_FIELD_VALUE_LENGTH). */
    public const MAX_TEXT = 320;

    /** Longest textarea answer (the server's MAX_TEXTAREA_VALUE_LENGTH). */
    public const MAX_TEXTAREA = 2000;

    /** Field keys the server accepts (the upload route's own pattern). */
    private const KEY_PATTERN = '/^[A-Za-z][A-Za-z0-9_-]{0,63}$/';

    /**
     * The form, normalised: only fields with a usable key and type survive.
     *
     * @param array $raw The decoded server answer.
     * @return array{name: string, description: string, successMessage: string, fields: array[]}
     */
    public function normalise(array $raw): array
    {
        $fields = [];
        $seen = [];
        foreach ((array) ($raw['fields'] ?? []) as $field) {
            if (!is_array($field)) {
                continue;
            }
            $key = isset($field['key']) && is_string($field['key']) ? $field['key'] : '';
            $type = isset($field['type']) && is_string($field['type']) ? $field['type'] : '';
            if ($key === '' || $type === '' || isset($seen[$key]) || preg_match(self::KEY_PATTERN, $key) !== 1) {
                continue;
            }
            $seen[$key] = true;
            $field['type'] = in_array($type, self::TYPES, true) ? $type : 'text';
            $field['label'] = isset($field['label']) && is_string($field['label']) && $field['label'] !== ''
                ? $field['label']
                : $key;
            $field['required'] = !empty($field['required']);
            $field['options'] = array_values(array_map(
                'strval',
                array_filter((array) ($field['options'] ?? []), 'is_scalar')
            ));
            $fields[] = $field;
        }

        return [
            'name' => is_string($raw['name'] ?? null) ? $raw['name'] : '',
            'description' => is_string($raw['description'] ?? null) ? $raw['description'] : '',
            'successMessage' => is_string($raw['successMessage'] ?? null) ? $raw['successMessage'] : '',
            'fields' => $fields,
            'calendar' => $this->calendar($raw),
        ];
    }

    /**
     * The seller's calendar the server sent with the definition, or null.
     *
     * TackQuote adds `calendar: {timeZone, today}` to a definition with a "No dates in the
     * past" date field: the seller's IANA zone and their today (`YYYY-MM-DD`), the day the
     * server refuses earlier dates against (tack `tenant-today.ts`). An older server sends
     * none, and anything malformed is ignored: the field then falls back to this store's
     * own today, as before.
     *
     * @param array $raw The definition as TackQuote sent it.
     * @return array{timeZone: string, today: string}|null
     */
    public function calendar(array $raw): ?array
    {
        $calendar = $raw['calendar'] ?? null;
        if (!is_array($calendar)) {
            return null;
        }
        $zone = $calendar['timeZone'] ?? null;
        $today = $calendar['today'] ?? null;
        if (!is_string($zone) || !is_string($today) || strlen($zone) > 64) {
            return null;
        }
        // An IANA name: `Asia/Kolkata`, `America/Argentina/Buenos_Aires`, `UTC`, `Etc/GMT+5`.
        if (preg_match('#^[A-Za-z][A-Za-z0-9_+\-]*(/[A-Za-z0-9_+\-]+){0,2}$#', $zone) !== 1) {
            return null;
        }
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $today, $m) !== 1
            || !checkdate((int) $m[2], (int) $m[3], (int) $m[1])) {
            return null;
        }

        return ['timeZone' => $zone, 'today' => $today];
    }

    /**
     * Whether the form has at least one file field.
     *
     * @param array[] $fields
     * @return bool
     */
    public function hasFileField(array $fields): bool
    {
        foreach ($fields as $field) {
            if (($field['type'] ?? '') === 'file') {
                return true;
            }
        }

        return false;
    }

    /**
     * Turn posted values into the answers the server validates.
     *
     * A required, shown field must be answered; nothing may exceed the length caps; hidden
     * (`showIf`) fields are dropped the way the server drops them. File fields are skipped
     * here: they are posted separately and checked by pickFiles().
     *
     * @param array[] $fields Normalised fields.
     * @param array $posted Raw posted values keyed by field key.
     * @return array{values: array, error: string|null}
     */
    public function collectAnswers(array $fields, array $posted): array
    {
        $byKey = $this->byKey($fields);
        $values = [];

        foreach ($fields as $field) {
            $key = (string) $field['key'];
            $type = (string) $field['type'];
            $label = (string) $field['label'];
            if ($type === 'file' || !$this->isShown($field, $byKey, $values)) {
                continue;
            }

            $answer = $this->coerce($type, $field, array_key_exists($key, $posted) ? $posted[$key] : null);
            $missing = $answer === null
                || (is_string($answer) && trim($answer) === '')
                || (is_array($answer) && $answer === []);

            if ($missing) {
                if ($type === 'checkbox') {
                    if (!empty($field['required'])) {
                        return $this->fail((string) __('%1 must be ticked.', $label));
                    }
                    $values[$key] = false;
                    continue;
                }
                if (!empty($field['required'])) {
                    return $this->fail((string) __('%1 is required.', $label));
                }
                continue;
            }

            if (is_string($answer)) {
                $max = $type === 'textarea' ? self::MAX_TEXTAREA : self::MAX_TEXT;
                if (strlen($answer) > $max) {
                    return $this->fail((string) __('%1 is too long.', $label));
                }
            }
            if ($type === 'address') {
                foreach (self::ADDRESS_REQUIRED as $part) {
                    if (empty($answer[$part])) {
                        return $this->fail((string) __('%1: %2 is required.', $label, $this->addressPartLabel($part)));
                    }
                }
            }
            $values[$key] = $answer;
        }

        return ['values' => $values, 'error' => null];
    }

    /**
     * The shown file fields that need a file, and which posted files belong to them.
     *
     * One file per field. A required, shown file field with no file refuses the
     * submission; a file posted for a field the form does not have, or one that is
     * hidden, is ignored.
     *
     * @param array[] $fields Normalised fields.
     * @param array $values Answers collected so far (for showIf).
     * @param array<string,array> $files Field key => one uploaded-file entry.
     * @return array{wanted: array<string, array>, error: string|null} Field key => field.
     */
    public function pickFiles(array $fields, array $values, array $files): array
    {
        $byKey = $this->byKey($fields);
        $wanted = [];
        foreach ($fields as $field) {
            if (($field['type'] ?? '') !== 'file' || !$this->isShown($field, $byKey, $values)) {
                continue;
            }
            $key = (string) $field['key'];
            if (!isset($files[$key])) {
                if (!empty($field['required'])) {
                    return ['wanted' => [], 'error' => (string) __('%1 is required.', (string) $field['label'])];
                }
                continue;
            }
            $wanted[$key] = $field;
        }

        return ['wanted' => $wanted, 'error' => null];
    }

    /**
     * Whether a conditional field is shown for the answers collected so far.
     *
     * Mirrors `isFieldShown()` on the server: the controlling field must itself be shown,
     * and its value must equal the condition (checkboxes compare booleans, everything else
     * trimmed strings). Conditions only look backwards.
     *
     * @param array $field
     * @param array<string,array> $byKey
     * @param array $values
     * @param int $depth
     * @return bool
     */
    public function isShown(array $field, array $byKey, array $values, int $depth = 0): bool
    {
        $condition = $field['showIf'] ?? null;
        if (!is_array($condition) || empty($condition['field'])) {
            return true;
        }
        if ($depth > 25) {
            return false;
        }
        $controller = (string) $condition['field'];
        if (!isset($byKey[$controller]) || !$this->isShown($byKey[$controller], $byKey, $values, $depth + 1)) {
            return false;
        }
        $current = array_key_exists($controller, $values) ? $values[$controller] : null;
        $equals = array_key_exists('equals', $condition) ? $condition['equals'] : null;
        if (is_bool($equals)) {
            return $current === $equals;
        }

        return is_string($current) && trim($current) === (string) $equals;
    }

    /**
     * A display label for one address part.
     *
     * @param string $part
     * @return string
     */
    public function addressPartLabel(string $part): string
    {
        $labels = [
            'line1' => __('Address line 1'),
            'line2' => __('Address line 2'),
            'city' => __('City'),
            'region' => __('State / Province'),
            'postalCode' => __('Postal code'),
            'country' => __('Country'),
        ];

        return isset($labels[$part]) ? (string) $labels[$part] : $part;
    }

    /**
     * One posted value in the shape the server expects for its type, or null.
     *
     * @param string $type
     * @param array $field
     * @param mixed $input
     * @return string|bool|array|null
     */
    private function coerce(string $type, array $field, $input)
    {
        switch ($type) {
            case 'checkbox':
                return $input === null || $input === '' || $input === '0' ? null : true;

            case 'multiselect':
                if (!is_array($input)) {
                    return null;
                }
                $options = (array) ($field['options'] ?? []);
                $picked = [];
                foreach ($input as $choice) {
                    $choice = is_scalar($choice) ? trim((string) $choice) : '';
                    if (in_array($choice, $options, true) && !in_array($choice, $picked, true)) {
                        $picked[] = $choice;
                    }
                }

                return $picked === [] ? null : $picked;

            case 'select':
                if (!is_scalar($input)) {
                    return null;
                }
                $choice = trim((string) $input);
                // Only one of the offered options: anything else is "not answered".
                return in_array($choice, (array) ($field['options'] ?? []), true) ? $choice : null;

            case 'address':
                if (!is_array($input)) {
                    return null;
                }
                $address = [];
                foreach (self::ADDRESS_PARTS as $part) {
                    $value = isset($input[$part]) && is_scalar($input[$part]) ? trim((string) $input[$part]) : '';
                    if ($value !== '') {
                        $address[$part] = mb_substr($this->plain($value), 0, 200);
                    }
                }

                return $address === [] ? null : $address;

            case 'textarea':
                return is_scalar($input) ? trim(strip_tags((string) $input)) : null;

            default:
                // text, email, tel, number, date, tax_id: one trimmed line.
                return is_scalar($input) ? $this->plain((string) $input) : null;
        }
    }

    /**
     * One line of plain text: tags stripped, whitespace collapsed.
     *
     * @param string $value
     * @return string
     */
    private function plain(string $value): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', strip_tags($value)));
    }

    /**
     * Index form fields by their key.
     *
     * @param array[] $fields
     * @return array<string, array>
     */
    private function byKey(array $fields): array
    {
        $byKey = [];
        foreach ($fields as $field) {
            $byKey[(string) $field['key']] = $field;
        }

        return $byKey;
    }

    /**
     * Build a failed parse result.
     *
     * @param string $message
     * @return array{values: array, error: string}
     */
    private function fail(string $message): array
    {
        return ['values' => [], 'error' => $message];
    }
}

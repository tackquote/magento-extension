<?php
/**
 * Renders one TackQuote form field as Magento storefront HTML.
 *
 * Every field type of the seller's form (FormDefinition::TYPES), including `file`. Names
 * are `tack_sf[<key>]` (answers), `tack_sf[<key>][<part>]` (an address),
 * `tack_sf[<key>][]` (a multiselect) and `tack_file[<key>]` (a file), which is what
 * Controller\Account\WholesalePost reads back. Every label, value and attribute goes
 * through Magento's Escaper: the definition is the seller's text, and a refilled value is
 * the shopper's.
 *
 * `showIf` is carried as `data-tq-show-if-field` / `data-tq-show-if-equals` for the
 * optional script; without it every field shows, and the server (and FormDefinition) drop
 * the answers of a hidden one.
 *
 * Kept free of layout and templates so the output can be unit-tested directly.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

use Magento\Framework\Escaper;
use TackQuote\Quotes\Model\AttachmentValidator;

class FieldRenderer
{
    /** HTML input type per single-line field type. */
    private const INPUT_TYPES = [
        'text' => 'text',
        'email' => 'email',
        'tel' => 'tel',
        'number' => 'number',
        'date' => 'date',
        'tax_id' => 'text',
    ];

    /**
     * @var Escaper
     */
    private $escaper;

    /**
     * @var FormDefinition
     */
    private $definition;

    /**
     * @param Escaper $escaper
     * @param FormDefinition $definition
     */
    public function __construct(Escaper $escaper, FormDefinition $definition)
    {
        $this->escaper = $escaper;
        $this->definition = $definition;
    }

    /**
     * One field, wrapped in Magento's `field` markup.
     *
     * @param array $field A normalised field (FormDefinition::normalise()).
     * @param mixed $value A refilled answer, or null.
     * @param bool $filesAllowed Whether this form may take files now (signed in, server
     *                           accepts them). False renders a note instead of an input.
     * @param array|null $calendar The seller's calendar (FormDefinition::calendar()), or null.
     * @return string
     */
    public function render(array $field, $value = null, bool $filesAllowed = true, ?array $calendar = null): string
    {
        $key = (string) $field['key'];
        $type = (string) $field['type'];
        $id = 'tq-sf-' . preg_replace('/[^A-Za-z0-9_-]/', '', $key);
        $condition = $field['showIf'] ?? null;
        $conditional = is_array($condition) && !empty($condition['field']);
        $classes = 'field tq-field tq-field-' . $type . (!empty($field['required']) ? ' required' : '');
        // A conditional field may be hidden, and a browser refuses to submit a hidden
        // `required` input. Its requirement is enforced on submit instead
        // (FormDefinition::collectAnswers()/pickFiles(), then the server).
        if ($conditional) {
            $field['required'] = false;
        }
        $required = !empty($field['required']);

        $attrs = ' class="' . $this->escaper->escapeHtmlAttr($classes) . '"';
        if ($conditional) {
            $equals = $condition['equals'] ?? '';
            $attrs .= ' data-tq-show-if-field="' . $this->escaper->escapeHtmlAttr((string) $condition['field']) . '"'
                . ' data-tq-show-if-equals="'
                . $this->escaper->escapeHtmlAttr(is_bool($equals) ? ($equals ? 'true' : 'false') : (string) $equals)
                . '"';
        }

        $label = $this->escaper->escapeHtml((string) $field['label']);
        $help = isset($field['help']) && is_string($field['help']) && $field['help'] !== ''
            ? '<div class="note">' . $this->escaper->escapeHtml($field['help']) . '</div>'
            : '';

        if ($type === 'checkbox') {
            return '<div' . $attrs . '><div class="control">'
                . '<input type="checkbox" id="' . $id . '" name="tack_sf[' . $this->escaper->escapeHtmlAttr($key) . ']"'
                . ' value="1"' . ($value === true ? ' checked="checked"' : '') . ($required ? ' required' : '') . '/>'
                . ' <label class="label" for="' . $id . '"><span>' . $label . '</span></label>'
                . $help . '</div></div>';
        }

        if ($type === 'address' || $type === 'multiselect') {
            return '<fieldset' . $attrs . '><legend class="label"><span>' . $label . '</span></legend>'
                . '<div class="control">'
                . ($type === 'address' ? $this->address($key, $id, is_array($value) ? $value : [], $required)
                    : $this->multiselect($field, $id, is_array($value) ? $value : []))
                . $help . '</div></fieldset>';
        }

        return '<div' . $attrs . '>'
            . '<label class="label" for="' . $id . '"><span>' . $label . '</span></label>'
            . '<div class="control">' . $this->control($field, $id, $value, $filesAllowed, $calendar) . $help
            . $this->calendarHint($field, $id, $calendar) . '</div>'
            . '</div>';
    }

    /**
     * The control for a single-value field.
     *
     * @param array $field
     * @param string $id
     * @param mixed $value
     * @param bool $filesAllowed
     * @param array|null $calendar
     * @return string
     */
    private function control(array $field, string $id, $value, bool $filesAllowed, ?array $calendar = null): string
    {
        $key = $this->escaper->escapeHtmlAttr((string) $field['key']);
        $type = (string) $field['type'];
        $required = !empty($field['required']) ? ' required' : '';
        $text = is_scalar($value) ? (string) $value : '';

        switch ($type) {
            case 'textarea':
                return '<textarea id="' . $id . '" name="tack_sf[' . $key . ']" rows="4" maxlength="'
                    . FormDefinition::MAX_TEXTAREA . '"' . $required . '>'
                    . $this->escaper->escapeHtml($text) . '</textarea>';

            case 'select':
                $html = '<select id="' . $id . '" name="tack_sf[' . $key . ']"' . $required . '>'
                    . '<option value="">' . $this->escaper->escapeHtml(__('Please select')) . '</option>';
                foreach ((array) $field['options'] as $option) {
                    $html .= '<option value="' . $this->escaper->escapeHtmlAttr((string) $option) . '"'
                        . ((string) $option === $text ? ' selected="selected"' : '') . '>'
                        . $this->escaper->escapeHtml((string) $option) . '</option>';
                }

                return $html . '</select>';

            case 'file':
                if (!$filesAllowed) {
                    return '<div class="message info"><span>'
                        . $this->escaper->escapeHtml(__('Sign in to your account to attach files.'))
                        . '</span></div>';
                }

                return '<input type="file" id="' . $id . '" name="tack_file[' . $key . ']"'
                    . ' accept="' . $this->escaper->escapeHtmlAttr(implode(',', $this->accept($field))) . '"'
                    . $required . '/>'
                    . '<div class="note">' . $this->escaper->escapeHtml(__(
                        'PDF, JPEG or PNG, up to %1 MB.',
                        $this->maxMb($field)
                    )) . '</div>';

            default:
                $input = self::INPUT_TYPES[$type] ?? 'text';
                $extra = $input === 'email' ? ' autocomplete="email"' : '';
                if ($this->pastDatesRefused($field)) {
                    // "No dates in the past" means the SELLER's today, the day the server refuses
                    // earlier dates against. An older TackQuote server sends no calendar: then
                    // this store's UTC today, as before.
                    $extra .= ' min="' . $this->escaper->escapeHtmlAttr($calendar['today'] ?? gmdate('Y-m-d')) . '"';
                    if ($calendar !== null) {
                        $extra .= ' data-tq-seller-zone="' . $this->escaper->escapeHtmlAttr($calendar['timeZone']) . '"'
                            . ' data-tq-seller-today="' . $this->escaper->escapeHtmlAttr($calendar['today']) . '"';
                    }
                }

                return '<input type="' . $input . '" id="' . $id . '" name="tack_sf[' . $key . ']"'
                    . ' value="' . $this->escaper->escapeHtmlAttr($text) . '"'
                    . ' maxlength="' . FormDefinition::MAX_TEXT . '"' . $extra . $required . '/>';
        }
    }

    /**
     * Whether a field is a date field with "No dates in the past".
     *
     * @param array $field
     * @return bool
     */
    private function pastDatesRefused(array $field): bool
    {
        return ($field['type'] ?? null) === 'date' && ($field['min'] ?? null) === 'today';
    }

    /**
     * The hint under a "No dates in the past" field.
     *
     * Rendered hidden: only the browser knows the shopper's own date, so
     * `TackQuote_Quotes/js/seller-calendar` shows it when that date differs from the
     * seller's today. The same sentence as TackQuote's hosted form.
     *
     * @param array $field
     * @param string $id
     * @param array|null $calendar
     * @return string
     */
    private function calendarHint(array $field, string $id, ?array $calendar): string
    {
        if ($calendar === null || !$this->pastDatesRefused($field)) {
            return '';
        }
        $parts = explode('/', $calendar['timeZone']);
        $city = str_replace('_', ' ', (string) end($parts));
        $text = (string) __('Dates follow the seller\'s calendar (%1). Today there is %2.', $city, '%TACKDAY%');

        // The script writes the day in the shopper's language; the ISO date is the fallback.
        return '<div class="note tq-seller-calendar" id="' . $id . '-calendar" hidden="hidden">'
            . str_replace(
                '%TACKDAY%',
                '<span class="tq-seller-day">' . $this->escaper->escapeHtml($calendar['today']) . '</span>',
                $this->escaper->escapeHtml($text)
            )
            . '</div>';
    }

    /**
     * Accepted MIME types for a file field.
     *
     * The accepted MIME types for a file field: the field's list, kept to what this store
     * checks (PDF, JPEG, PNG), or all three.
     *
     * @param array $field
     * @return string[]
     */
    public function accept(array $field): array
    {
        $ours = array_values(array_unique(AttachmentValidator::MIME_BY_EXTENSION));
        $theirs = isset($field['accept']) && is_array($field['accept'])
            ? array_values(array_intersect(array_map('strval', $field['accept']), $ours))
            : [];

        return $theirs !== [] ? $theirs : $ours;
    }

    /**
     * The size cap for a file field, in MB: the field's own, never above this store's.
     *
     * @param array $field
     * @return int
     */
    public function maxMb(array $field): int
    {
        $max = isset($field['maxSizeMb']) && is_numeric($field['maxSizeMb']) ? (int) $field['maxSizeMb'] : 0;

        return $max >= 1 ? min($max, AttachmentValidator::MAX_MB) : AttachmentValidator::MAX_MB;
    }

    /**
     * Address inputs.
     *
     * @param string $key
     * @param string $id
     * @param array $value
     * @param bool $required
     * @return string
     */
    private function address(string $key, string $id, array $value, bool $required): string
    {
        $html = '';
        foreach (FormDefinition::ADDRESS_PARTS as $part) {
            $partId = $id . '-' . $part;
            $partRequired = $required && in_array($part, FormDefinition::ADDRESS_REQUIRED, true);
            $html .= '<div class="field' . ($partRequired ? ' required' : '') . '">'
                . '<label class="label" for="' . $partId . '"><span>'
                . $this->escaper->escapeHtml($this->definition->addressPartLabel($part)) . '</span></label>'
                . '<div class="control"><input type="text" id="' . $partId . '"'
                . ' name="tack_sf[' . $this->escaper->escapeHtmlAttr($key) . '][' . $part . ']"'
                . ' value="'
                . $this->escaper->escapeHtmlAttr(is_scalar($value[$part] ?? null) ? (string) $value[$part] : '')
                . '"'
                . ' maxlength="200"' . ($partRequired ? ' required' : '') . '/></div></div>';
        }

        return $html;
    }

    /**
     * Multiselect as a list of checkboxes.
     *
     * @param array $field
     * @param string $id
     * @param array $value
     * @return string
     */
    private function multiselect(array $field, string $id, array $value): string
    {
        $html = '';
        foreach ((array) $field['options'] as $i => $option) {
            $optionId = $id . '-' . $i;
            $html .= '<div class="field choice">'
                . '<input type="checkbox" id="' . $optionId . '"'
                . ' name="tack_sf[' . $this->escaper->escapeHtmlAttr((string) $field['key']) . '][]"'
                . ' value="' . $this->escaper->escapeHtmlAttr((string) $option) . '"'
                . (in_array((string) $option, array_map('strval', $value), true) ? ' checked="checked"' : '') . '/>'
                . ' <label for="' . $optionId . '"><span>' . $this->escaper->escapeHtml((string) $option)
                . '</span></label></div>';
        }

        return $html;
    }
}

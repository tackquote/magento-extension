<?php
/**
 * Validates the storefront styling fields (group `styling`) when the admin saves them.
 *
 * A value that cannot be used is refused with a message naming the field, the way
 * Model\Groups\Config\RuleMapBackend refuses a rule line it cannot read, instead of being
 * saved and silently ignored on the storefront. The WooCommerce plugin keeps the old value
 * and shows a settings error for the accent colour (class-tack-settings.php,
 * sanitize_accent_color); Magento's equivalent is an exception from beforeSave(), which
 * the config controller shows as an error and saves nothing.
 *
 * The rules themselves live in Model\Styling\StyleRules and run again on every read.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Config\Backend;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use TackQuote\Quotes\Model\Styling\StyleRules;

class StylingValue extends Value
{
    public const FIELD_ACCENT = 'accent_color';
    public const FIELD_OFFSET = 'launcher_offset';
    public const FIELD_LABEL = 'launcher_label';
    public const FIELD_CUSTOM_CSS = 'custom_css';

    /**
     * Validate the styling value before it is stored.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $parts = explode('/', (string) $this->getPath());
        $this->setValue($this->check((string) end($parts), (string) $this->getValue()));

        return parent::beforeSave();
    }

    /**
     * The value to save for a styling field, or an exception naming what is wrong.
     *
     * @param string $field The field id (last path segment).
     * @param string $value The submitted value.
     * @return string
     * @throws LocalizedException
     */
    public function check(string $field, string $value): string
    {
        $rules = new StyleRules();

        switch ($field) {
            case self::FIELD_ACCENT:
                $hex = $rules->normalizeHex($value);
                if ($hex === null) {
                    throw new LocalizedException(
                        __(
                            'The accent color must be a hex color such as #1e73be, or empty to use your '
                            . 'theme\'s colors.'
                        )
                    );
                }
                return $hex;

            case self::FIELD_OFFSET:
                if (trim($value) === '') {
                    return (string) StyleRules::OFFSET_DEFAULT;
                }
                if (!$rules->isValidOffset($value)) {
                    throw new LocalizedException(__(
                        'The launcher distance from the edges must be a whole number of pixels from %1 to %2.',
                        StyleRules::OFFSET_MIN,
                        StyleRules::OFFSET_MAX
                    ));
                }
                return (string) (int) trim($value);

            case self::FIELD_LABEL:
                return $rules->launcherLabel($value);

            case self::FIELD_CUSTOM_CSS:
                $problem = $rules->customCssProblem($value);
                if ($problem === StyleRules::CSS_HAS_LT) {
                    throw new LocalizedException(
                        __('Custom CSS cannot contain the "<" character, so it was not saved.')
                    );
                }
                if ($problem === StyleRules::CSS_TOO_LONG) {
                    throw new LocalizedException(
                        __('Custom CSS can be at most %1 characters, so it was not saved.', StyleRules::CUSTOM_CSS_MAX)
                    );
                }
                return trim($value);

            default:
                return $value;
        }
    }
}

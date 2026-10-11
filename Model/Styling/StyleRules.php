<?php
/**
 * The storefront styling settings (group `styling`): validation, and the CSS they produce.
 *
 * Parity with the WooCommerce plugin 1.10.0+ "Styling" and "Quote list & launcher" settings
 * (includes/class-tack-settings.php: sanitize_accent_color, sanitize_fab_position,
 * sanitize_fab_offset, sanitize_fab_size; includes/class-tack-widget.php: accent_css,
 * readable_text_on). Two additions the WooCommerce plugin does not have: a button style
 * (filled or outlined) and a custom CSS field.
 *
 * No Magento dependencies on purpose: the admin backend models (Model\Config\Backend\*) and
 * the storefront (ViewModel\Styling) use the same rules, and the unit tests run them
 * directly. Every value is validated again when it is READ, not only when it is saved: a
 * value written straight into core_config_data (config:set, a data patch, a database
 * import) never passes the admin form, and only the read-time check stands between it and
 * the page.
 *
 * The CSS is built from fixed text. The only values that reach it are a hex colour that
 * matched HEX_PATTERN, a whole number of pixels, and the merchant's custom CSS after
 * sanitizeCustomCss().
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Styling;

class StyleRules
{
    /** `#rgb` or `#rrggbb`, the same shapes WordPress's sanitize_hex_color() accepts. */
    public const HEX_PATTERN = '/^#(?:[0-9a-fA-F]{3}){1,2}$/';

    public const BUTTON_FILLED = 'filled';
    public const BUTTON_OUTLINED = 'outlined';

    public const POSITION_RIGHT = 'right';
    public const POSITION_LEFT = 'left';

    public const SIZE_REGULAR = 'regular';
    public const SIZE_COMPACT = 'compact';

    /** Launcher distance from the bottom and side edges, in px. 20 is the layout it always had. */
    public const OFFSET_DEFAULT = 20;
    public const OFFSET_MIN = 0;
    public const OFFSET_MAX = 200;

    /** Longest launcher label kept; a longer one is cut, so the launcher cannot cover the page. */
    public const LAUNCHER_LABEL_MAX = 40;

    /** Longest custom CSS saved or printed, in characters. */
    public const CUSTOM_CSS_MAX = 10000;

    /** customCssProblem(): the CSS contains "<". */
    public const CSS_HAS_LT = 'has_lt';
    /** customCssProblem(): the CSS is longer than CUSTOM_CSS_MAX characters. */
    public const CSS_TOO_LONG = 'too_long';

    /**
     * The module's main buttons. Each is matched as `.action<selector>` so the rule only ever
     * reaches this module's own controls.
     */
    private const PRIMARY = [
        // "Add to Quote" is primary only when it is the product page's sole quote control.
        '.primary.tackquote-add-btn',
        '.tackquote-request-btn.tocart',
        '.tackquote-widget__toggle',
        '.tackquote-drawer__submit',
        '.tackquote-submit-btn',
        '.tackquote-next',
        '.tackquote-quote-only-notice__cta',
        '[data-role="tackquote-page-submit"]',
    ];

    /** The module's second-rank buttons and links. */
    private const SECONDARY = [
        '.secondary.tackquote-add-btn',
        '.tackquote-request-btn.secondary',
        '.tackquote-cart-quote__button',
        '.tackquote-back',
        '.tackquote-drawer__page-link',
    ];

    /**
     * A hex colour as saved: '' (use the theme's colours), the colour in lower case, or null
     * when the value is not a hex colour.
     *
     * @param string $value
     * @return string|null
     */
    public function normalizeHex(string $value): ?string
    {
        $value = trim($value);
        if ($value === '') {
            return '';
        }

        return preg_match(self::HEX_PATTERN, $value) === 1 ? strtolower($value) : null;
    }

    /**
     * Whether a submitted launcher offset is a whole number of pixels in range.
     *
     * @param string $value
     * @return bool
     */
    public function isValidOffset(string $value): bool
    {
        $value = trim($value);
        if (preg_match('/^\d{1,3}$/', $value) !== 1) {
            return false;
        }
        $number = (int) $value;

        return $number >= self::OFFSET_MIN && $number <= self::OFFSET_MAX;
    }

    /**
     * The launcher offset to use: the saved value when valid, the default otherwise.
     *
     * @param mixed $value
     * @return int
     */
    public function offset($value): int
    {
        if (is_int($value)) {
            $value = (string) $value;
        }

        return is_string($value) && $this->isValidOffset($value) ? (int) trim($value) : self::OFFSET_DEFAULT;
    }

    /**
     * One of the allowed values, or the default.
     *
     * @param mixed $value
     * @param string[] $allowed
     * @param string $default
     * @return string
     */
    public function oneOf($value, array $allowed, string $default): string
    {
        return is_string($value) && in_array($value, $allowed, true) ? $value : $default;
    }

    /**
     * Why a custom CSS value cannot be saved (CSS_HAS_LT or CSS_TOO_LONG), or null when it can.
     *
     * Refused rather than quietly changed at save time, so the merchant sees what will not
     * be printed. sanitizeCustomCss() still runs on every read.
     *
     * @param string $css
     * @return string|null
     */
    public function customCssProblem(string $css): ?string
    {
        if (strpos($css, '<') !== false) {
            return self::CSS_HAS_LT;
        }
        if (mb_strlen($css, 'UTF-8') > self::CUSTOM_CSS_MAX) {
            return self::CSS_TOO_LONG;
        }

        return null;
    }

    /**
     * The custom CSS as it is printed inside a <style> element.
     *
     * Inside <style> the HTML parser only leaves the element at "</style", so a "<" is the one
     * character that can end it early (or open a comment or another tag). Every "<" is
     * removed, which CSS never needs outside a quoted string. NUL bytes and invalid UTF-8 are
     * dropped, and the result is cut to CUSTOM_CSS_MAX characters.
     *
     * @param string $css
     * @return string
     */
    public function sanitizeCustomCss(string $css): string
    {
        if (!mb_check_encoding($css, 'UTF-8')) {
            $css = mb_convert_encoding($css, 'UTF-8', 'UTF-8');
        }
        $css = str_replace(["\0", '<'], '', $css);
        $css = mb_substr($css, 0, self::CUSTOM_CSS_MAX, 'UTF-8');

        return trim($css);
    }

    /**
     * The launcher label as printed: trimmed, control characters removed, at most
     * LAUNCHER_LABEL_MAX characters. '' means "use the default label".
     *
     * @param string $label
     * @return string
     */
    public function launcherLabel(string $label): string
    {
        $label = (string) preg_replace('/[\x00-\x1F\x7F]+/u', ' ', $label);

        return trim(mb_substr(trim($label), 0, self::LAUNCHER_LABEL_MAX, 'UTF-8'));
    }

    /**
     * Black or white, whichever has the higher WCAG 2 contrast ratio on the colour.
     *
     * Same formula as the WooCommerce plugin's Tack_Widget::readable_text_on().
     *
     * @param string $hex `#rgb` or `#rrggbb`.
     * @return string `#000` or `#fff`.
     */
    public function readableTextOn(string $hex): string
    {
        $h = ltrim($hex, '#');
        if (strlen($h) === 3) {
            $h = $h[0] . $h[0] . $h[1] . $h[1] . $h[2] . $h[2];
        }
        $lin = static function (int $channel): float {
            $c = $channel / 255;

            return $c <= 0.03928 ? $c / 12.92 : (($c + 0.055) / 1.055) ** 2.4;
        };
        $luminance = 0.2126 * $lin((int) hexdec(substr($h, 0, 2)))
            + 0.7152 * $lin((int) hexdec(substr($h, 2, 2)))
            + 0.0722 * $lin((int) hexdec(substr($h, 4, 2)));

        // Contrast with white is 1.05 / (L + 0.05); with black (L + 0.05) / 0.05.
        return (1.05 / ($luminance + 0.05)) >= (($luminance + 0.05) / 0.05) ? '#fff' : '#000';
    }

    /**
     * The storefront CSS for these settings, or '' when every setting is at its default.
     *
     * @param string $accent A saved accent colour (validated again here).
     * @param string $buttonStyle filled|outlined (anything else is filled).
     * @param mixed $offset Launcher offset in px (anything invalid is the default).
     * @param string $customCss The merchant's custom CSS (sanitised here).
     * @return string
     */
    public function buildCss(string $accent, string $buttonStyle, $offset, string $customCss): string
    {
        $hex = $this->normalizeHex($accent);
        $hex = $hex === null ? '' : $hex;
        $style = $this->oneOf($buttonStyle, [self::BUTTON_FILLED, self::BUTTON_OUTLINED], self::BUTTON_FILLED);
        $px = $this->offset($offset);

        $vars = [];
        if ($px !== self::OFFSET_DEFAULT) {
            $vars[] = '--tackquote-launcher-offset:' . $px . 'px';
        }
        if ($hex !== '') {
            $vars[] = '--tackquote-accent:' . $hex;
            $vars[] = '--tackquote-accent-text:' . $this->readableTextOn($hex);
        }

        $css = $vars !== [] ? ':root{' . implode(';', $vars) . '}' : '';

        if ($style === self::BUTTON_OUTLINED) {
            // Without an accent the outline takes the button's own text colour.
            $css .= $this->rule(self::PRIMARY, '')
                . '{background:transparent;border:1px solid var(--tackquote-accent,currentColor);'
                . 'color:var(--tackquote-accent,inherit)}'
                . $this->rule(self::PRIMARY, ':hover')
                . '{background:color-mix(in srgb,var(--tackquote-accent,currentColor) 10%,transparent);'
                . 'color:var(--tackquote-accent,inherit)}';
        } elseif ($hex !== '') {
            $css .= $this->rule(self::PRIMARY, '')
                . '{background:var(--tackquote-accent);border-color:var(--tackquote-accent);'
                . 'color:var(--tackquote-accent-text)}'
                . $this->rule(self::PRIMARY, ':hover')
                . '{background:color-mix(in srgb,var(--tackquote-accent) 85%,var(--tackquote-accent-text));'
                . 'border-color:var(--tackquote-accent);color:var(--tackquote-accent-text)}';
        }

        if ($hex !== '') {
            $css .= $this->rule(self::SECONDARY, '')
                . '{background:transparent;border:1px solid var(--tackquote-accent);color:var(--tackquote-accent)}'
                . $this->rule(self::SECONDARY, ':hover')
                . '{background:color-mix(in srgb,var(--tackquote-accent) 10%,transparent);'
                . 'color:var(--tackquote-accent)}'
                . '.tackquote-b2b .tackquote-b2b__account-price{color:var(--tackquote-accent)}'
                . '.tackquote-b2b .tackquote-b2b__group-name{border-color:var(--tackquote-accent);'
                . 'color:var(--tackquote-accent)}';
        }

        $custom = $this->sanitizeCustomCss($customCss);
        if ($custom !== '') {
            $css .= ($css !== '' ? "\n" : '') . $custom;
        }

        return $css;
    }

    /**
     * One selector list for the given controls.
     *
     * `html:root body .action` outranks Luma's `.action.primary:hover` (0,3,0) without
     * `!important`, which would also beat the merchant's own custom CSS.
     *
     * @param string[] $controls
     * @param string $state A pseudo-class such as ':hover', or ''.
     * @return string
     */
    private function rule(array $controls, string $state): string
    {
        $out = [];
        foreach ($controls as $control) {
            $out[] = 'html:root body .action' . $control . $state;
        }

        return implode(',', $out);
    }
}

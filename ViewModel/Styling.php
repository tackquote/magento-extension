<?php
/**
 * The storefront styling settings (Stores > Configuration > TackQuote, group `styling`),
 * read and validated for the templates.
 *
 * Passed to templates through layout XML (`view/frontend/layout/default.xml`) rather than
 * injected into Block\QuoteList, so the blocks' constructors stay as they are. The admin
 * Overview reads the same values through Block\Adminhtml\Dashboard.
 *
 * Every value goes through Model\Styling\StyleRules on the way out, so a value written
 * around the admin form (config:set, a database import) is validated too.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\View\Element\Block\ArgumentInterface;
use Magento\Store\Model\ScopeInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Styling\StyleRules;

class Styling implements ArgumentInterface
{
    public const XML_PATH_ACCENT = 'tackquote_quotes/styling/accent_color';
    public const XML_PATH_BUTTON_STYLE = 'tackquote_quotes/styling/button_style';
    public const XML_PATH_LAUNCHER_POSITION = 'tackquote_quotes/styling/launcher_position';
    public const XML_PATH_LAUNCHER_OFFSET = 'tackquote_quotes/styling/launcher_offset';
    public const XML_PATH_LAUNCHER_LABEL = 'tackquote_quotes/styling/launcher_label';
    public const XML_PATH_LAUNCHER_SIZE = 'tackquote_quotes/styling/launcher_size';
    public const XML_PATH_CUSTOM_CSS = 'tackquote_quotes/styling/custom_css';

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var StyleRules
     */
    private $rules;

    /**
     * @param ScopeConfigInterface $scopeConfig
     * @param Config $config
     * @param StyleRules $rules
     */
    public function __construct(ScopeConfigInterface $scopeConfig, Config $config, StyleRules $rules)
    {
        $this->scopeConfig = $scopeConfig;
        $this->config = $config;
        $this->rules = $rules;
    }

    /**
     * The accent colour (`#rrggbb` or `#rgb`, lower case), or '' for the theme's colours.
     *
     * @return string
     */
    public function getAccentColor(): string
    {
        return (string) $this->rules->normalizeHex($this->read(self::XML_PATH_ACCENT));
    }

    /**
     * `filled` or `outlined`.
     *
     * @return string
     */
    public function getButtonStyle(): string
    {
        return $this->rules->oneOf(
            $this->read(self::XML_PATH_BUTTON_STYLE),
            [StyleRules::BUTTON_FILLED, StyleRules::BUTTON_OUTLINED],
            StyleRules::BUTTON_FILLED
        );
    }

    /**
     * `right` or `left`.
     *
     * @return string
     */
    public function getLauncherPosition(): string
    {
        return $this->rules->oneOf(
            $this->read(self::XML_PATH_LAUNCHER_POSITION),
            [StyleRules::POSITION_RIGHT, StyleRules::POSITION_LEFT],
            StyleRules::POSITION_RIGHT
        );
    }

    /**
     * Launcher distance from the bottom and side edges, in px.
     *
     * @return int
     */
    public function getLauncherOffset(): int
    {
        return $this->rules->offset($this->read(self::XML_PATH_LAUNCHER_OFFSET));
    }

    /**
     * `regular` or `compact`.
     *
     * @return string
     */
    public function getLauncherSize(): string
    {
        return $this->rules->oneOf(
            $this->read(self::XML_PATH_LAUNCHER_SIZE),
            [StyleRules::SIZE_REGULAR, StyleRules::SIZE_COMPACT],
            StyleRules::SIZE_REGULAR
        );
    }

    /**
     * The launcher's label: the merchant's, or "Quote list".
     *
     * @return string
     */
    public function getLauncherLabel(): string
    {
        $label = $this->rules->launcherLabel($this->read(self::XML_PATH_LAUNCHER_LABEL));

        return $label !== '' ? $label : (string) __('Quote list');
    }

    /**
     * Whether the merchant saved custom CSS that will be printed.
     *
     * @return bool
     */
    public function hasCustomCss(): bool
    {
        return $this->rules->sanitizeCustomCss($this->read(self::XML_PATH_CUSTOM_CSS)) !== '';
    }

    /**
     * Modifier classes for the quote list wrapper (launcher side and size).
     *
     * @return string Space-separated class names from a fixed set.
     */
    public function getLauncherClasses(): string
    {
        $classes = [];
        if ($this->getLauncherPosition() === StyleRules::POSITION_LEFT) {
            $classes[] = 'tackquote-app--launcher-left';
        }
        if ($this->getLauncherSize() === StyleRules::SIZE_COMPACT) {
            $classes[] = 'tackquote-app--launcher-compact';
        }

        return implode(' ', $classes);
    }

    /**
     * The CSS to print in the page head, or '' when nothing differs from the defaults or no
     * TackQuote control can render (module off or no API key).
     *
     * @return string
     */
    public function getInlineCss(): string
    {
        if (!$this->config->isConfigured()) {
            return '';
        }

        return $this->rules->buildCss(
            $this->read(self::XML_PATH_ACCENT),
            $this->read(self::XML_PATH_BUTTON_STYLE),
            $this->read(self::XML_PATH_LAUNCHER_OFFSET),
            $this->read(self::XML_PATH_CUSTOM_CSS)
        );
    }

    /**
     * A styling value for the current store, as a string.
     *
     * @param string $path
     * @return string
     */
    private function read(string $path): string
    {
        $value = $this->scopeConfig->getValue($path, ScopeInterface::SCOPE_STORE);

        return is_scalar($value) ? (string) $value : '';
    }
}

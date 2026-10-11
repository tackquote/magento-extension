<?php
/**
 * Quote list launcher size (Styling group).
 *
 * The option values are the Model\Styling\StyleRules constants, which the storefront reads
 * back through StyleRules::oneOf(), so the dropdown and the storefront cannot disagree.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Config\Source;

use Magento\Framework\Data\OptionSourceInterface;
use TackQuote\Quotes\Model\Styling\StyleRules;

class LauncherSize implements OptionSourceInterface
{
    /**
     * Launcher size options for the configuration field.
     *
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => StyleRules::SIZE_REGULAR, 'label' => __('Regular')],
            ['value' => StyleRules::SIZE_COMPACT, 'label' => __('Compact')],
        ];
    }
}

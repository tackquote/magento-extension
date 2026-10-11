<?php
/**
 * Storefront button style (Styling group).
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

class ButtonStyle implements OptionSourceInterface
{
    /**
     * @return array<int, array{value: string, label: \Magento\Framework\Phrase}>
     */
    public function toOptionArray(): array
    {
        return [
            ['value' => StyleRules::BUTTON_FILLED, 'label' => __('Filled (solid background)')],
            ['value' => StyleRules::BUTTON_OUTLINED, 'label' => __('Outlined (border only)')],
        ];
    }
}

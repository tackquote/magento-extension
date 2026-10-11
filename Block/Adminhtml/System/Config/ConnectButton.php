<?php
/**
 * "Connect with TackQuote" beside the API key field in Stores > Configuration >
 * TackQuote: the compact ConnectCard.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Adminhtml\System\Config;

use Magento\Config\Block\System\Config\Form\Field;
use Magento\Framework\Data\Form\Element\AbstractElement;
use TackQuote\Quotes\Block\Adminhtml\ConnectCard;

class ConnectButton extends Field
{
    /**
     * The scope switcher and "Use system value" checkbox make no sense for a button.
     *
     * @param AbstractElement $element
     * @return string
     */
    public function render(AbstractElement $element): string
    {
        $element->unsScope()->unsCanUseWebsiteValue()->unsCanUseDefaultValue();

        return parent::render($element);
    }

    /**
     * @param AbstractElement $element
     * @return string
     */
    protected function _getElementHtml(AbstractElement $element): string
    {
        return (string) $this->getLayout()
            ->createBlock(ConnectCard::class, 'tackquote.config.connect', ['data' => ['compact' => true]])
            ->toHtml();
    }
}

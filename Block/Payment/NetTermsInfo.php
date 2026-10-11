<?php
/**
 * The order view's payment block for "Pay on net terms": the terms and the PO number.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Payment;

use Magento\Payment\Block\Info;
use TackQuote\Quotes\Model\Payment\NetTermsMethod;

class NetTermsInfo extends Info
{
    /**
     * @inheritdoc
     */
    protected function _prepareSpecificInformation($transport = null)
    {
        $transport = parent::_prepareSpecificInformation($transport);
        $info = $this->getInfo();
        $data = [];
        $terms = (string) $info->getAdditionalInformation(NetTermsMethod::INFO_TERMS);
        if ($terms !== '') {
            $data[(string) __('Payment terms')] = $terms;
        }
        $po = NetTermsMethod::cleanPoNumber(
            $info->getData('po_number') ?: $info->getAdditionalInformation(NetTermsMethod::INFO_PO_NUMBER)
        );
        if ($po !== '') {
            $data[(string) __('Purchase order number')] = $po;
        }

        return $transport->setData(array_merge($transport->getData(), $data));
    }
}

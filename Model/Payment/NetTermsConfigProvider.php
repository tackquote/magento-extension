<?php
/**
 * What the checkout's "Pay on net terms" renderer needs: whether to ask for a PO number, and
 * the method's description. Store configuration only, the same for every shopper; whether the
 * method is offered at all is NetTermsMethod::isAvailable(), decided per quote.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Payment;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\Config;

class NetTermsConfigProvider implements ConfigProviderInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ScopeConfigInterface
     */
    private $scopeConfig;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Config $config
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Config $config,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager
    ) {
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritdoc
     */
    public function getConfig()
    {
        $storeId = (int) $this->storeManager->getStore()->getId();

        return [
            'payment' => [
                NetTermsMethod::CODE => [
                    'poNumber' => $this->config->isNetTermsPoNumberEnabled($storeId),
                    'poMaxLength' => NetTermsMethod::PO_MAX_LENGTH,
                    'description' => (string) $this->scopeConfig->getValue(
                        'payment/' . NetTermsMethod::CODE . '/description',
                        ScopeInterface::SCOPE_STORE,
                        $storeId
                    ),
                ],
            ],
        ];
    }
}

<?php
/**
 * What the checkout's "Pay on net terms" renderer needs: whether to ask for a PO number, the
 * method's description, and the buyer's own terms ("Net 30 terms. $5,000.00 available.").
 *
 * Whether the method is offered at all is NetTermsMethod::isAvailable(), decided per quote by
 * the server. The terms line is read through NetTermsBuyer, the SAME identity and standing path
 * the method decides with (and the same 60-second standing cache, so the checkout page makes no
 * second call), for the checkout session's own quote. It is a description only: nothing here
 * offers or refuses the method, and any failure leaves the line out rather than breaking the
 * checkout page.
 *
 * Registered in etc/frontend/di.xml (see the comment there for why not the global file).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Payment;

use Magento\Checkout\Model\ConfigProviderInterface;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Store\Model\ScopeInterface;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
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
     * @var CheckoutSession
     */
    private $checkoutSession;

    /**
     * @var NetTermsBuyer
     */
    private $buyer;

    /**
     * @var NetTermsEligibility
     */
    private $eligibility;

    /**
     * @var PriceCurrencyInterface
     */
    private $priceCurrency;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Config $config
     * @param ScopeConfigInterface $scopeConfig
     * @param StoreManagerInterface $storeManager
     * @param CheckoutSession $checkoutSession
     * @param NetTermsBuyer $buyer
     * @param NetTermsEligibility $eligibility
     * @param PriceCurrencyInterface $priceCurrency
     * @param LoggerInterface $logger
     */
    public function __construct(
        Config $config,
        ScopeConfigInterface $scopeConfig,
        StoreManagerInterface $storeManager,
        CheckoutSession $checkoutSession,
        NetTermsBuyer $buyer,
        NetTermsEligibility $eligibility,
        PriceCurrencyInterface $priceCurrency,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->scopeConfig = $scopeConfig;
        $this->storeManager = $storeManager;
        $this->checkoutSession = $checkoutSession;
        $this->buyer = $buyer;
        $this->eligibility = $eligibility;
        $this->priceCurrency = $priceCurrency;
        $this->logger = $logger;
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
                    'terms' => $this->termsSummary($storeId),
                ],
            ],
        ];
    }

    /**
     * The checkout buyer's terms line, or '' when there is nothing true to say.
     *
     * For example "Net 30 terms. $5,000.00 available.". Only an ACTIVE line
     * (NetTermsEligibility::view()) in the quote's own currency is described; the amount is
     * formatted by Magento's price formatter in that currency with the store's locale rules.
     * The available part is left out when the server did not send `available`.
     *
     * @param int $storeId
     * @return string
     */
    private function termsSummary(int $storeId): string
    {
        if (!$this->scopeConfig->isSetFlag(
            'payment/' . NetTermsMethod::CODE . '/active',
            ScopeInterface::SCOPE_STORE,
            $storeId
        )) {
            return '';
        }
        try {
            $subject = $this->buyer->subjectOf($this->checkoutSession->getQuote(), 'quote_currency_code');
            if ($subject === null) {
                return '';
            }
            $view = $this->eligibility->view($this->buyer->standingOf($subject, false)['answer']);
            if ($view['state'] !== 'active' || $view['currency'] !== strtoupper(trim($subject['currency']))) {
                return '';
            }

            $summary = (string) __('Net %1 terms.', $view['termsDays']);
            if ($view['available'] !== '') {
                $summary .= ' ' . (string) __(
                    '%1 available.',
                    $this->priceCurrency->format(
                        (float) $view['available'],
                        false,
                        PriceCurrencyInterface::DEFAULT_PRECISION,
                        $subject['storeId'] ?? $storeId,
                        $view['currency']
                    )
                );
            }

            return $summary;
        } catch (\Throwable $e) {
            $this->logger->warning('TackQuote net-terms checkout summary unavailable; left out.');

            return '';
        }
    }
}

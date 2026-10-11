<?php
/**
 * "Pay on net terms": an offline payment method for buyers TackQuote has approved for terms.
 *
 * Built on Magento's payment facade, `Magento\Payment\Model\Method\Adapter` (Adobe Commerce
 * "Payment Provider Gateway" / facade configuration), configured in etc/di.xml and
 * etc/config.xml (`payment/tackquote_net_terms`: offline, no payment action, so Magento
 * places the order in state `new` with the configured `order_status`, `pending`).
 *
 * WHY A SUBCLASS AND NOT AN `availability` VALIDATOR. Adapter::isAvailable() runs the
 * `availability` validator only when an info instance is already set, and
 * Magento\Payment\Model\MethodList::getAvailableMethods() calls isAvailable($quote) BEFORE it
 * sets one; and any exception the validator throws is swallowed with the method left
 * AVAILABLE. Both fail open. (Read in magento2 2.4-develop: Payment/Model/Method/Adapter.php
 * isAvailable(), Payment/Model/MethodList.php getAvailableMethods().) This override decides
 * from the QUOTE and turns every failure into "not available".
 *
 * WHO. The buyer is the quote's or order's own registered customer (id + the account email
 * Magento keeps on it),
 * never the customer session and never anything posted: the method list is built per quote
 * by AJAX/REST, outside the full-page cache, and the session plays no part.
 *
 * WHEN. Offered when TackQuote's `net-terms` answer allows this total in this currency
 * (NetTermsEligibility, the WooCommerce gateway's rules). Re-checked with a FRESH read when
 * the order is placed (validate() on the order payment), because the standing can change
 * between the checkout page and the click, and TackQuote also re-checks at the order pull.
 *
 * RECORDED. On success the payment carries `additional_information`:
 * `tackquote_payment_terms` = "Net N" and `tackquote_terms_days` = "N", and the optional PO
 * number on the payment's own `po_number` (assignData()). tack's Magento order pull maps both
 * (tack integrations/platform/order-inbound-mapping.ts magentoNetTermsDays()).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Payment;

use Magento\Framework\DataObject;
use Magento\Framework\Event\ManagerInterface;
use Magento\Framework\Exception\LocalizedException;
use Magento\Payment\Gateway\Command\CommandManagerInterface;
use Magento\Payment\Gateway\Command\CommandPoolInterface;
use Magento\Payment\Gateway\Config\ValueHandlerPoolInterface;
use Magento\Payment\Gateway\Data\PaymentDataObjectFactory;
use Magento\Payment\Gateway\Validator\ValidatorPoolInterface;
use Magento\Payment\Model\Method\Adapter;
use Magento\Quote\Api\Data\CartInterface;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\IdentityResolver;

class NetTermsMethod extends Adapter
{
    public const CODE = 'tackquote_net_terms';

    /** `additional_information` keys this method writes. */
    public const INFO_TERMS = 'tackquote_payment_terms';
    public const INFO_TERMS_DAYS = 'tackquote_terms_days';
    public const INFO_PO_NUMBER = 'tackquote_po_number';

    /** The longest PO number kept (the WooCommerce plugin's cap). */
    public const PO_MAX_LENGTH = 64;

    /**
     * @var IdentityResolver
     */
    private $identities;

    /**
     * @var NetTermsStanding
     */
    private $standing;

    /**
     * @var NetTermsEligibility
     */
    private $eligibility;

    /**
     * @var Config
     */
    private $moduleConfig;

    /**
     * @var LoggerInterface
     */
    private $netTermsLogger;

    /**
     * @param ManagerInterface $eventManager
     * @param ValueHandlerPoolInterface $valueHandlerPool
     * @param PaymentDataObjectFactory $paymentDataObjectFactory
     * @param IdentityResolver $identities
     * @param NetTermsStanding $standing
     * @param NetTermsEligibility $eligibility
     * @param Config $moduleConfig
     * @param LoggerInterface $netTermsLogger
     * @param string $code
     * @param string $formBlockType
     * @param string $infoBlockType
     * @param CommandPoolInterface|null $commandPool
     * @param ValidatorPoolInterface|null $validatorPool
     * @param CommandManagerInterface|null $commandExecutor
     * @param LoggerInterface|null $logger
     */
    public function __construct(
        ManagerInterface $eventManager,
        ValueHandlerPoolInterface $valueHandlerPool,
        PaymentDataObjectFactory $paymentDataObjectFactory,
        IdentityResolver $identities,
        NetTermsStanding $standing,
        NetTermsEligibility $eligibility,
        Config $moduleConfig,
        LoggerInterface $netTermsLogger,
        $code,
        $formBlockType,
        $infoBlockType,
        ?CommandPoolInterface $commandPool = null,
        ?ValidatorPoolInterface $validatorPool = null,
        ?CommandManagerInterface $commandExecutor = null,
        ?LoggerInterface $logger = null
    ) {
        parent::__construct(
            $eventManager,
            $valueHandlerPool,
            $paymentDataObjectFactory,
            $code,
            $formBlockType,
            $infoBlockType,
            $commandPool,
            $validatorPool,
            $commandExecutor,
            $logger
        );
        $this->identities = $identities;
        $this->standing = $standing;
        $this->eligibility = $eligibility;
        $this->moduleConfig = $moduleConfig;
        $this->netTermsLogger = $netTermsLogger;
    }

    /**
     * Offered only for a quote whose registered customer TackQuote allows terms for.
     *
     * @param CartInterface|null $quote
     * @return bool
     */
    public function isAvailable(?CartInterface $quote = null)
    {
        if ($quote === null) {
            return false;
        }
        try {
            if (!parent::isAvailable($quote)) {
                return false;
            }
            $subject = $this->subjectOfQuote($quote);

            return $subject !== null && $this->decide($subject, false)['eligible'];
        } catch (\Throwable $e) {
            $this->log('net-terms availability check failed; method hidden.');

            return false;
        }
    }

    /**
     * Copies the optional PO number onto the payment.
     *
     * `po_number` is a top-level PaymentInterface field (Quote\Payment::importData() keeps
     * it out of additional_data). It is also kept in additional_information, which Magento
     * copies from the quote payment to the order payment.
     *
     * @param DataObject $data
     * @return $this
     */
    public function assignData(DataObject $data)
    {
        parent::assignData($data);
        $info = $this->getInfoInstance();
        if ($info === null) {
            return $this;
        }
        $po = '';
        if ($this->moduleConfig->isNetTermsPoNumberEnabled($this->storeIdOf($info))) {
            $raw = $data->getData('po_number');
            if ($raw === null) {
                $additional = $data->getData('additional_data');
                $raw = is_array($additional) ? ($additional['po_number'] ?? null) : null;
            }
            $po = self::cleanPoNumber($raw);
        }
        $info->setData('po_number', $po !== '' ? $po : null);
        $info->setAdditionalInformation(self::INFO_PO_NUMBER, $po);

        return $this;
    }

    /**
     * Refuses the payment unless TackQuote still allows terms for it, and records the terms.
     *
     * Called by Magento on the quote payment (importData) and on the order payment
     * (Order\Payment::place()); the order payment is checked with a FRESH read.
     *
     * @return $this
     * @throws LocalizedException
     */
    public function validate()
    {
        parent::validate();
        $info = $this->getInfoInstance();
        $order = $info !== null && method_exists($info, 'getOrder') ? $info->getOrder() : null;
        $subject = $order ? $this->subjectOfOrder($order) : null;
        $fresh = $subject !== null;
        if ($subject === null) {
            $quote = $info !== null && method_exists($info, 'getQuote') ? $info->getQuote() : null;
            $subject = $quote ? $this->subjectOfQuote($quote) : null;
        }
        if ($subject === null) {
            throw new LocalizedException($this->refusal('not_signed_in'));
        }

        $decision = $this->decide($subject, $fresh);
        if (!$decision['eligible']) {
            $this->log('net-terms payment refused: ' . $decision['reason']);
            throw new LocalizedException($this->refusal($decision['reason']));
        }

        $days = (int) $decision['termsDays'];
        $info->setAdditionalInformation(self::INFO_TERMS, 'Net ' . $days);
        $info->setAdditionalInformation(self::INFO_TERMS_DAYS, (string) $days);
        // The order payment may arrive without po_number if the quote-to-order copy did
        // not carry it; additional_information is copied, so restore it from there.
        $po = self::cleanPoNumber($info->getAdditionalInformation(self::INFO_PO_NUMBER));
        if ($po !== '' && (string) $info->getData('po_number') === '') {
            $info->setData('po_number', $po);
        }

        return $this;
    }

    // phpcs:disable Magento2.Functions.StaticFunction -- pure sanitiser, also used by Block\Payment\NetTermsInfo
    /**
     * A PO number as stored: plain text, one line, at most PO_MAX_LENGTH characters.
     *
     * @param mixed $raw
     * @return string
     */
    public static function cleanPoNumber($raw): string
    {
        if (!is_scalar($raw)) {
            return '';
        }
        $text = trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $raw)));

        return mb_substr($text, 0, self::PO_MAX_LENGTH);
    }
    // phpcs:enable Magento2.Functions.StaticFunction

    /**
     * The verdict for one subject.
     *
     * @param array{storeId:int|null,customerId:int,email:string,amount:mixed,currency:string} $subject
     * @param bool $fresh
     * @return array{eligible: bool, reason: string, termsDays: int}
     */
    private function decide(array $subject, bool $fresh): array
    {
        $who = $this->identities->forCustomer($subject['customerId'], $subject['email'], $subject['storeId']);
        if (!$who->carriesCustomerId()) {
            // Credit is only ever read for the customer TackQuote can tie to a buyer by a
            // seller-made link; an email alone never spends a credit line.
            return ['eligible' => false, 'reason' => 'customer_not_identified', 'termsDays' => 0];
        }
        $answer = $this->standing->read($who, $subject['storeId'], $fresh);
        if ($answer === null) {
            return ['eligible' => false, 'reason' => 'lookup_failed', 'termsDays' => 0];
        }

        return $this->eligibility->evaluate($answer, $subject['amount'], $subject['currency']);
    }

    /**
     * The registered customer and the total of a quote, or null for a guest.
     *
     * @param mixed $quote
     * @return array|null
     */
    private function subjectOfQuote($quote): ?array
    {
        return $this->subjectOf($quote, 'quote_currency_code');
    }

    /**
     * The registered customer and the total of an order, or null for a guest.
     *
     * @param mixed $order
     * @return array|null
     */
    private function subjectOfOrder($order): ?array
    {
        return $this->subjectOf($order, 'order_currency_code');
    }

    /**
     * The registered customer and the total of a quote or an order, from its own data.
     *
     * Read with getData() on the stored fields (`customer_id`, `customer_is_guest`,
     * `customer_email`, `store_id`, `grand_total`, the currency code), which both
     * Magento\Quote\Model\Quote and Magento\Sales\Model\Order carry. A quote's
     * `customer_email` for a signed-in customer is the account email Magento copied onto it.
     *
     * @param mixed $owner
     * @param string $currencyKey
     * @return array|null Null for a guest or a quote/order with no customer.
     */
    public function subjectOf($owner, string $currencyKey): ?array
    {
        if (!$owner instanceof DataObject) {
            return null;
        }
        $customerId = (int) $owner->getData('customer_id');
        if ($customerId <= 0 || (bool) $owner->getData('customer_is_guest')) {
            return null;
        }
        $storeId = $owner->getData('store_id');

        return [
            'storeId' => $storeId !== null ? (int) $storeId : null,
            'customerId' => $customerId,
            'email' => (string) $owner->getData('customer_email'),
            'amount' => $owner->getData('grand_total'),
            'currency' => (string) $owner->getData($currencyKey),
        ];
    }

    /**
     * The store of the payment's quote or order.
     *
     * @param mixed $info
     * @return int|null
     */
    private function storeIdOf($info): ?int
    {
        foreach (['getOrder', 'getQuote'] as $getter) {
            $owner = method_exists($info, $getter) ? $info->{$getter}() : null;
            if ($owner instanceof DataObject && $owner->getData('store_id') !== null) {
                return (int) $owner->getData('store_id');
            }
        }

        return null;
    }

    /**
     * What the buyer reads when the method refuses.
     *
     * @param string $reason
     * @return \Magento\Framework\Phrase
     */
    private function refusal(string $reason): \Magento\Framework\Phrase
    {
        return in_array($reason, ['over_limit', 'insufficient_available_credit'], true)
            ? __(
                'This order is more than the net-terms credit you have available. Please choose another payment '
                . 'method or contact us.'
            )
            : __(
                'Net terms are not available for this order right now. Please choose another payment method or '
                . 'contact us.'
            );
    }

    /**
     * Log a reason code only: never an email, an amount or a TackQuote answer.
     *
     * @param string $message
     * @return void
     */
    private function log(string $message): void
    {
        $this->netTermsLogger->warning('TackQuote ' . $message);
    }
}

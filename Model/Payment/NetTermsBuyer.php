<?php
/**
 * Who a quote or order belongs to, and that customer's TackQuote net-terms standing.
 *
 * The ONE place the "Pay on net terms" method (NetTermsMethod: is it offered, may this order
 * go on terms?) and the checkout panel (NetTermsConfigProvider: "Net 30 terms. $5,000.00
 * available.") read the standing from, so the panel can never describe terms the method would
 * refuse, or the other way round.
 *
 * WHO. The quote's or order's own registered customer (id + the account email Magento keeps on
 * it), never the customer session and never anything posted. Credit is read only for a customer
 * TackQuote can tie to a buyer by a seller-made link (AccountIdentity::carriesCustomerId()); an
 * email alone never reads a credit line.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Payment;

use Magento\Framework\DataObject;
use TackQuote\Quotes\Model\Forms\IdentityResolver;

class NetTermsBuyer
{
    /**
     * @var IdentityResolver
     */
    private $identities;

    /**
     * @var NetTermsStanding
     */
    private $standing;

    /**
     * @param IdentityResolver $identities
     * @param NetTermsStanding $standing
     */
    public function __construct(IdentityResolver $identities, NetTermsStanding $standing)
    {
        $this->identities = $identities;
        $this->standing = $standing;
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
     * @param string $currencyKey `quote_currency_code` or `order_currency_code`.
     * @return array{storeId:int|null,customerId:int,email:string,amount:mixed,currency:string}|null
     *         Null for a guest or a quote/order with no customer.
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
     * The subject's standing.
     *
     * `identified` false: TackQuote cannot tie this customer to a buyer (no capability, no
     * install id, no email); nothing was read. `answer` null with `identified` true: the read
     * failed. Callers fail closed on both.
     *
     * @param array{storeId:int|null,customerId:int,email:string} $subject
     * @param bool $fresh Bypass the standing caches (an order being placed).
     * @return array{identified: bool, answer: array|null}
     */
    public function standingOf(array $subject, bool $fresh): array
    {
        $who = $this->identities->forCustomer($subject['customerId'], $subject['email'], $subject['storeId']);
        if (!$who->carriesCustomerId()) {
            return ['identified' => false, 'answer' => null];
        }

        return ['identified' => true, 'answer' => $this->standing->read($who, $subject['storeId'], $fresh)];
    }
}

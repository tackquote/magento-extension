<?php
/**
 * What TackQuote (or the visitor's own situation) says about one shopper's buyer group.
 *
 * The statuses keep apart the cases the WooCommerce plugin learned to keep apart
 * (class-tack-group-restrictions.php `permitted()`, the "WE COULD NOT ASK" and "WE ASKED, AND
 * THE ANSWER IS NO" note): an outage and a real "no group" need OPPOSITE defaults.
 *
 *   exempt       Not a shopper: the merchant's admin or an integration (TackQuote converting
 *                an accepted quote included). No buyer-group rule ever applies.
 *   guest        Not signed in, or signed in to a customer group the merchant did not select
 *                under "Only for these customer groups". Nothing was asked.
 *   grouped      TackQuote places the buyer in the group `code` (upper-cased).
 *   none         TackQuote answered: no group (or the buyer is not linked).
 *   anonymous    TackQuote answered `anonymous` (no identity reached it).
 *   unavailable  TackQuote could not be asked or gave an answer this module does not know.
 *
 * `taxExempt` is true only when TackQuote sent `taxExempt: true` on a `grouped` or `none`
 * answer (BuyerGroupResult, tack apps/api/src/modules/storefront-core/storefront-results.ts:
 * 82-86). Anything else, an outage included, is not exempt.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

class BuyerGroupAnswer
{
    public const EXEMPT = 'exempt';
    public const GUEST = 'guest';
    public const GROUPED = 'grouped';
    public const NONE = 'none';
    public const ANONYMOUS = 'anonymous';
    public const UNAVAILABLE = 'unavailable';

    private const STATUSES = [
        self::EXEMPT,
        self::GUEST,
        self::GROUPED,
        self::NONE,
        self::ANONYMOUS,
        self::UNAVAILABLE,
    ];

    /**
     * @var string
     */
    private $status;

    /**
     * @var string
     */
    private $code;

    /**
     * @var bool
     */
    private $taxExempt;

    /**
     * @param string $status One of the status constants; anything else reads as UNAVAILABLE.
     * @param string $code The group code, for GROUPED only.
     * @param bool $taxExempt
     */
    public function __construct(string $status, string $code = '', bool $taxExempt = false)
    {
        $status = in_array($status, self::STATUSES, true) ? $status : self::UNAVAILABLE;
        $code = GroupRules::normaliseCode($code);
        if ($status === self::GROUPED && $code === '') {
            // A group with no code cannot match a rule: it is outside every group.
            $status = self::NONE;
        }

        $this->status = $status;
        $this->code = $status === self::GROUPED ? $code : '';
        $this->taxExempt = $taxExempt && ($status === self::GROUPED || $status === self::NONE);
    }

    /**
     * @return string
     */
    public function getStatus(): string
    {
        return $this->status;
    }

    /**
     * The upper-cased group code, or '' unless GROUPED.
     *
     * @return string
     */
    public function getCode(): string
    {
        return $this->code;
    }

    /**
     * @return bool
     */
    public function isTaxExempt(): bool
    {
        return $this->taxExempt;
    }

    /**
     * @return bool
     */
    public function isExempt(): bool
    {
        return $this->status === self::EXEMPT;
    }

    /**
     * TackQuote could not be asked: the one status whose default depends on a setting.
     *
     * @return bool
     */
    public function isUnknown(): bool
    {
        return $this->status === self::UNAVAILABLE;
    }
}

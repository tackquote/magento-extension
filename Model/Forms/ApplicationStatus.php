<?php
/**
 * Where a signed-in customer's wholesale application stands, from TackQuote.
 *
 * `GET /storefront/v1/wholesale-application` (tack StorefrontV1Controller, added with this
 * module's lane MG-FORMS-TERMS) answers `{status: application, state: none|pending|approved|
 * rejected}` for the customer TackQuote can identify by their Magento customer id, or
 * `anonymous` / `unlinked`.
 *
 * FAIL CLOSED: anything this class cannot read with certainty is `unknown`, and the tab then
 * shows the application form without claiming anything about the customer. TackQuote folds a
 * re-submission into an open application, so showing the form to someone already pending is
 * harmless; telling someone they are approved when they are not is not.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

class ApplicationStatus
{
    public const NONE = 'none';
    public const PENDING = 'pending';
    public const APPROVED = 'approved';
    public const DECLINED = 'rejected';
    public const UNKNOWN = 'unknown';

    /**
     * @var AccountApiClient
     */
    private $client;

    /**
     * @param AccountApiClient $client
     */
    public function __construct(AccountApiClient $client)
    {
        $this->client = $client;
    }

    /**
     * One of the constants above.
     *
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @return string
     */
    public function wholesale(AccountIdentity $who, ?int $storeId = null): string
    {
        if (!$who->carriesCustomerId()) {
            // An email alone identifies no application: TackQuote answers `unlinked` for it.
            return self::UNKNOWN;
        }

        return self::stateOf($this->client->getWholesaleApplication($who, $storeId));
    }

    /**
     * The state a client result reports, failing closed to UNKNOWN.
     *
     * @param array $result AccountApiClient result.
     * @return string
     */
    public static function stateOf(array $result): string
    {
        $data = !empty($result['ok']) && is_array($result['data'] ?? null) ? $result['data'] : [];
        if (($data['status'] ?? null) !== 'application') {
            return self::UNKNOWN;
        }
        $state = $data['state'] ?? null;

        return in_array($state, [self::NONE, self::PENDING, self::APPROVED, self::DECLINED], true)
            ? $state
            : self::UNKNOWN;
    }
}

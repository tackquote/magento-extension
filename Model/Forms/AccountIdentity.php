<?php
/**
 * Who a signed-in customer is, in the words TackQuote's `/storefront/v1/*` routes read.
 *
 * WIRE CONTRACT (tack storefront-api/api-key-storefront-identity.resolver.ts):
 *
 *   query  buyerEmail       the account email (always)
 *   header X-TackQuote-Platform  magento              only when the server lists the
 *                                                     `customerIdentity` capability
 *   query  buyerExternalId  the Magento customer id  } beside the platform header, and only
 *   header X-TackQuote-Store-Id  this install's UUID  } when this install has an id
 *
 * The customer id and the install id travel together or not at all. An older server has no Magento namespace and
 * reads a bare `buyerExternalId` as a WordPress user id, so without the capability the
 * customer is asserted by email alone: TackQuote then shows prices but no credit, and a
 * net-terms application is refused rather than filed under the wrong store.
 *
 * The values come from the customer record Magento holds for the session or the quote,
 * never from anything the shopper posts.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

class AccountIdentity
{
    /** The ping capability that says the server namespaces Magento customer ids. */
    public const CAPABILITY = 'customerIdentity';

    public const HEADER_PLATFORM = 'X-TackQuote-Platform';
    public const HEADER_STORE_ID = 'X-TackQuote-Store-Id';
    public const PLATFORM = 'magento';

    /** @var int */
    private $customerId;

    /** @var string */
    private $email;

    /** @var string */
    private $installId;

    /** @var bool */
    private $namespaced;

    /**
     * @param int $customerId Magento customer id (0 for nobody).
     * @param string $email Account email.
     * @param string $installId This install's UUID, or ''.
     * @param bool $serverNamespaces Whether the server lists the `customerIdentity` capability.
     */
    public function __construct(int $customerId, string $email, string $installId, bool $serverNamespaces)
    {
        $this->customerId = max(0, $customerId);
        $this->email = strtolower(trim($email));
        $this->installId = strtolower(trim($installId));
        $this->namespaced = $serverNamespaces;
    }

    /**
     * Whether there is a signed-in customer with an email to assert at all.
     *
     * @return bool
     */
    public function isSignedIn(): bool
    {
        return $this->customerId > 0 && $this->email !== '' && strpos($this->email, '@') !== false;
    }

    /**
     * Whether the customer id can be sent (and so credit can ever be read for them).
     *
     * @return bool
     */
    public function carriesCustomerId(): bool
    {
        return $this->isSignedIn() && $this->namespaced && $this->installId !== '';
    }

    /**
     * @return int
     */
    public function getCustomerId(): int
    {
        return $this->customerId;
    }

    /**
     * @return string
     */
    public function getEmail(): string
    {
        return $this->email;
    }

    /**
     * Query parameters naming the buyer. Empty for nobody.
     *
     * @return array<string, string>
     */
    public function query(): array
    {
        if (!$this->isSignedIn()) {
            return [];
        }
        $query = ['buyerEmail' => $this->email];
        if ($this->carriesCustomerId()) {
            $query['buyerExternalId'] = (string) $this->customerId;
        }

        return $query;
    }

    /**
     * Headers naming the platform (whenever the server reads it) and the install (only
     * beside a customer id).
     *
     * The platform header goes out even when this install has no id yet: without it the
     * server would read the email as a WooCommerce shopper, and on a workspace that also
     * has a WooCommerce store a net-terms application could be filed under that store.
     * With it, the server refuses such an application with its own "update the module"
     * answer instead.
     *
     * @return array<string, string>
     */
    public function headers(): array
    {
        if (!$this->isSignedIn() || !$this->namespaced) {
            return [];
        }
        $headers = [self::HEADER_PLATFORM => self::PLATFORM];
        if ($this->carriesCustomerId()) {
            $headers[self::HEADER_STORE_ID] = $this->installId;
        }

        return $headers;
    }
}

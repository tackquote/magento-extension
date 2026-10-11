<?php
/**
 * Builds the AccountIdentity TackQuote is told about, from a customer Magento itself holds.
 *
 * Callers pass the customer id and account email from the customer SESSION (account tabs)
 * or from the QUOTE / ORDER (checkout), never from posted input. Whether the Magento
 * customer id may be sent is the server's `customerIdentity` capability and this install's id.
 * The capability is read through Model\ServerCapabilities, which asks the server once when
 * nothing is cached (after a cache flush or before the first cron run) instead of answering
 * "no" until cron, which used to send wholesale applications without the Magento identity.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\ServerCapabilities;

class IdentityResolver
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var ServerCapabilities
     */
    private $capabilities;

    /**
     * @param Config $config
     * @param ServerCapabilities $capabilities
     */
    public function __construct(Config $config, ServerCapabilities $capabilities)
    {
        $this->config = $config;
        $this->capabilities = $capabilities;
    }

    /**
     * Resolve the identity TackQuote should know a customer by.
     *
     * @param int|null $customerId
     * @param string|null $email
     * @param int|null $storeId
     * @return AccountIdentity
     */
    public function forCustomer(?int $customerId, ?string $email, ?int $storeId = null): AccountIdentity
    {
        return new AccountIdentity(
            (int) $customerId,
            (string) $email,
            $this->config->getInstallId(),
            $this->capabilities->supports(AccountIdentity::CAPABILITY, $storeId, true)
        );
    }
}

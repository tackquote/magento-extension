<?php
/**
 * Keeps the TackQuote server's capability list in cache so a page render does not have to ping.
 *
 * The list decides the attachment control (Block\QuoteList), whether target prices travel
 * (Controller\Quote\Submit) and whether the Magento customer id is sent with wholesale and
 * net-terms requests (Model\Forms\IdentityResolver, `customerIdentity`). So every active store
 * view with TackQuote configured is warmed, not only those with attachments on: a store without
 * files still needs the identity. Model\ServerCapabilities asks on its own when the cache is
 * cold; this job keeps it from going cold and is what turns a failed ping into a WARNING in
 * system.log and the "last check failed" line on the Overview (ServerCapabilities::refresh()).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\ServerCapabilities;

class WarmServerCapabilities
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var ServerCapabilities
     */
    private $capabilities;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param StoreManagerInterface $storeManager
     * @param Config $config
     * @param ServerCapabilities $capabilities
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        Config $config,
        ServerCapabilities $capabilities,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->config = $config;
        $this->capabilities = $capabilities;
        $this->logger = $logger;
    }

    /**
     * Refresh the list for every active store view with TackQuote configured.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$store->getIsActive() || !$this->config->isConfigured($storeId)) {
                continue;
            }

            // One store view that cannot be read must not stop the others being warmed.
            try {
                $this->capabilities->refresh($storeId);
            } catch (\Exception $e) {
                $this->logger->warning(sprintf(
                    'TackQuote: could not refresh the server capabilities for store %d: %s',
                    $storeId,
                    $e->getMessage()
                ));
            }
        }
    }
}

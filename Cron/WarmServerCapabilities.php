<?php
/**
 * Keeps the TackQuote server's capability list in cache so no page render has to ping.
 *
 * Block\QuoteList decides whether to render the attachment control from
 * Model\ServerCapabilities::getCached(); this job is what puts the list there. Same schedule
 * and same per-store-view loop as Cron\WarmRegistrationConfig, and only for store views that
 * have the attachment switch on: a store that never offers files never pings for them (target
 * prices are checked by the submit controller itself, where the shopper already waits).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Cron;

use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\QuoteRequestConfig;
use TackQuote\Quotes\Model\ServerCapabilities;

class WarmServerCapabilities
{
    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var QuoteRequestConfig
     */
    private $quoteRequestConfig;

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
     * @param QuoteRequestConfig $quoteRequestConfig
     * @param ServerCapabilities $capabilities
     * @param LoggerInterface $logger
     */
    public function __construct(
        StoreManagerInterface $storeManager,
        QuoteRequestConfig $quoteRequestConfig,
        ServerCapabilities $capabilities,
        LoggerInterface $logger
    ) {
        $this->storeManager = $storeManager;
        $this->quoteRequestConfig = $quoteRequestConfig;
        $this->capabilities = $capabilities;
        $this->logger = $logger;
    }

    /**
     * Refresh the list for every active store view with attachments switched on.
     *
     * @return void
     */
    public function execute(): void
    {
        foreach ($this->storeManager->getStores() as $store) {
            $storeId = (int) $store->getId();
            if (!$store->getIsActive() || !$this->quoteRequestConfig->isAttachmentsSwitchOn($storeId)) {
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

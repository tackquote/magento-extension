<?php
/**
 * My Account > Wholesale account (GET /tackquote/account/wholesale).
 *
 * Magento\Customer\Controller\AbstractAccount: a signed-out visitor is sent to sign in by
 * Magento's own account plugin before this runs. 404 while the tab is switched off.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Account;

use Magento\Customer\Controller\AbstractAccount;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\ResultFactory;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\Config;

class Wholesale extends AbstractAccount implements HttpGetActionInterface
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Context $context
     * @param Config $config
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(Context $context, Config $config, StoreManagerInterface $storeManager)
    {
        parent::__construct($context);
        $this->config = $config;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        if (!$this->config->isWholesaleTabEnabled((int) $this->storeManager->getStore()->getId())) {
            return $this->resultFactory->create(ResultFactory::TYPE_FORWARD)->forward('noroute');
        }
        $page = $this->resultFactory->create(ResultFactory::TYPE_PAGE);
        $page->getConfig()->getTitle()->set(__('Wholesale account'));

        return $page;
    }
}

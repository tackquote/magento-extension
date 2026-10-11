<?php
/**
 * "Connect with TackQuote" button: opens a connect request and sends the admin
 * to TackQuote's consent page. See Model\Connect\ConnectService.
 *
 * POST only (HttpPostActionInterface): Magento's backend validates the admin
 * form key on every POST before execute() runs
 * (`Backend\App\AbstractAction::_processUrlKeys`), which is the CSRF defence.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use TackQuote\Quotes\Model\Connect\ConnectService;
use TackQuote\Quotes\Model\Connect\StoreInfo;

class Start extends Action implements HttpPostActionInterface
{
    /**
     * Connecting writes the API key, so it needs the Connect resource AND the
     * configuration resource (see _isAllowed and etc/acl.xml).
     */
    public const ADMIN_RESOURCE = 'TackQuote_Quotes::connect';

    /**
     * @var ConnectService
     */
    private $connect;

    /**
     * @var StoreInfo
     */
    private $storeInfo;

    /**
     * @param Context $context
     * @param ConnectService $connect
     * @param StoreInfo $storeInfo
     */
    public function __construct(Context $context, ConnectService $connect, StoreInfo $storeInfo)
    {
        parent::__construct($context);
        $this->connect = $connect;
        $this->storeInfo = $storeInfo;
    }

    /**
     * Both resources: Connect, and the TackQuote configuration section.
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed('TackQuote_Quotes::config');
    }

    /**
     * Start a connection and redirect the admin to TackQuote.
     *
     * @return Redirect
     */
    public function execute()
    {
        $userId = (int) $this->_auth->getUser()->getId();
        $outcome = $this->connect->start($userId, $this->storeInfo->describe());

        $result = $this->resultRedirectFactory->create();
        if (isset($outcome['redirect'])) {
            // An external URL, already checked by ConnectUrlPolicy::consentHost().
            return $result->setUrl($outcome['redirect']);
        }
        $this->messageManager->addErrorMessage($outcome['message']);

        return $result->setPath('tackquote/dashboard/index');
    }
}

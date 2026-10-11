<?php
/**
 * Where TackQuote returns the admin's browser after "Connect with TackQuote".
 * See Model\Connect\ConnectService::finish().
 *
 * A GET from another site, so it cannot carry a form key. Its defences:
 *   - the admin session and the ACL (this controller);
 *   - with "Add Secret Key to URLs" on (the default), the secret key in the
 *     route, bound to this admin session's form key, which TackQuote returns
 *     unchanged because it is part of the return URL the module declared;
 *   - the pending connect of THIS admin user, taken once, and the `state`
 *     compared to its hash in constant time;
 *   - the code alone is useless: redeeming it needs the PKCE verifier, which
 *     never left this server.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Adminhtml\Connect;

use Magento\Backend\App\Action;
use Magento\Backend\App\Action\Context;
use Magento\Framework\App\Action\HttpGetActionInterface;
use Magento\Framework\Controller\Result\Redirect;
use TackQuote\Quotes\Model\Connect\ConnectService;
use TackQuote\Quotes\Model\Connect\StoreInfo;

class Callback extends Action implements HttpGetActionInterface
{
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
     * Only admins allowed to manage the TackQuote configuration may finish a connection.
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed('TackQuote_Quotes::config');
    }

    /**
     * Finish the connection TackQuote redirected back from.
     *
     * @return Redirect
     */
    public function execute()
    {
        // The address bar holds a one-time code until the redirect below replaces it.
        $this->getResponse()->setHeader('Referrer-Policy', 'no-referrer', true);

        $query = [];
        foreach (['code', 'state', 'error'] as $name) {
            $value = $this->getRequest()->getParam($name);
            if (is_string($value)) {
                $query[$name] = $value;
            }
        }
        $userId = (int) $this->_auth->getUser()->getId();
        $outcome = $this->connect->finish($userId, $query, $this->storeInfo->siteUrl());

        if ($outcome['type'] === 'success') {
            $this->messageManager->addSuccessMessage($outcome['message']);
        } elseif ($outcome['type'] === 'warning') {
            $this->messageManager->addWarningMessage($outcome['message']);
        } else {
            $this->messageManager->addErrorMessage($outcome['message']);
        }

        return $this->resultRedirectFactory->create()->setPath('tackquote/dashboard/index');
    }
}

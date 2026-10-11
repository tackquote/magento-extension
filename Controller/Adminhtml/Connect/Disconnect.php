<?php
/**
 * "Disconnect": revoke the saved key in TackQuote when Connect issued it, and
 * remove it from this store. See Model\Connect\ConnectService::disconnect().
 *
 * POST only, so the admin form key is checked before execute() runs.
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

class Disconnect extends Action implements HttpPostActionInterface
{
    public const ADMIN_RESOURCE = 'TackQuote_Quotes::connect';

    /**
     * @var ConnectService
     */
    private $connect;

    /**
     * @param Context $context
     * @param ConnectService $connect
     */
    public function __construct(Context $context, ConnectService $connect)
    {
        parent::__construct($context);
        $this->connect = $connect;
    }

    /**
     * Only admins allowed to manage the TackQuote configuration may disconnect.
     *
     * @return bool
     */
    protected function _isAllowed(): bool
    {
        return parent::_isAllowed() && $this->_authorization->isAllowed('TackQuote_Quotes::config');
    }

    /**
     * Forget the stored TackQuote connection.
     *
     * @return Redirect
     */
    public function execute()
    {
        $outcome = $this->connect->disconnect();
        if ($outcome['type'] === 'success') {
            $this->messageManager->addSuccessMessage($outcome['message']);
        } else {
            $this->messageManager->addWarningMessage($outcome['message']);
        }

        return $this->resultRedirectFactory->create()->setPath('tackquote/dashboard/index');
    }
}

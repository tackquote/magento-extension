<?php
/**
 * Submits the net-terms (credit) application (POST /tackquote/account/nettermspost).
 *
 * Signed-in customers only (AbstractAccount); form key checked by Magento's CSRF validator.
 * The applicant and the contact email are the SESSION's customer, never posted fields.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Account;

use Magento\Customer\Controller\AbstractAccount;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Store\Model\StoreManagerInterface;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\ApplicationSubmitter;
use TackQuote\Quotes\Model\Forms\IdentityResolver;
use TackQuote\Quotes\Model\SubmissionThrottle;

class NetTermsPost extends AbstractAccount implements HttpPostActionInterface
{
    /** TackQuote allows 3 credit applications per 15 minutes per key. */
    private const MAX_PER_WINDOW = 3;
    private const WINDOW_SECONDS = 900;

    /**
     * @var Config
     */
    private $config;

    /**
     * @var Session
     */
    private $session;

    /**
     * @var IdentityResolver
     */
    private $identities;

    /**
     * @var ApplicationSubmitter
     */
    private $submitter;

    /**
     * @var SubmissionThrottle
     */
    private $throttle;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @param Context $context
     * @param Config $config
     * @param Session $session
     * @param IdentityResolver $identities
     * @param ApplicationSubmitter $submitter
     * @param SubmissionThrottle $throttle
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        Config $config,
        Session $session,
        IdentityResolver $identities,
        ApplicationSubmitter $submitter,
        SubmissionThrottle $throttle,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->session = $session;
        $this->identities = $identities;
        $this->submitter = $submitter;
        $this->throttle = $throttle;
        $this->storeManager = $storeManager;
    }

    /**
     * @inheritdoc
     */
    public function execute()
    {
        $redirect = $this->resultRedirectFactory->create();
        $redirect->setPath('tackquote/account/netterms');
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isNetTermsTabEnabled($storeId)) {
            return $this->resultFactory->create(\Magento\Framework\Controller\ResultFactory::TYPE_FORWARD)
                ->forward('noroute');
        }
        if ($this->throttle->isExceededFor('creditapp', self::MAX_PER_WINDOW, self::WINDOW_SECONDS)) {
            $this->messageManager->addErrorMessage(__('Too many attempts. Please wait a few minutes and try again.'));

            return $redirect;
        }

        $customer = $this->session->getCustomerData();
        $who = $this->identities->forCustomer(
            (int) $this->session->getCustomerId(),
            $customer ? (string) $customer->getEmail() : '',
            $storeId
        );
        $posted = $this->getRequest()->getParam('tack_ct');
        $outcome = $this->submitter->submitCredit(is_array($posted) ? $posted : [], $who, $storeId);

        if ($outcome['kind'] === 'error') {
            $this->messageManager->addErrorMessage($outcome['message']);
        } elseif ($outcome['kind'] === 'success') {
            $this->messageManager->addSuccessMessage($outcome['message']);
        } else {
            $this->messageManager->addNoticeMessage($outcome['message']);
        }

        return $redirect;
    }
}

<?php
/**
 * Submits the wholesale application (POST /tackquote/account/wholesalepost).
 *
 * Signed-in customers only (AbstractAccount); the form key is checked by Magento's CSRF
 * validator for every HttpPostActionInterface action. The applicant is the SESSION's customer,
 * never a posted field. Post/Redirect/Get: the outcome is a session message on the page the
 * form was on (the account tab or a CMS page), validated as this store's own URL.
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
use TackQuote\Quotes\Model\Forms\WholesaleFormSource;
use TackQuote\Quotes\Model\SubmissionThrottle;

class WholesalePost extends AbstractAccount implements HttpPostActionInterface
{
    /** Applications per visitor per window: TackQuote allows 5 per 5 minutes per key. */
    private const MAX_PER_WINDOW = 5;
    private const WINDOW_SECONDS = 600;

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
     * @var WholesaleFormSource
     */
    private $source;

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
     * @param WholesaleFormSource $source
     * @param ApplicationSubmitter $submitter
     * @param SubmissionThrottle $throttle
     * @param StoreManagerInterface $storeManager
     */
    public function __construct(
        Context $context,
        Config $config,
        Session $session,
        IdentityResolver $identities,
        WholesaleFormSource $source,
        ApplicationSubmitter $submitter,
        SubmissionThrottle $throttle,
        StoreManagerInterface $storeManager
    ) {
        parent::__construct($context);
        $this->config = $config;
        $this->session = $session;
        $this->identities = $identities;
        $this->source = $source;
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
        $redirect->setUrl($this->_redirect->getRefererUrl());
        $storeId = (int) $this->storeManager->getStore()->getId();
        if (!$this->config->isConfigured($storeId)) {
            $this->messageManager->addErrorMessage(__('Applications are not available right now.'));

            return $redirect;
        }
        if ($this->throttle->isExceededFor('application', self::MAX_PER_WINDOW, self::WINDOW_SECONDS)) {
            $this->messageManager->addErrorMessage(__('Too many attempts. Please wait a few minutes and try again.'));

            return $redirect;
        }

        $request = $this->getRequest();
        $slug = strtolower(trim((string) $request->getParam('tack_slug', '')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $slug) !== 1) {
            $slug = $this->config->getWholesaleFormSlug($storeId);
        }
        // The LIVE form, so an answer is checked against what the seller asks today.
        $form = $this->source->get($slug, $storeId, true);
        if ($form === null) {
            $this->messageManager->addErrorMessage(
                __('The application form is not available right now. Please try again later.')
            );

            return $redirect;
        }

        $customer = $this->session->getCustomerData();
        $who = $this->identities->forCustomer(
            (int) $this->session->getCustomerId(),
            $customer ? (string) $customer->getEmail() : '',
            $storeId
        );
        $posted = $request->getParam('tack_sf');
        $outcome = $this->submitter->submitWholesale(
            $slug,
            $form,
            is_array($posted) ? $posted : [],
            $this->submitter->filesByField($request->getFiles('tack_file')),
            $who,
            $storeId
        );

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

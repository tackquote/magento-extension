<?php
/**
 * The wholesale application form: in the "Wholesale account" tab, and on any CMS page.
 *
 * On a CMS page (or a widget) it renders only what is the same for every visitor (the
 * seller's form definition), so a full-page-cached copy can never show one shopper's state
 * to another:
 *
 *   {{block class="TackQuote\Quotes\Block\Account\WholesaleForm"
 *           template="TackQuote_Quotes::account/wholesale.phtml"}}
 *
 * Optional `slug="<form slug>"` picks a form other than the store's setting. Submitting
 * needs a signed-in customer; the POST controller sends a guest to sign in.
 *
 * In the account tab the layout sets `show_status` (and the block is not cacheable): the
 * customer's own application state replaces the form once there is one.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Account;

use Magento\Customer\Model\Session;
use Magento\Framework\View\Element\Template;
use Magento\Framework\View\Element\Template\Context;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\ApplicationStatus;
use TackQuote\Quotes\Model\Forms\FieldRenderer;
use TackQuote\Quotes\Model\Forms\FormDefinition;
use TackQuote\Quotes\Model\Forms\IdentityResolver;
use TackQuote\Quotes\Model\Forms\WholesaleFormSource;

class WholesaleForm extends Template
{
    /**
     * @var Config
     */
    private $config;

    /**
     * @var WholesaleFormSource
     */
    private $source;

    /**
     * @var FieldRenderer
     */
    private $renderer;

    /**
     * @var FormDefinition
     */
    private $definition;

    /**
     * @var ApplicationStatus
     */
    private $status;

    /**
     * @var IdentityResolver
     */
    private $identities;

    /**
     * @var Session
     */
    private $customerSession;

    /**
     * @var array|null|false false = not read yet
     */
    private $form = false;

    /**
     * @param Context $context
     * @param Config $config
     * @param WholesaleFormSource $source
     * @param FieldRenderer $renderer
     * @param FormDefinition $definition
     * @param ApplicationStatus $status
     * @param IdentityResolver $identities
     * @param Session $customerSession
     * @param array $data
     */
    public function __construct(
        Context $context,
        Config $config,
        WholesaleFormSource $source,
        FieldRenderer $renderer,
        FormDefinition $definition,
        ApplicationStatus $status,
        IdentityResolver $identities,
        Session $customerSession,
        array $data = []
    ) {
        parent::__construct($context, $data);
        $this->config = $config;
        $this->source = $source;
        $this->renderer = $renderer;
        $this->definition = $definition;
        $this->status = $status;
        $this->identities = $identities;
        $this->customerSession = $customerSession;
    }

    /**
     * Whether anything renders: the store is connected to TackQuote.
     *
     * @return bool
     */
    public function isEnabled(): bool
    {
        return $this->config->isConfigured($this->storeId());
    }

    /**
     * The form slug: the block's own `slug`, else the store's setting, else '' (default form).
     *
     * @return string
     */
    public function getSlug(): string
    {
        $own = strtolower(trim((string) $this->getData('slug')));
        if (preg_match('/^[a-z0-9][a-z0-9_-]{0,99}$/', $own) === 1) {
            return $own;
        }

        return $this->config->getWholesaleFormSlug($this->storeId());
    }

    /**
     * The normalised form, or null when TackQuote has none to show.
     *
     * @return array|null
     */
    public function getForm(): ?array
    {
        if ($this->form === false) {
            $this->form = $this->isEnabled() ? $this->source->get($this->getSlug(), $this->storeId()) : null;
        }

        return $this->form;
    }

    /**
     * The customer's wholesale application state.
     *
     * The customer's own application state, only in the account tab; ApplicationStatus::UNKNOWN
     * everywhere else and whenever it cannot be read.
     *
     * @return string
     */
    public function getState(): string
    {
        if (!$this->getData('show_status') || !$this->customerSession->isLoggedIn()) {
            return ApplicationStatus::UNKNOWN;
        }

        return $this->status->wholesale($this->identity(), $this->storeId());
    }

    /**
     * Whether the form's file inputs render.
     *
     * Whether file inputs render. In the account tab: only when TackQuote can tie a file to
     * this customer. On a CMS page: yes (the POST re-checks once the visitor signs in).
     *
     * @return bool
     */
    public function filesAllowed(): bool
    {
        if (!$this->getData('show_status')) {
            return true;
        }

        return $this->customerSession->isLoggedIn() && $this->identity()->carriesCustomerId();
    }

    /**
     * Whether the form needs `multipart/form-data`.
     *
     * @return bool
     */
    public function hasFileField(): bool
    {
        $form = $this->getForm();

        return $form !== null && $this->definition->hasFileField($form['fields']);
    }

    /**
     * One field's HTML (escaped inside FieldRenderer).
     *
     * @param array $field
     * @return string
     */
    public function renderField(array $field): string
    {
        return $this->renderer->render($field, null, $this->filesAllowed());
    }

    /**
     * URL the application form posts to.
     *
     * @return string
     */
    public function getActionUrl(): string
    {
        return $this->getUrl('tackquote/account/wholesalepost');
    }

    /**
     * Identity of the signed-in customer, as TackQuote knows it.
     *
     * @return \TackQuote\Quotes\Model\Forms\AccountIdentity
     */
    private function identity()
    {
        $customer = $this->customerSession->getCustomerData();

        return $this->identities->forCustomer(
            (int) $this->customerSession->getCustomerId(),
            $customer ? (string) $customer->getEmail() : '',
            $this->storeId()
        );
    }

    /**
     * Id of the current store, or null when it cannot be read.
     *
     * @return int|null
     */
    private function storeId(): ?int
    {
        try {
            return (int) $this->_storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return null;
        }
    }
}

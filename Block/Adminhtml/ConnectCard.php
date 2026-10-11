<?php
/**
 * The "Connect with TackQuote" card: on the TackQuote Dashboard, and (compact)
 * beside the API key field in Stores > Configuration > TackQuote.
 *
 * Shows how this store is connected, like the WooCommerce plugin's Overview:
 *   Connected via Connect with TackQuote | Connected via API key | Not connected.
 * "Connected" here means a default-scope key is SAVED; the Test connection
 * button proves whether it works.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Block\Adminhtml;

use Magento\Backend\Block\Template;
use Magento\Backend\Block\Template\Context;
use TackQuote\Quotes\Model\Config;

class ConnectCard extends Template
{
    /**
     * @var string
     */
    protected $_template = 'TackQuote_Quotes::connect-card.phtml';

    /**
     * @var Config
     */
    private $config;

    /**
     * @param Context $context
     * @param Config $config
     * @param array $data
     */
    public function __construct(Context $context, Config $config, array $data = [])
    {
        parent::__construct($context, $data);
        $this->config = $config;
    }

    /**
     * Config::VIA_CONNECT, Config::VIA_KEY, or '' (no default-scope key).
     *
     * @return string
     */
    public function getConnectionState(): string
    {
        return $this->config->getConnectedVia();
    }

    /**
     * The state as the merchant reads it.
     *
     * @return string
     */
    public function getStateLabel(): string
    {
        switch ($this->getConnectionState()) {
            case Config::VIA_CONNECT:
                return (string) __('Connected via Connect with TackQuote');
            case Config::VIA_KEY:
                return (string) __('Connected via API key');
            default:
                return (string) __('Not connected');
        }
    }

    /**
     * One sentence under the state, saying what pressing the button will do.
     *
     * @return string
     */
    public function getIntro(): string
    {
        switch ($this->getConnectionState()) {
            case Config::VIA_CONNECT:
                return (string) __('This store is connected with TackQuote. Connect again to issue a new key for this store; the previous one is revoked.');
            case Config::VIA_KEY:
                return (string) __('This store uses a pasted API key. Connecting with TackQuote replaces the saved key on this store. The old key keeps working until you revoke it in TackQuote.');
            default:
                return (string) __('Connect this store to your TackQuote account. Press Connect with TackQuote, sign in or create an account, and approve. Nothing to copy.');
        }
    }

    /**
     * @return string
     */
    public function getConnectLabel(): string
    {
        return $this->getConnectionState() === Config::VIA_CONNECT
            ? (string) __('Connect again')
            : (string) __('Connect with TackQuote');
    }

    /**
     * Whether this admin may connect or disconnect (both ACL resources).
     *
     * @return bool
     */
    public function canConnect(): bool
    {
        return $this->_authorization->isAllowed('TackQuote_Quotes::connect')
            && $this->_authorization->isAllowed('TackQuote_Quotes::config');
    }

    /**
     * Whether a default-scope key is saved (Disconnect is offered).
     *
     * @return bool
     */
    public function isConnected(): bool
    {
        return $this->getConnectionState() !== '';
    }

    /**
     * @return string
     */
    public function getStartUrl(): string
    {
        return $this->getUrl('tackquote/connect/start');
    }

    /**
     * @return string
     */
    public function getDisconnectUrl(): string
    {
        return $this->getUrl('tackquote/connect/disconnect');
    }

    /**
     * Compact mode: inside the configuration form, where a nested <form> is not
     * allowed, so the buttons post through a form built on click.
     *
     * @return bool
     */
    public function isCompact(): bool
    {
        return (bool) $this->getData('compact');
    }
}

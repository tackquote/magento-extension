<?php
/**
 * After "Submit application" the customer lands on the Wholesale account tab, with the
 * outcome as a session message, whenever that tab is on (devstore Q/A finding #5: the browser
 * landed on the quote-list page with no confirmation). With the tab off, back to the page the
 * form was on.
 *
 * Mutations (applied, red, restored):
 *   R1  always redirect to the referer -> testASubmissionLandsOnTheWholesaleTabWithTheOutcome
 *   R2  always redirect to the tab -> testWithTheTabOffItGoesBackToTheFormsPage
 *   R3  a received (pending) application as a notice -> testASubmissionLandsOnTheWholesaleTabWithTheOutcome
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Controller\Account;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\App\Response\RedirectInterface;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface;
use Magento\Store\Api\Data\StoreInterface;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Controller\Account\WholesalePost;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Forms\AccountIdentity;
use TackQuote\Quotes\Model\Forms\ApplicationSubmitter;
use TackQuote\Quotes\Model\Forms\IdentityResolver;
use TackQuote\Quotes\Model\Forms\WholesaleFormSource;
use TackQuote\Quotes\Model\SubmissionThrottle;

/**
 * @covers \TackQuote\Quotes\Controller\Account\WholesalePost
 */
class WholesalePostTest extends TestCase
{
    private const REFERER = 'https://store.example.test/trade-application.html';

    /** @var bool */
    private $tabOn = true;

    /** @var array<int, array{0: string, 1: string}> [kind, text] of every session message. */
    private $messages = [];

    /** @var string|null */
    private $path;

    /** @var string|null */
    private $url;

    private function controller(): WholesalePost
    {
        $this->messages = [];
        $this->path = null;
        $this->url = null;

        $redirect = $this->createMock(Redirect::class);
        $redirect->method('setPath')->willReturnCallback(function ($path) use ($redirect) {
            $this->path = $path;

            return $redirect;
        });
        $redirect->method('setUrl')->willReturnCallback(function ($url) use ($redirect) {
            $this->url = $url;

            return $redirect;
        });
        $redirectFactory = $this->createMock(RedirectFactory::class);
        $redirectFactory->method('create')->willReturn($redirect);

        $messages = $this->createMock(ManagerInterface::class);
        $kinds = ['addSuccessMessage' => 'success', 'addNoticeMessage' => 'notice', 'addErrorMessage' => 'error'];
        foreach ($kinds as $m => $k) {
            $messages->method($m)->willReturnCallback(function ($text) use ($k, $messages) {
                $this->messages[] = [$k, (string) $text];

                return $messages;
            });
        }

        $referer = $this->createMock(RedirectInterface::class);
        $referer->method('getRefererUrl')->willReturn(self::REFERER);

        $request = $this->createMock(HttpRequest::class);
        $params = ['tack_slug' => 'trade', 'tack_sf' => ['companyName' => 'Acme']];
        $request->method('getParam')->willReturnCallback(fn ($key, $default = null) => $params[$key] ?? $default);
        $request->method('getFiles')->willReturn(null);

        $context = $this->createMock(Context::class);
        $context->method('getResultRedirectFactory')->willReturn($redirectFactory);
        $context->method('getMessageManager')->willReturn($messages);
        $context->method('getRedirect')->willReturn($referer);
        $context->method('getRequest')->willReturn($request);

        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn(true);
        $config->method('isWholesaleTabEnabled')->willReturnCallback(fn () => $this->tabOn);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('buyer@example.test');
        $session = $this->createMock(Session::class);
        $session->method('getCustomerData')->willReturn($customer);
        $session->method('getCustomerId')->willReturn(42);

        $identities = $this->createMock(IdentityResolver::class);
        $identities->method('forCustomer')
            ->willReturn(new AccountIdentity(42, 'buyer@example.test', '0f0e0d0c-0b0a-4908-8706-050403020100', true));
        $source = $this->createMock(WholesaleFormSource::class);
        $source->method('get')->willReturn(['fields' => []]);
        $submitter = $this->createMock(ApplicationSubmitter::class);
        $submitter->method('filesByField')->willReturn([]);
        $submitter->method('submitWholesale')
            ->willReturn(['kind' => 'pending', 'message' => 'Your application has been received.']);
        $throttle = $this->createMock(SubmissionThrottle::class);

        $store = $this->createMock(StoreInterface::class);
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new WholesalePost(
            $context,
            $config,
            $session,
            $identities,
            $source,
            $submitter,
            $throttle,
            $storeManager
        );
    }

    public function testASubmissionLandsOnTheWholesaleTabWithTheOutcome(): void
    {
        $this->controller()->execute();

        self::assertSame('tackquote/account/wholesale', $this->path);
        self::assertNull($this->url);
        self::assertSame([['success', 'Your application has been received.']], $this->messages);
    }

    public function testWithTheTabOffItGoesBackToTheFormsPage(): void
    {
        $this->tabOn = false;

        $this->controller()->execute();

        self::assertNull($this->path);
        self::assertSame(self::REFERER, $this->url);
        self::assertSame([['success', 'Your application has been received.']], $this->messages);
    }
}

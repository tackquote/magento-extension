<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Plugin\Checkout;

use Magento\Checkout\Controller\Index\Index as CheckoutIndex;
use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\Controller\Result\Redirect;
use Magento\Framework\Controller\Result\RedirectFactory;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Plugin\Checkout\QuoteOnlyCheckoutGuard;

/**
 * @covers \TackQuote\Quotes\Plugin\Checkout\QuoteOnlyCheckoutGuard
 */
class QuoteOnlyCheckoutGuardTest extends TestCase
{
    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var RedirectFactory&MockObject */
    private $redirectFactory;

    /** @var MessageManager&MockObject */
    private $messages;

    /** @var Redirect&MockObject */
    private $redirect;

    /** @var CheckoutIndex&MockObject */
    private $controller;

    /** @var CheckoutSession&MockObject */
    private $session;

    /** @var Quote&MockObject */
    private $cart;

    protected function setUp(): void
    {
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        $this->messages = $this->createMock(MessageManager::class);
        $this->redirect = $this->createMock(Redirect::class);

        $this->redirectFactory = $this->getMockBuilder(RedirectFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $this->redirectFactory->method('create')->willReturn($this->redirect);

        $this->controller = $this->createMock(CheckoutIndex::class);

        $this->cart = $this->createMock(Quote::class);
        $this->session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $this->session->method('getQuote')->willReturn($this->cart);
    }

    private function guard(): QuoteOnlyCheckoutGuard
    {
        return new QuoteOnlyCheckoutGuard(
            $this->mode,
            $this->redirectFactory,
            $this->messages,
            $this->session,
            new QuotedCart(new QuotedLine(new Json()))
        );
    }

    public function testCheckoutIsRefusedAndTheShopperIsSentBackToTheirCart(): void
    {
        $this->mode->method('isActive')->willReturn(true);

        // The destination is the cart on purpose: it is where the shopper's items still are,
        // and the `tackquote_quote_only_cart` layout handle renders a Request a Quote CTA
        // there for them.
        $this->redirect->expects(self::once())->method('setPath')->with('checkout/cart')->willReturnSelf();
        $this->messages->expects(self::once())->method('addErrorMessage');

        $proceedWasCalled = false;
        $result = $this->guard()->aroundExecute($this->controller, function () use (&$proceedWasCalled) {
            $proceedWasCalled = true;

            return 'the real checkout page';
        });

        self::assertFalse($proceedWasCalled, 'the checkout controller must not run at all');
        self::assertSame($this->redirect, $result);
    }

    public function testANormalStoreReachesCheckoutUntouched(): void
    {
        $this->mode->method('isActive')->willReturn(false);

        $this->messages->expects(self::never())->method('addErrorMessage');

        $result = $this->guard()->aroundExecute($this->controller, static function () {
            return 'the real checkout page';
        });

        self::assertSame('the real checkout page', $result);
    }

    public function testACartHoldingAQuoteOnlyProductIsRefusedInNormalMode(): void
    {
        // Per-product: the store is not quote-only, but this cart holds a product whose own
        // flag is Yes (added before the flag was set). It must not reach checkout.
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->method('cartHasQuoteOnlyProduct')->with($this->cart)->willReturn(true);

        $this->redirect->expects(self::once())->method('setPath')->with('checkout/cart')->willReturnSelf();
        $this->messages->expects(self::once())->method('addErrorMessage');

        $proceedWasCalled = false;
        $result = $this->guard()->aroundExecute($this->controller, function () use (&$proceedWasCalled) {
            $proceedWasCalled = true;

            return 'the real checkout page';
        });

        self::assertFalse($proceedWasCalled);
        self::assertSame($this->redirect, $result);
    }

    public function testAnIntactAcceptedQuoteReachesCheckoutInAQuoteOnlyStore(): void
    {
        // Lane MG-QUOTE-CHECKOUT-LINK: the one legitimate checkout in a quote-only store.
        $this->mode->method('isActive')->willReturn(true);
        $this->messages->expects(self::never())->method('addErrorMessage');
        $quotedCart = $this->createMock(QuotedCart::class);
        $quotedCart->method('isIntact')->with($this->cart)->willReturn(true);

        $guard = new QuoteOnlyCheckoutGuard($this->mode, $this->redirectFactory, $this->messages, $this->session, $quotedCart);
        $result = $guard->aroundExecute($this->controller, static function () {
            return 'the real checkout page';
        });

        self::assertSame('the real checkout page', $result);
    }
}

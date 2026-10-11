<?php
/**
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Observer;

use Magento\Checkout\Model\Session as CheckoutSession;
use Magento\Framework\App\ActionFlag;
use Magento\Framework\App\ActionInterface;
use Magento\Framework\App\Response\Http as HttpResponse;
use Magento\Framework\Event\Observer;
use Magento\Framework\Message\ManagerInterface as MessageManager;
use Magento\Framework\UrlInterface;
use Magento\Quote\Model\Quote;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuoteOnlyMode;
use TackQuote\Quotes\Observer\QuoteOnlyMultishippingGuard;

/**
 * @covers \TackQuote\Quotes\Observer\QuoteOnlyMultishippingGuard
 */
class QuoteOnlyMultishippingGuardTest extends TestCase
{
    /** @var QuoteOnlyMode&MockObject */
    private $mode;

    /** @var ActionFlag&MockObject */
    private $flag;

    /** @var HttpResponse&MockObject */
    private $response;

    /** @var MessageManager&MockObject */
    private $messages;

    /** @var Quote&MockObject */
    private $cart;

    protected function setUp(): void
    {
        $this->mode = $this->createMock(QuoteOnlyMode::class);
        $this->flag = $this->createMock(ActionFlag::class);
        $this->response = $this->createMock(HttpResponse::class);
        $this->messages = $this->createMock(MessageManager::class);
        $this->cart = $this->createMock(Quote::class);
    }

    private function fire(): void
    {
        $session = $this->getMockBuilder(CheckoutSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getQuote'])
            ->getMock();
        $session->method('getQuote')->willReturn($this->cart);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->with('checkout/cart')->willReturn('https://store.test/checkout/cart/');

        (new QuoteOnlyMultishippingGuard(
            $this->mode,
            $session,
            $this->flag,
            $this->response,
            $url,
            $this->messages
        ))->execute(new Observer());
    }

    private function expectRefusal(): void
    {
        // FLAG_NO_DISPATCH is what makes FrontController::getActionResponse() return the
        // response without running the multishipping action (FrontController.php:240-241).
        $this->flag->expects(self::once())->method('set')->with('', ActionInterface::FLAG_NO_DISPATCH, true);
        $this->response->expects(self::once())->method('setRedirect')->with('https://store.test/checkout/cart/');
        $this->messages->expects(self::once())->method('addErrorMessage');
    }

    public function testMultishippingIsRefusedWhereTheModeApplies(): void
    {
        $this->mode->method('isActive')->willReturn(true);
        $this->expectRefusal();

        $this->fire();
    }

    public function testMultishippingIsRefusedForACartHoldingAQuoteOnlyProduct(): void
    {
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->method('cartHasQuoteOnlyProduct')->with($this->cart)->willReturn(true);
        $this->expectRefusal();

        $this->fire();
    }

    public function testANormalCartGoesThrough(): void
    {
        $this->mode->method('isActive')->willReturn(false);
        $this->mode->method('cartHasQuoteOnlyProduct')->willReturn(false);
        $this->flag->expects(self::never())->method('set');
        $this->response->expects(self::never())->method('setRedirect');

        $this->fire();
    }
}

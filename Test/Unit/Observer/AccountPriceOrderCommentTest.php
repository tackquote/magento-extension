<?php
/**
 * The order says so when the account price could not be confirmed and the store price was
 * charged, and says nothing otherwise.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Observer;

use Magento\Framework\DataObject;
use Magento\Framework\Event;
use Magento\Framework\Event\Observer;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Enforce\CustomPriceApplier;
use TackQuote\Quotes\Observer\AccountPriceOrderComment;

/**
 * @covers \TackQuote\Quotes\Observer\AccountPriceOrderComment
 */
class AccountPriceOrderCommentTest extends TestCase
{
    /**
     * @param mixed $flag
     * @return object The order stand-in, recording comments.
     */
    private function observe($flag): object
    {
        $order = new class {
            /** @var array<int, array> */
            public $comments = [];

            public function addCommentToStatusHistory($comment, $status = false, $isVisibleOnFront = false)
            {
                $this->comments[] = [(string) $comment, $status, $isVisibleOnFront];

                return $this;
            }
        };
        $quote = new DataObject([CustomPriceApplier::FALLBACK_FLAG => $flag]);
        $observer = new Observer(['event' => new Event(['order' => $order, 'quote' => $quote])]);

        (new AccountPriceOrderComment())->execute($observer);

        return $order;
    }

    public function testAFallbackIsRecordedForTheMerchantOnly(): void
    {
        $order = $this->observe(true);

        self::assertSame(
            [['TackQuote account price could not be confirmed; store price charged.', false, false]],
            $order->comments
        );
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function noFallback(): array
    {
        return ['false' => [false], 'unset' => [null], 'truthy string' => ['1']];
    }

    /**
     * @dataProvider noFallback
     * @param mixed $flag
     */
    public function testNothingIsRecordedWithoutAFallback($flag): void
    {
        self::assertSame([], $this->observe($flag)->comments);
    }
}

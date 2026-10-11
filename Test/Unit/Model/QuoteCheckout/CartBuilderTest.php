<?php
/**
 * The accepted quote goes into the cart whole, at its quoted prices and quantities, or not at all.
 *
 * Mutation proof:
 *   B1 the currency check is dropped                    -> testAQuoteInAnotherCurrencyIsRefusedBeforeTheCartIsTouched
 *   B2 the tax-basis check is dropped                   -> testAStoreThatEntersPricesWithTaxIsRefused
 *   B3 an unknown SKU is skipped instead of refused     -> testAnUnknownSkuRefusesTheWholeQuote
 *   B4 a failed add leaves the other lines in the cart  -> testALineMagentoRefusesEmptiesTheCartAgain
 *   B5 the custom price is not set                      -> testEveryLineCarriesTheQuotedPriceAndTheMarker
 *   B6 the building flag is not set around the adds     -> testTheGuardsSeeTheBuildingFlagDuringTheAdds
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\QuoteCheckout;

use Magento\Catalog\Api\ProductRepositoryInterface;
use Magento\Catalog\Model\Product;
use Magento\Framework\Exception\LocalizedException;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\Serialize\Serializer\Json;
use Magento\Quote\Api\CartRepositoryInterface;
use Magento\Quote\Model\Quote;
use Magento\Quote\Model\Quote\Address;
use Magento\Quote\Model\Quote\Item;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\QuoteCheckout\CartBuilder;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedCart;
use TackQuote\Quotes\Model\QuoteCheckout\QuotedLine;

/**
 * @covers \TackQuote\Quotes\Model\QuoteCheckout\CartBuilder
 */
class CartBuilderTest extends TestCase
{
    /** @var Item[] The fake cart's visible lines. */
    private $items = [];

    /** @var int */
    private $removeAll = 0;

    /** @var int */
    private $saves = 0;

    /** @var array<string, string> SKU => product type */
    private $catalog = ['SKU-A' => 'simple', 'SKU-B' => 'virtual'];

    /** @var string[] SKUs Magento refuses to add */
    private $refuseAdd = [];

    /** @var array<string, int> SKU => quantity Magento puts in instead */
    private $changeQty = [];

    /** @var bool[] The building flag as each add saw it */
    private $sawBuilding = [];

    /** @var QuotedCart */
    private $quotedCart;

    /** @var string */
    private $current = 'USD';

    /** @var bool */
    private $taxIncluded = false;

    /** @var CartRepositoryInterface&MockObject */
    private $cartRepository;

    private function payload(array $over = []): array
    {
        return array_merge([
            'quoteRef' => 'tqco_AAAAAAAAAAAAAAAAAAAAAA',
            'quoteNumber' => 'TK-2026-000123',
            'currency' => 'USD',
            'buyerEmail' => 'buyer@example.test',
            'poNumber' => '',
            'lines' => [
                ['sku' => 'SKU-A', 'name' => 'Alpha', 'quantity' => 3, 'unitPrice' => '10.00'],
                ['sku' => 'SKU-B', 'name' => 'Beta', 'quantity' => 2, 'unitPrice' => '5.5'],
            ],
        ], $over);
    }

    private function builder(): CartBuilder
    {
        $repository = $this->createMock(ProductRepositoryInterface::class);
        $repository->method('get')->willReturnCallback(function ($sku) {
            if (!isset($this->catalog[$sku])) {
                throw new NoSuchEntityException(__('no'));
            }
            $product = $this->createMock(Product::class);
            $product->method('getTypeId')->willReturn($this->catalog[$sku]);
            $product->method('getSku')->willReturn($sku);

            return $product;
        });
        $this->cartRepository = $this->createMock(CartRepositoryInterface::class);
        $this->cartRepository->method('save')->willReturnCallback(function (): void {
            $this->saves++;
        });
        $store = $this->createMock(Store::class);
        $store->method('getBaseCurrencyCode')->willReturn('USD');
        $store->method('getCurrentCurrencyCode')->willReturnCallback(function () {
            return $this->current;
        });
        $manager = $this->createMock(StoreManagerInterface::class);
        $manager->method('getStore')->willReturn($store);
        $config = $this->createMock(Config::class);
        $config->method('catalogPricesIncludeTax')->willReturnCallback(function () {
            return $this->taxIncluded;
        });
        $line = new QuotedLine(new Json());
        $this->quotedCart = new QuotedCart($line);

        return new CartBuilder($repository, $this->cartRepository, $manager, $config, $this->quotedCart, $line);
    }

    private function quote(): Quote
    {
        $quote = $this->getMockBuilder(Quote::class)
            ->disableOriginalConstructor()
            ->onlyMethods([
                'addProduct',
                'removeAllItems',
                'getAllVisibleItems',
                'collectTotals',
                'getBillingAddress',
                'getShippingAddress',
                'deleteItem',
            ])
            ->getMock();
        $quote->method('getAllVisibleItems')->willReturnCallback(function () {
            return array_values($this->items);
        });
        $quote->method('removeAllItems')->willReturnCallback(function () use ($quote) {
            $this->items = [];
            $this->removeAll++;

            return $quote;
        });
        $quote->method('deleteItem')->willReturnCallback(function ($item) use ($quote) {
            $this->items = array_filter($this->items, static function ($i) use ($item) {
                return $i !== $item;
            });

            return $quote;
        });
        $quote->method('addProduct')->willReturnCallback(function (Product $product, $qty) {
            $this->sawBuilding[] = $this->quotedCart->isBuilding();
            $sku = (string) $product->getSku();
            if (in_array($sku, $this->refuseAdd, true)) {
                throw new LocalizedException(__('Product that you are trying to add is not available.'));
            }
            $item = $this->getMockBuilder(Item::class)
                ->disableOriginalConstructor()
                ->onlyMethods(['getParentItem'])
                ->getMock();
            $item->method('getParentItem')->willReturn(null);
            $item->setData(['id' => count($this->items) + 1, 'sku' => $sku, 'qty' => $this->changeQty[$sku] ?? $qty]);
            $this->items[] = $item;

            return $item;
        });
        $quote->method('collectTotals')->willReturnSelf();
        $quote->method('getBillingAddress')->willReturn($this->createMock(Address::class));
        $quote->method('getShippingAddress')->willReturn($this->createMock(Address::class));
        // What Quote::beforeSave() writes from the store (Quote.php: base and current currency).
        $quote->setData('base_currency_code', 'USD');
        $quote->setData('quote_currency_code', 'USD');

        return $quote;
    }

    public function testEveryLineCarriesTheQuotedPriceAndTheMarker(): void
    {
        $quote = $this->quote();
        $out = $this->builder()->build($quote, $this->payload(), 1);

        self::assertSame(['ok' => true], $out);
        self::assertSame(1, $this->removeAll, 'the shopper\'s cart is emptied first');
        self::assertCount(2, $this->items);
        [$a, $b] = array_values($this->items);
        self::assertSame(10.0, $a->getData('custom_price'));
        self::assertSame(10.0, $a->getData('original_custom_price'));
        self::assertSame(5.5, $b->getData('custom_price'));
        $marker = (new QuotedLine(new Json()))->read($b);
        self::assertSame(
            [
                'ref' => 'tqco_AAAAAAAAAAAAAAAAAAAAAA',
                'number' => 'TK-2026-000123',
                'price' => 5.5,
                'qty' => 2,
                'lines' => 2,
                'currency' => 'USD',
            ],
            $marker
        );
        self::assertTrue($this->quotedCart->isIntact($quote));
        self::assertSame(1, $this->saves);
        self::assertNull($quote->getData('is_super_mode'), 'super mode would switch stock validation off');
    }

    public function testTheGuardsSeeTheBuildingFlagDuringTheAdds(): void
    {
        $this->builder()->build($this->quote(), $this->payload(), 1);

        self::assertSame([true, true], $this->sawBuilding);
        self::assertFalse($this->quotedCart->isBuilding(), 'and never after');
    }

    public function testAQuoteInAnotherCurrencyIsRefusedBeforeTheCartIsTouched(): void
    {
        $this->current = 'EUR';
        $out = $this->builder()->build($this->quote(), $this->payload(), 1);

        self::assertFalse($out['ok']);
        self::assertStringContainsString('priced in USD but this store checks out in EUR', (string) $out['message']);
        self::assertSame(0, $this->removeAll);
        self::assertSame(0, $this->saves);
    }

    public function testAStoreThatEntersPricesWithTaxIsRefused(): void
    {
        $this->taxIncluded = true;
        $out = $this->builder()->build($this->quote(), $this->payload(), 1);

        self::assertFalse($out['ok']);
        self::assertStringContainsString('including tax', (string) $out['message']);
        self::assertSame(0, $this->removeAll);
    }

    public function testAnUnknownSkuRefusesTheWholeQuote(): void
    {
        unset($this->catalog['SKU-B']);
        $out = $this->builder()->build($this->quote(), $this->payload(), 1);

        self::assertFalse($out['ok']);
        self::assertStringContainsString(': Beta.', (string) $out['message']);
        self::assertSame(0, $this->removeAll, 'the cart is not touched');
    }

    public function testAConfigurableParentCannotCarryAQuotedPrice(): void
    {
        $this->catalog['SKU-A'] = 'configurable';
        $out = $this->builder()->build($this->quote(), $this->payload(), 1);

        self::assertFalse($out['ok']);
        self::assertStringContainsString('Alpha', (string) $out['message']);
    }

    public function testALineMagentoRefusesEmptiesTheCartAgain(): void
    {
        $this->refuseAdd = ['SKU-B'];
        $out = $this->builder()->build($this->quote(), $this->payload(), 1);

        self::assertFalse($out['ok']);
        self::assertStringContainsString('Beta', (string) $out['message']);
        self::assertSame([], $this->items, 'never a partial quote');
        self::assertSame(2, $this->removeAll);
        self::assertFalse($this->quotedCart->isBuilding());
    }

    public function testAQuantityMagentoChangesIsARefusal(): void
    {
        $this->changeQty = ['SKU-A' => 4];
        $out = $this->builder()->build($this->quote(), $this->payload(), 1);

        self::assertFalse($out['ok']);
        self::assertSame([], $this->items);
    }

    public function testAGuestStartsCheckoutWithTheQuotesEmail(): void
    {
        $quote = $this->quote();
        $this->builder()->build($quote, $this->payload(), 1);

        self::assertSame('buyer@example.test', $quote->getData('customer_email'));

        $customer = $this->quote();
        $customer->setData('customer_id', 7);
        $customer->setData('customer_email', 'own@example.test');
        $this->items = [];
        $this->builder()->build($customer, $this->payload(), 1);
        self::assertSame('own@example.test', $customer->getData('customer_email'));
    }
}

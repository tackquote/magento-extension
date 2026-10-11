<?php
/**
 * What the product page is told: formatted text, the strike-through rule, the sentences.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\B2bDisplay;

use Magento\Catalog\Helper\Data as CatalogHelper;
use Magento\Catalog\Model\Product;
use Magento\Framework\Pricing\Amount\AmountInterface;
use Magento\Framework\Pricing\Price\PriceInterface;
use Magento\Framework\Pricing\PriceCurrencyInterface;
use Magento\Framework\Pricing\PriceInfoInterface;
use Magento\Store\Model\Store;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\B2bDisplay\Presenter;

/**
 * @covers \TackQuote\Quotes\Model\B2bDisplay\Presenter
 */
class PresenterTest extends TestCase
{
    /** @var CatalogHelper&MockObject */
    private $catalogHelper;

    /** @var PriceCurrencyInterface&MockObject */
    private $priceCurrency;

    /** @var array<int, array> Every format() call's arguments. */
    private $formatCalls = [];

    protected function setUp(): void
    {
        $this->catalogHelper = $this->createMock(CatalogHelper::class);
        // Identity tax basis: the price as given.
        $this->catalogHelper->method('getTaxPrice')->willReturnCallback(static function ($product, $price) {
            return $price;
        });
        $this->priceCurrency = $this->createMock(PriceCurrencyInterface::class);
        $this->priceCurrency->method('format')->willReturnCallback(function (...$args) {
            $this->formatCalls[] = $args;

            return '$' . number_format((float) $args[0], 2);
        });
        $this->priceCurrency->expects($this->never())->method('convertAndFormat');
    }

    private function product(?float $regular): Product
    {
        $amount = $this->createMock(AmountInterface::class);
        $amount->method('getValue')->willReturn($regular);
        $price = $this->createMock(PriceInterface::class);
        $price->method('getAmount')->willReturn($amount);
        $info = $this->createMock(PriceInfoInterface::class);
        $info->method('getPrice')->with('regular_price')->willReturn($price);
        $product = $this->createMock(Product::class);
        $product->method('getPriceInfo')->willReturn($info);

        return $product;
    }

    private function presenter(): Presenter
    {
        return new Presenter($this->catalogHelper, $this->priceCurrency);
    }

    private static function facts(array $overrides): array
    {
        return array_merge(Presenter::EMPTY_PAYLOAD, $overrides);
    }

    public function testTheStorePriceIsStruckThroughOnlyWhenTheAccountPriceIsLower(): void
    {
        $store = $this->createMock(Store::class);
        $presenter = $this->presenter();

        $lower = $presenter->present(
            self::facts(['accountPrice' => ['unitPrice' => 8.0, 'currency' => 'USD']]),
            $this->product(10.0),
            $store,
            'USD'
        );
        self::assertSame('$8.00', $lower['accountPrice']['price']);
        self::assertSame('$10.00', $lower['accountPrice']['storePrice']);
        self::assertSame('Your account price', $lower['accountPrice']['label']);

        $higher = $presenter->present(
            self::facts(['accountPrice' => ['unitPrice' => 12.0, 'currency' => 'USD']]),
            $this->product(10.0),
            $store,
            'USD'
        );
        self::assertNull($higher['accountPrice']['storePrice'], 'a "was" price below "now" reads as a rise');
    }

    public function testMoneyIsFormattedInThePageCurrencyWithoutConversion(): void
    {
        $store = $this->createMock(Store::class);

        $this->presenter()->present(
            self::facts(['accountPrice' => ['unitPrice' => 8.0, 'currency' => 'USD']]),
            $this->product(10.0),
            $store,
            'USD'
        );

        self::assertNotEmpty($this->formatCalls);
        foreach ($this->formatCalls as $args) {
            self::assertFalse($args[1], 'no HTML container');
            self::assertSame($store, $args[3]);
            self::assertSame('USD', $args[4]);
        }
    }

    public function testTheAccountPriceIsPutOnTheStoresTaxBasisAsANetPrice(): void
    {
        $helper = $this->createMock(CatalogHelper::class);
        $helper->expects($this->atLeastOnce())->method('getTaxPrice')
            ->with(
                $this->anything(),
                8.0,
                null,
                null,
                null,
                null,
                $this->anything(),
                false
            )
            ->willReturn(8.8);

        $out = (new Presenter($helper, $this->priceCurrency))->present(
            self::facts(['accountPrice' => ['unitPrice' => 8.0, 'currency' => 'USD']]),
            $this->product(11.0),
            $this->createMock(Store::class),
            'USD'
        );

        self::assertSame('$8.80', $out['accountPrice']['price']);
    }

    public function testTheVolumeTableCaptionSaysWhenTheLadderIsTheAccounts(): void
    {
        $rows = [['minQty' => 1, 'unitPrice' => 10.0], ['minQty' => 25, 'unitPrice' => 9.0]];
        $presenter = $this->presenter();
        $store = $this->createMock(Store::class);

        $own = $presenter->present(
            self::facts(['quantityBreaks' => ['currency' => 'USD', 'accountSpecific' => true, 'rows' => $rows]]),
            $this->product(10.0),
            $store,
            'USD'
        );
        $public = $presenter->present(
            self::facts(['quantityBreaks' => ['currency' => 'USD', 'accountSpecific' => false, 'rows' => $rows]]),
            $this->product(10.0),
            $store,
            'USD'
        );

        self::assertSame('Volume pricing for your account', $own['quantityBreaks']['caption']);
        self::assertSame('Volume pricing', $public['quantityBreaks']['caption']);
        self::assertSame(
            [['quantity' => '1+', 'price' => '$10.00'], ['quantity' => '25+', 'price' => '$9.00']],
            $own['quantityBreaks']['rows']
        );
    }

    public function testTheThreeOrderLimitSentences(): void
    {
        $presenter = $this->presenter();

        self::assertSame(
            'Order between 5 and 100 units of this product.',
            $presenter->limitSentence(['min' => 5, 'max' => 100])
        );
        self::assertSame('Minimum order quantity: 5 units.', $presenter->limitSentence(['min' => 5, 'max' => null]));
        self::assertSame('Maximum order quantity: 9 units.', $presenter->limitSentence(['min' => null, 'max' => 9]));
    }

    public function testNothingToShowStaysEmpty(): void
    {
        self::assertSame(
            Presenter::EMPTY_PAYLOAD,
            $this->presenter()->present(Presenter::EMPTY_PAYLOAD, $this->product(10.0), $this->createMock(Store::class), 'USD')
        );
    }

    public function testTheCartNoteAppearsOnlyBesideATackQuotePrice(): void
    {
        $presenter = $this->presenter();
        $store = $this->createMock(Store::class);
        $rows = [['minQty' => 1, 'unitPrice' => 10.0], ['minQty' => 25, 'unitPrice' => 9.0]];
        $note = "Your account prices are shown here; the cart and checkout use the store's prices for now.";

        $withPrice = $presenter->present(
            self::facts(['accountPrice' => ['unitPrice' => 8.0, 'currency' => 'USD']]),
            $this->product(10.0),
            $store,
            'USD'
        );
        $withTable = $presenter->present(
            self::facts(['quantityBreaks' => ['currency' => 'USD', 'accountSpecific' => true, 'rows' => $rows]]),
            $this->product(10.0),
            $store,
            'USD'
        );
        $badgeAndLimitOnly = $presenter->present(
            self::facts([
                'buyerGroup' => ['name' => 'Tier 2'],
                'orderLimit' => ['min' => 5, 'max' => null, 'accountSpecific' => true],
            ]),
            $this->product(10.0),
            $store,
            'USD'
        );

        self::assertSame(['text' => $note], $withPrice['cartNote']);
        self::assertSame(['text' => $note], $withTable['cartNote']);
        self::assertNull($badgeAndLimitOnly['cartNote'], 'no price shown, nothing to qualify');
        self::assertStringNotContainsString("\u{2014}", $note);
    }

    public function testTheBadgeCarriesTheGroupNameAsText(): void
    {
        $out = $this->presenter()->present(
            self::facts(['buyerGroup' => ['name' => '<b>Tier 2</b>']]),
            $this->product(10.0),
            $this->createMock(Store::class),
            'USD'
        );

        // Raw text: the browser inserts it with textContent, so markup stays inert.
        self::assertSame('<b>Tier 2</b>', $out['buyerGroup']['name']);
    }
}

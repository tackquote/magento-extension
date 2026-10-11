<?php
/**
 * B2B checkout enforcement is declared where carts are priced and orders placed (storefront,
 * REST, SOAP, GraphQL), after the quote-only guards, and nowhere the admin would run it.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Layout;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class CheckoutEnforcementDeclarationsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private const QUOTE = 'Magento\Quote\Model\Quote';
    private const SUBMIT = 'Magento\Quote\Model\QuoteManagement';
    private const ITEM_REPOSITORY = 'Magento\Quote\Api\CartItemRepositoryInterface';

    private function xml(string $relative): \SimpleXMLElement
    {
        $xml = simplexml_load_file(self::ROOT . '/' . $relative);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, "$relative is not well-formed XML");

        return $xml;
    }

    /**
     * @return array<string, array{type: string, sortOrder: int}> plugin class => where and when
     */
    private function plugins(string $relative): array
    {
        $out = [];
        foreach ($this->xml($relative)->xpath('//type[plugin]') ?: [] as $type) {
            foreach ($type->plugin as $plugin) {
                $out[(string) $plugin['type']] = [
                    'type' => (string) $type['name'],
                    'sortOrder' => (int) $plugin['sortOrder'],
                ];
            }
        }

        return $out;
    }

    /**
     * @return string[][]
     */
    public static function storefrontAreas(): array
    {
        return [
            ['etc/frontend/di.xml'],
            ['etc/webapi_rest/di.xml'],
            ['etc/webapi_soap/di.xml'],
            ['etc/graphql/di.xml'],
        ];
    }

    /**
     * @dataProvider storefrontAreas
     */
    public function testPricesAndLimitsAreDeclaredInEveryStorefrontArea(string $file): void
    {
        $plugins = $this->plugins($file);
        $expected = [
            'TackQuote\Quotes\Plugin\Quote\AccountPriceTotalsPlugin' => self::QUOTE,
            'TackQuote\Quotes\Plugin\Quote\OrderLimitCartGuard' => self::QUOTE,
            'TackQuote\Quotes\Plugin\Quote\AccountPriceSubmitPlugin' => self::SUBMIT,
            'TackQuote\Quotes\Plugin\Quote\OrderLimitSubmitGuard' => self::SUBMIT,
        ];

        foreach ($expected as $plugin => $intercepted) {
            self::assertSame($intercepted, $plugins[$plugin]['type'] ?? null, "$plugin in $file");
        }
    }

    /**
     * @dataProvider storefrontAreas
     */
    public function testEveryEnforcementPluginRunsAfterTheQuoteOnlyGuardOnTheSameMethod(string $file): void
    {
        $plugins = $this->plugins($file);
        $guard = $plugins['TackQuote\Quotes\Plugin\Quote\QuoteOnlySubmitGuard']['sortOrder'];
        $price = $plugins['TackQuote\Quotes\Plugin\Quote\AccountPriceSubmitPlugin']['sortOrder'];
        $limits = $plugins['TackQuote\Quotes\Plugin\Quote\OrderLimitSubmitGuard']['sortOrder'];

        self::assertGreaterThan($guard, $price, 'a quote-only cart is refused before it is priced');
        self::assertGreaterThan($price, $limits, 'the order total is checked after the fresh prices');
        self::assertGreaterThan(
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteOnlyCartGuard']['sortOrder'],
            $plugins['TackQuote\Quotes\Plugin\Quote\OrderLimitCartGuard']['sortOrder']
        );
    }

    /**
     * @return string[][]
     */
    public static function apiAreas(): array
    {
        return [['etc/webapi_rest/di.xml'], ['etc/webapi_soap/di.xml'], ['etc/graphql/di.xml']];
    }

    /**
     * @dataProvider apiAreas
     */
    public function testTheApiQuantityChangeIsLimitChecked(string $file): void
    {
        self::assertSame(
            self::ITEM_REPOSITORY,
            $this->plugins($file)['TackQuote\Quotes\Plugin\Quote\OrderLimitCartItemRepositoryGuard']['type'] ?? null
        );
    }

    public function testTheCartPageUpdateIsLimitChecked(): void
    {
        $plugins = $this->plugins('etc/frontend/di.xml');
        self::assertSame(
            'Magento\Checkout\Model\Cart',
            $plugins['TackQuote\Quotes\Plugin\Checkout\OrderLimitUpdateItemsGuard']['type'] ?? null
        );
    }

    public function testNothingIsDeclaredWhereTheAdminWouldRunIt(): void
    {
        $global = (string) file_get_contents(self::ROOT . '/etc/di.xml');
        foreach (['AccountPrice', 'OrderLimit'] as $name) {
            self::assertStringNotContainsString($name, $global, 'the admin order editor must keep its own prices');
        }
        self::assertFileDoesNotExist(self::ROOT . '/etc/adminhtml/di.xml');
    }

    public function testTheOrderNoteObserverListensToTheSubmitEvent(): void
    {
        $observers = $this->xml('etc/events.xml')
            ->xpath('//event[@name="sales_model_service_quote_submit_before"]/observer');

        self::assertNotEmpty($observers);
        self::assertSame('TackQuote\Quotes\Observer\AccountPriceOrderComment', (string) $observers[0]['instance']);
    }

    public function testBothSwitchesDefaultToOffAndAreInTheAdmin(): void
    {
        $defaults = $this->xml('etc/config.xml');
        self::assertSame('0', (string) $defaults->default->tackquote_quotes->b2b_checkout->enforce_prices);
        self::assertSame('0', (string) $defaults->default->tackquote_quotes->b2b_checkout->enforce_order_limits);

        $fields = $this->xml('etc/adminhtml/system.xml')->xpath('//group[@id="b2b_checkout"]/field/@id') ?: [];
        $ids = array_map('strval', $fields);
        self::assertSame(['enforce_prices', 'enforce_order_limits'], $ids);
    }
}

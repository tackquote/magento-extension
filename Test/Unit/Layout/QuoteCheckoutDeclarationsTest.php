<?php
/**
 * The accepted-quote checkout link is wired where carts are edited and orders placed, FIRST on
 * each method, and nowhere the admin would run it.
 *
 * Mutation proof:
 *   D1 a quote-checkout plugin ordered after the quote-only guard -> testTheQuoteCheckoutGuardsRunFirst
 *   D2 the submit guard missing from an API area                  -> testEveryStorefrontAreaGuardsTheQuote
 *   D3 the landing notice made cacheable                          -> testTheLandingPageIsNotCacheable
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Layout;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class QuoteCheckoutDeclarationsTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private function xml(string $relative): \SimpleXMLElement
    {
        $xml = simplexml_load_file(self::ROOT . '/' . $relative);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, "$relative is not well-formed XML");

        return $xml;
    }

    /**
     * @return array<string, array{type: string, sortOrder: int}>
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
    public function testEveryStorefrontAreaGuardsTheQuote(string $file): void
    {
        $plugins = $this->plugins($file);

        self::assertSame(
            'Magento\Quote\Model\Quote',
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteCheckoutCartGuard']['type'] ?? null
        );
        self::assertSame(
            'Magento\Quote\Model\QuoteManagement',
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteCheckoutSubmitGuard']['type'] ?? null
        );
    }

    /**
     * @dataProvider storefrontAreas
     */
    public function testTheQuoteCheckoutGuardsRunFirst(string $file): void
    {
        $plugins = $this->plugins($file);

        self::assertLessThan(
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteOnlySubmitGuard']['sortOrder'],
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteCheckoutSubmitGuard']['sortOrder'],
            'a broken quoted cart is refused before the quote-only guard exempts an intact one'
        );
        self::assertLessThan(
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteOnlyCartGuard']['sortOrder'],
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteCheckoutCartGuard']['sortOrder']
        );
    }

    public function testTheCartPageLockAndTheCheckoutPageGuardAreFrontend(): void
    {
        $plugins = $this->plugins('etc/frontend/di.xml');

        self::assertSame(
            'Magento\Checkout\Model\Cart',
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteCheckoutUpdateItemsLock']['type'] ?? null
        );
        self::assertSame(
            'Magento\Checkout\Controller\Index\Index',
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteCheckoutPageGuard']['type'] ?? null
        );
        self::assertLessThan(
            $plugins['TackQuote\Quotes\Plugin\Checkout\QuoteOnlyCheckoutGuard']['sortOrder'],
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteCheckoutPageGuard']['sortOrder']
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
    public function testTheApiQuantityChangeIsLocked(string $file): void
    {
        self::assertSame(
            'Magento\Quote\Api\CartItemRepositoryInterface',
            $this->plugins($file)['TackQuote\Quotes\Plugin\Quote\QuoteCheckoutCartItemRepositoryLock']['type'] ?? null
        );
    }

    public function testNothingIsDeclaredWhereTheAdminWouldRunIt(): void
    {
        self::assertStringNotContainsString('QuoteCheckout', (string) file_get_contents(self::ROOT . '/etc/di.xml'));
    }

    public function testTheObserversListenToTheSubmitAndRemoveEvents(): void
    {
        $events = $this->xml('etc/events.xml');
        $report = $events->xpath('//event[@name="sales_model_service_quote_submit_success"]/observer') ?: [];
        $removed = $events->xpath('//event[@name="sales_quote_remove_item"]/observer') ?: [];

        self::assertSame('TackQuote\Quotes\Observer\QuoteCheckoutOrderReport', (string) ($report[0]['instance'] ?? ''));
        self::assertSame('TackQuote\Quotes\Observer\QuoteCheckoutLineRemoved', (string) ($removed[0]['instance'] ?? ''));
    }

    public function testTheLandingPageIsNotCacheable(): void
    {
        $blocks = $this->xml('view/frontend/layout/tackquote_checkout_start.xml')
            ->xpath('//block[@name="tackquote.quote.checkout.notice"]') ?: [];

        self::assertCount(1, $blocks);
        self::assertSame('false', (string) $blocks[0]['cacheable']);
        self::assertFileExists(self::ROOT . '/view/frontend/templates/quote-checkout/notice.phtml');
        self::assertFileExists(self::ROOT . '/Controller/Checkout/Start.php');
    }

    public function testTheSwitchIsInTheAdminAndOnByDefault(): void
    {
        $defaults = $this->xml('etc/config.xml');
        self::assertSame('1', (string) $defaults->default->tackquote_quotes->quote_checkout->enabled);

        $fields = $this->xml('etc/adminhtml/system.xml')->xpath('//group[@id="quote_checkout"]/field/@id') ?: [];
        self::assertSame(['enabled'], array_map('strval', $fields));
    }
}

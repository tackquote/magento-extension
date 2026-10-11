<?php
/**
 * Where the buyer-group rules are declared IS part of the feature: storefront and API areas
 * only (never the global files, which the admin loads), every switch off by default, every rule
 * field validated on save, and the search exclusion present but inert until bound.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Layout;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class BuyerGroupWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';

    private function xml(string $relative): \SimpleXMLElement
    {
        $xml = simplexml_load_file(self::ROOT . '/' . $relative);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, "$relative is missing or not well-formed");

        return $xml;
    }

    /**
     * @return array<string, string> plugin type => intercepted class
     */
    private function plugins(string $relative): array
    {
        $out = [];
        foreach ($this->xml($relative)->xpath('//type[plugin]') ?: [] as $type) {
            foreach ($type->plugin as $plugin) {
                $out[(string) $plugin['type']] = (string) $type['name'];
            }
        }

        return $out;
    }

    /**
     * @return array<string, string> observer instance => event
     */
    private function observers(string $relative): array
    {
        $out = [];
        foreach ($this->xml($relative)->xpath('//event') ?: [] as $event) {
            foreach ($event->observer as $observer) {
                $out[(string) $observer['instance'] . '@' . (string) $event['name']] = (string) $event['name'];
            }
        }

        return $out;
    }

    public function testTheStorefrontDeclaresEveryCatalogAndCheckoutInterceptionPoint(): void
    {
        $plugins = $this->plugins('etc/frontend/di.xml');
        $expected = [
            'TackQuote\Quotes\Plugin\PageCache\BuyerGroupContext' => 'Magento\Framework\App\Http\Context',
            'TackQuote\Quotes\Plugin\PageCache\BuyerGroupWarmup' => 'Magento\Framework\App\ActionInterface',
            'TackQuote\Quotes\Plugin\Catalog\TopmenuVisibility' => 'Magento\Theme\Block\Html\Topmenu',
            'TackQuote\Quotes\Plugin\Catalog\CategoryViewVisibility' => 'Magento\Catalog\Controller\Category\View',
            'TackQuote\Quotes\Plugin\Catalog\ProductPageVisibility' => 'Magento\Catalog\Helper\Product',
            'TackQuote\Quotes\Plugin\Catalog\LayerVisibility' => 'Magento\Catalog\Model\Layer',
            'TackQuote\Quotes\Plugin\Payment\MethodListRestriction' => 'Magento\Payment\Model\MethodList',
            'TackQuote\Quotes\Plugin\Shipping\GroupShippingRates' => 'Magento\Shipping\Model\Shipping',
            'TackQuote\Quotes\Plugin\Tax\TaxExemptCustomerClass' => 'Magento\Quote\Model\Quote',
        ];
        foreach ($expected as $plugin => $subject) {
            self::assertSame($subject, $plugins[$plugin] ?? null, "$plugin is not declared on $subject");
        }
        // The quote-only plugins on the same types are still there.
        self::assertSame(
            'Magento\Quote\Model\Quote',
            $plugins['TackQuote\Quotes\Plugin\Quote\QuoteOnlyCartGuard'] ?? null
        );
        self::assertSame(
            'Magento\Framework\App\Http\Context',
            $plugins['TackQuote\Quotes\Plugin\PageCache\WholesaleApprovalContext'] ?? null
        );
    }

    public function testTheMenuFilterRunsAfterMagentoCatalogBuildsTheTree(): void
    {
        $nodes = $this->xml('etc/frontend/di.xml')->xpath(
            '//type[@name="Magento\Theme\Block\Html\Topmenu"]/plugin[@name="tackquote_topmenu_visibility"]'
        );

        self::assertNotEmpty($nodes);
        self::assertGreaterThan(0, (int) $nodes[0]['sortOrder'], 'catalogTopmenu has no sortOrder');
    }

    /**
     * @return array<int, array{0: string}>
     */
    public static function apiAreas(): array
    {
        return [['etc/webapi_rest'], ['etc/webapi_soap'], ['etc/graphql']];
    }

    /**
     * @dataProvider apiAreas
     */
    public function testTheApiAreasRestrictPaymentShippingTaxAndHiddenProducts(string $area): void
    {
        $plugins = $this->plugins("$area/di.xml");
        self::assertSame(
            'Magento\Payment\Model\MethodList',
            $plugins['TackQuote\Quotes\Plugin\Payment\MethodListRestriction'] ?? null
        );
        self::assertSame(
            'Magento\Shipping\Model\Shipping',
            $plugins['TackQuote\Quotes\Plugin\Shipping\GroupShippingRates'] ?? null
        );
        self::assertSame(
            'Magento\Quote\Model\Quote',
            $plugins['TackQuote\Quotes\Plugin\Tax\TaxExemptCustomerClass'] ?? null
        );
        self::assertArrayNotHasKey(
            'TackQuote\Quotes\Plugin\PageCache\BuyerGroupContext',
            $plugins,
            'no page cache in the API'
        );

        $events = $this->observers("$area/events.xml");
        self::assertContains('payment_method_is_active', $events);
        self::assertContains('catalog_product_is_salable_after', $events);
        self::assertNotContains('customer_login', $events);
    }

    public function testTheStorefrontObservers(): void
    {
        $events = $this->observers('etc/frontend/events.xml');

        self::assertSame(
            'payment_method_is_active',
            $events['TackQuote\Quotes\Observer\PaymentMethodGroupRestriction@payment_method_is_active'] ?? null
        );
        self::assertSame(
            'catalog_product_is_salable_after',
            $events['TackQuote\Quotes\Observer\HiddenProductNotSalable@catalog_product_is_salable_after'] ?? null
        );
        self::assertSame('customer_login', $events['TackQuote\Quotes\Observer\GroupMirrorSync@customer_login'] ?? null);
        self::assertSame(
            'controller_action_predispatch',
            $events['TackQuote\Quotes\Observer\GroupMirrorSync@controller_action_predispatch'] ?? null
        );
    }

    public function testNothingIsDeclaredWhereTheAdminWouldRunIt(): void
    {
        foreach (array_keys($this->plugins('etc/di.xml')) as $plugin) {
            self::assertStringNotContainsString('\\Catalog\\', $plugin);
            self::assertStringNotContainsString('Group', $plugin);
            self::assertStringNotContainsString('TaxExempt', $plugin);
        }
        foreach (['etc/events.xml', 'etc/adminhtml/events.xml'] as $file) {
            foreach (array_keys($this->observers($file)) as $observer) {
                self::assertStringNotContainsString('Group', $observer, "$file runs in the admin");
                self::assertStringNotContainsString('HiddenProduct', $observer, "$file runs in the admin");
            }
        }
    }

    /**
     * Magento's di.xsd has a `uniqueType` constraint: a second <type> block for the same class
     * in one file fails validation in developer mode ("Invalid XML in file"). The buyer-group
     * plugins on classes other lanes also intercept therefore share their block.
     */
    public function testEachDiFileDeclaresAClassInOneBlockOnly(): void
    {
        foreach ([
            'etc/di.xml',
            'etc/frontend/di.xml',
            'etc/webapi_rest/di.xml',
            'etc/webapi_soap/di.xml',
            'etc/graphql/di.xml'
        ] as $file) {
            $names = [];
            foreach ($this->xml($file)->xpath('/config/type') ?: [] as $type) {
                $names[] = (string) $type['name'];
            }
            self::assertSame(
                [],
                array_values(array_unique(array_diff_assoc($names, array_unique($names)))),
                "$file declares a class in more than one <type> block"
            );
        }
    }

    public function testEverySwitchIsOffByDefault(): void
    {
        $defaults = $this->xml('etc/config.xml')->default->tackquote_quotes->b2b_groups;
        $switches = [
            'enable_catalog_visibility',
            'hide_catalog_when_unknown',
            'enable_restrictions',
            'restrict_when_unknown',
            'enable_shipping_discounts',
            'enable_tax_exempt',
            'enable_group_mirror',
        ];
        foreach ($switches as $switch) {
            self::assertSame('0', (string) $defaults->{$switch}, "$switch must default to 0");
        }
    }

    public function testEveryRuleFieldIsValidatedOnSave(): void
    {
        $group = $this->xml('etc/adminhtml/system.xml')->xpath('//group[@id="b2b_groups"]');
        self::assertNotEmpty($group);
        foreach ([
            'catalog_visibility_map',
            'payment_map',
            'shipping_map',
            'shipping_discount_map',
            'group_mirror_map'
        ] as $field) {
            $node = $group[0]->xpath("field[@id='$field']");
            self::assertNotEmpty($node, "$field is missing");
            self::assertSame(
                'TackQuote\Quotes\Model\Groups\Config\RuleMapBackend',
                (string) $node[0]->backend_model,
                "$field is saved unchecked"
            );
        }
    }

    public function testTheSearchExclusionIsANotClauseOnTheBoundPlaceholder(): void
    {
        $xml = $this->xml('etc/search_request.xml');
        foreach (['quick_search_container', 'catalog_view_container'] as $request) {
            $refs = $xml->xpath(
                "//request[@query='$request']/queries/query[@name='$request']"
                . "/queryReference[@ref='tackquote_hidden_categories']"
            );
            self::assertNotEmpty($refs, "$request does not exclude hidden categories");
            self::assertSame('not', (string) $refs[0]['clause']);
            $filter = $xml->xpath(
                "//request[@query='$request']/filters/filter[@name='tackquote_hidden_categories_filter']"
            );
            self::assertSame('category_ids', (string) $filter[0]['field']);
            self::assertSame('$tackquote_hidden_category_ids$', (string) $filter[0]['value']);
        }
    }
}

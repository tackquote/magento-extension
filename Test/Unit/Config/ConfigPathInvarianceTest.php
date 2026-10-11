<?php
/**
 * Every stored configuration path survives the admin restructure, byte for byte.
 *
 * The TackQuote settings were regrouped into the WooCommerce plugin's tab order (labels,
 * sortOrder, group comments and <depends> changed). None of that may move a value: the
 * module reads every setting by path (tackquote_quotes/<group>/<field>, or the field's
 * <config_path>), and merchants' saved values live under those paths in core_config_data.
 * A field moved to another group, a renamed id, a changed type, scope flag, backend or source
 * model, or a changed default in etc/config.xml would silently orphan a saved value or change
 * how it is read. This test fails on any of them.
 *
 * FIELDS_BEFORE and DEFAULTS_BEFORE were generated from origin/main at 1352338 (the commit
 * this restructure was rebased onto), before any change; the *_ADDED lists hold what this
 * change adds (group `styling`). A future change that adds settings appends to *_ADDED in
 * the same commit; nothing in *_BEFORE may ever be edited.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Config;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class ConfigPathInvarianceTest extends TestCase
{
    private const FIELDS_BEFORE = [
        'tackquote_quotes/b2b/customer_groups' => 'type=multiselect|default=1|website=1|store=0|config_path=|source=Magento\\Customer\\Model\\Config\\Source\\Group\\Multiselect|backend=|frontend=|can_be_empty=1',
        'tackquote_quotes/b2b/enable_account_price' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b/enable_buyer_group_badge' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b/enable_order_limits' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b/enable_quantity_breaks' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_checkout/enforce_order_limits' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_checkout/enforce_prices' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/catalog_visibility_map' => 'type=textarea|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Groups\\Config\\RuleMapBackend|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/customer_groups' => 'type=multiselect|default=1|website=1|store=0|config_path=|source=Magento\\Customer\\Model\\Config\\Source\\Group\\Multiselect|backend=|frontend=|can_be_empty=1',
        'tackquote_quotes/b2b_groups/enable_catalog_visibility' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/enable_group_mirror' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/enable_restrictions' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/enable_shipping_discounts' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/enable_tax_exempt' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/group_mirror_map' => 'type=textarea|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Groups\\Config\\RuleMapBackend|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/hide_catalog_when_unknown' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/payment_map' => 'type=textarea|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Groups\\Config\\RuleMapBackend|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/restrict_when_unknown' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/shipping_discount_map' => 'type=textarea|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Groups\\Config\\RuleMapBackend|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/shipping_map' => 'type=textarea|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Groups\\Config\\RuleMapBackend|frontend=|can_be_empty=',
        'tackquote_quotes/b2b_groups/tax_exempt_class' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Tax\\Model\\TaxClass\\Source\\Customer|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/forms/enable_net_terms_tab' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/forms/enable_wholesale_tab' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/forms/wholesale_form_slug' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/general/api_base_url' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/general/api_key' => 'type=obscure|default=1|website=1|store=0|config_path=|source=|backend=Magento\\Config\\Model\\Config\\Backend\\Encrypted|frontend=|can_be_empty=',
        'tackquote_quotes/general/connect_with_tackquote' => 'type=|default=1|website=0|store=0|config_path=|source=|backend=|frontend=TackQuote\\Quotes\\Block\\Adminhtml\\System\\Config\\ConnectButton|can_be_empty=',
        'tackquote_quotes/general/enabled' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/general/test_connection' => 'type=|default=1|website=1|store=0|config_path=|source=|backend=|frontend=TackQuote\\Quotes\\Block\\Adminhtml\\System\\Config\\TestConnection|can_be_empty=',
        'tackquote_quotes/net_terms/active' => 'type=select|default=1|website=1|store=0|config_path=payment/tackquote_net_terms/active|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/net_terms/description' => 'type=textarea|default=1|website=1|store=0|config_path=payment/tackquote_net_terms/description|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/net_terms/po_number' => 'type=select|default=1|website=1|store=0|config_path=payment/tackquote_net_terms/po_number|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/net_terms/sort_order' => 'type=text|default=1|website=1|store=0|config_path=payment/tackquote_net_terms/sort_order|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/net_terms/title' => 'type=text|default=1|website=1|store=0|config_path=payment/tackquote_net_terms/title|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_checkout/enabled' => 'type=select|default=1|website=1|store=1|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_only/applies_to' => 'type=select|default=1|website=1|store=0|config_path=|source=TackQuote\\Quotes\\Model\\Config\\Source\\QuoteOnlyScope|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_only/customer_groups' => 'type=multiselect|default=1|website=1|store=0|config_path=|source=Magento\\Customer\\Model\\Config\\Source\\Group\\Multiselect|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_only/enabled' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_only/hide_prices' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_request/cart_button_label' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_request/enable_attachments' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_request/enable_cart_button' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_request/enable_quote_page' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_request/enable_target_price' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/quote_request/quote_page_link_label' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/storefront/add_to_quote_label' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/storefront/button_label' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/storefront/checkout_button_label' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/storefront/show_add_to_quote' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/storefront/show_button' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/storefront/show_on_listing' => 'type=select|default=1|website=1|store=0|config_path=|source=Magento\\Config\\Model\\Config\\Source\\Yesno|backend=|frontend=|can_be_empty=',
    ];
    private const FIELDS_ADDED = [
        'tackquote_quotes/styling/accent_color' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Config\\Backend\\StylingValue|frontend=|can_be_empty=',
        'tackquote_quotes/styling/button_style' => 'type=select|default=1|website=1|store=0|config_path=|source=TackQuote\\Quotes\\Model\\Config\\Source\\ButtonStyle|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/styling/custom_css' => 'type=textarea|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Config\\Backend\\StylingValue|frontend=|can_be_empty=',
        'tackquote_quotes/styling/launcher_label' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Config\\Backend\\StylingValue|frontend=|can_be_empty=',
        'tackquote_quotes/styling/launcher_offset' => 'type=text|default=1|website=1|store=0|config_path=|source=|backend=TackQuote\\Quotes\\Model\\Config\\Backend\\StylingValue|frontend=|can_be_empty=',
        'tackquote_quotes/styling/launcher_position' => 'type=select|default=1|website=1|store=0|config_path=|source=TackQuote\\Quotes\\Model\\Config\\Source\\LauncherPosition|backend=|frontend=|can_be_empty=',
        'tackquote_quotes/styling/launcher_size' => 'type=select|default=1|website=1|store=0|config_path=|source=TackQuote\\Quotes\\Model\\Config\\Source\\LauncherSize|backend=|frontend=|can_be_empty=',
    ];
    private const DEFAULTS_BEFORE = [
        'payment/tackquote_net_terms/active' => '0',
        'payment/tackquote_net_terms/can_use_checkout' => '1',
        'payment/tackquote_net_terms/can_use_internal' => '0',
        'payment/tackquote_net_terms/description' => 'Place this order on your approved payment terms. We will send an invoice.',
        'payment/tackquote_net_terms/group' => 'offline',
        'payment/tackquote_net_terms/is_offline' => '1',
        'payment/tackquote_net_terms/model' => 'TackQuote\\Quotes\\Model\\Payment\\NetTermsMethod',
        'payment/tackquote_net_terms/order_status' => 'pending',
        'payment/tackquote_net_terms/po_number' => '0',
        'payment/tackquote_net_terms/sort_order' => '100',
        'payment/tackquote_net_terms/title' => 'Pay on net terms',
        'tackquote_quotes/b2b/customer_groups' => '',
        'tackquote_quotes/b2b/enable_account_price' => '0',
        'tackquote_quotes/b2b/enable_buyer_group_badge' => '0',
        'tackquote_quotes/b2b/enable_order_limits' => '0',
        'tackquote_quotes/b2b/enable_quantity_breaks' => '0',
        'tackquote_quotes/b2b_checkout/enforce_order_limits' => '0',
        'tackquote_quotes/b2b_checkout/enforce_prices' => '0',
        'tackquote_quotes/b2b_groups/customer_groups' => '',
        'tackquote_quotes/b2b_groups/enable_catalog_visibility' => '0',
        'tackquote_quotes/b2b_groups/enable_group_mirror' => '0',
        'tackquote_quotes/b2b_groups/enable_restrictions' => '0',
        'tackquote_quotes/b2b_groups/enable_shipping_discounts' => '0',
        'tackquote_quotes/b2b_groups/enable_tax_exempt' => '0',
        'tackquote_quotes/b2b_groups/hide_catalog_when_unknown' => '0',
        'tackquote_quotes/b2b_groups/restrict_when_unknown' => '0',
        'tackquote_quotes/b2b_groups/tax_exempt_class' => '0',
        'tackquote_quotes/forms/enable_net_terms_tab' => '0',
        'tackquote_quotes/forms/enable_wholesale_tab' => '0',
        'tackquote_quotes/forms/wholesale_form_slug' => '',
        'tackquote_quotes/quote_checkout/enabled' => '1',
        'tackquote_quotes/quote_only/hide_prices' => '0',
        'tackquote_quotes/quote_request/enable_attachments' => '0',
        'tackquote_quotes/quote_request/enable_cart_button' => '0',
        'tackquote_quotes/quote_request/enable_quote_page' => '0',
        'tackquote_quotes/quote_request/enable_target_price' => '0',
    ];
    private const DEFAULTS_ADDED = [
        'tackquote_quotes/styling/accent_color' => '',
        'tackquote_quotes/styling/button_style' => 'filled',
        'tackquote_quotes/styling/custom_css' => '',
        'tackquote_quotes/styling/launcher_label' => '',
        'tackquote_quotes/styling/launcher_offset' => '20',
        'tackquote_quotes/styling/launcher_position' => 'right',
        'tackquote_quotes/styling/launcher_size' => 'regular',
    ];

    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    public function testEveryFieldPathAndItsStorageShapeIsUnchanged(): void
    {
        $expected = self::FIELDS_BEFORE + self::FIELDS_ADDED;
        ksort($expected);
        $actual = $this->fields();
        ksort($actual);

        self::assertSame(
            [],
            array_diff_key($expected, $actual),
            'A configuration path disappeared from etc/adminhtml/system.xml (saved values would be orphaned).'
        );
        self::assertSame(
            [],
            array_diff_key($actual, $expected),
            'A new configuration path: add it to FIELDS_ADDED in the same change.'
        );
        self::assertSame($expected, $actual, 'A field changed type, scope, config_path, source or backend model.');
    }

    public function testEveryDefaultIsUnchanged(): void
    {
        $expected = self::DEFAULTS_BEFORE + self::DEFAULTS_ADDED;
        ksort($expected);
        $actual = $this->defaults();
        ksort($actual);

        self::assertSame($expected, $actual, 'etc/config.xml defaults changed.');
    }

    public function testNothingWasAddedToAnExistingGroupOrDefaultUnnoticed(): void
    {
        // The two lists must not overlap: an "added" path that already existed would mean
        // a field was moved or redefined rather than added.
        self::assertSame([], array_intersect_key(self::FIELDS_ADDED, self::FIELDS_BEFORE));
        self::assertSame([], array_intersect_key(self::DEFAULTS_ADDED, self::DEFAULTS_BEFORE));
    }

    public function testEveryDependsNamesAFieldOfTheSameGroup(): void
    {
        // A <depends> on a field id that does not exist hides the dependent field for good.
        $xml = $this->load('etc/adminhtml/system.xml');
        $checked = 0;
        foreach ($xml->xpath('/config/system/section/group') ?: [] as $group) {
            $ids = [];
            foreach ($group->field as $field) {
                $ids[] = (string) $field['id'];
            }
            foreach ($group->xpath('field/depends/field') ?: [] as $dependency) {
                self::assertContains(
                    (string) $dependency['id'],
                    $ids,
                    sprintf('group %s: <depends> names a field that is not in the group', (string) $group['id'])
                );
                $checked++;
            }
        }
        self::assertGreaterThan(10, $checked, 'no <depends> found: the reader is broken');
    }

    /**
     * Every field of system.xml as path => storage signature.
     *
     * @return array<string, string>
     */
    private function fields(): array
    {
        $xml = $this->load('etc/adminhtml/system.xml');
        $out = [];
        foreach ($xml->xpath('/config/system/section') ?: [] as $section) {
            foreach ($section->group as $group) {
                foreach ($group->field as $field) {
                    $path = sprintf('%s/%s/%s', $section['id'], $group['id'], $field['id']);
                    self::assertArrayNotHasKey($path, $out, 'field defined twice: ' . $path);
                    $out[$path] = implode('|', [
                        'type=' . (string) $field['type'],
                        'default=' . (string) $field['showInDefault'],
                        'website=' . (string) $field['showInWebsite'],
                        'store=' . (string) $field['showInStore'],
                        'config_path=' . trim((string) $field->config_path),
                        'source=' . trim((string) $field->source_model),
                        'backend=' . trim((string) $field->backend_model),
                        'frontend=' . trim((string) $field->frontend_model),
                        'can_be_empty=' . trim((string) $field->can_be_empty),
                    ]);
                }
            }
        }

        return $out;
    }

    /**
     * Every leaf under <default> in etc/config.xml as path => value.
     *
     * @return array<string, string>
     */
    private function defaults(): array
    {
        $xml = $this->load('etc/config.xml');
        $out = [];
        foreach ($xml->default->children() as $top) {
            $this->walk($top, $top->getName(), $out);
        }

        return $out;
    }

    /**
     * @param \SimpleXMLElement $node
     * @param string $path
     * @param array<string, string> $out
     * @return void
     */
    private function walk(\SimpleXMLElement $node, string $path, array &$out): void
    {
        if ($node->count() === 0) {
            $out[$path] = (string) $node;
            return;
        }
        foreach ($node->children() as $child) {
            $this->walk($child, $path . '/' . $child->getName(), $out);
        }
    }

    /**
     * @param string $relative
     * @return \SimpleXMLElement
     */
    private function load(string $relative): \SimpleXMLElement
    {
        $xml = simplexml_load_file($this->root . '/' . $relative);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, $relative . ' does not parse');

        return $xml;
    }
}

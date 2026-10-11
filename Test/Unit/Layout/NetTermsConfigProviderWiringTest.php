<?php
/**
 * The "Pay on net terms" checkout config provider is registered in the FRONTEND area.
 *
 * Magento merges an area's DI arguments over the global ones per type with a non-recursive
 * array_replace (Magento\Framework\ObjectManager\Config\Config::_mergeConfiguration(), read in
 * Magento 2.4.8-p5), and Magento_Checkout registers its own `configProviders` on
 * Magento\Checkout\Model\CompositeConfigProvider in its etc/frontend/di.xml. A registration in
 * the global etc/di.xml is therefore replaced whole on the storefront: the renderer read an
 * undefined window.checkoutConfig.payment.tackquote_net_terms (devstore Q/A finding #19).
 *
 * Mutations (applied, red, restored):
 *   C1  move the <type> block back to etc/di.xml -> both tests
 *   C2  rename the item's class -> testTheProviderIsRegisteredForTheStorefront
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Layout;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class NetTermsConfigProviderWiringTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../..';
    private const COMPOSITE = 'Magento\Checkout\Model\CompositeConfigProvider';
    private const PROVIDER = 'TackQuote\Quotes\Model\Payment\NetTermsConfigProvider';

    /**
     * Every configProviders item declared on the composite provider in one file: name => class.
     *
     * @param string $relative
     * @return array<string, string>
     */
    private function providers(string $relative): array
    {
        $xml = simplexml_load_file(self::ROOT . '/' . $relative);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml, "$relative is not well-formed XML");

        $out = [];
        foreach ($xml->xpath('//type') ?: [] as $type) {
            if (ltrim((string) $type['name'], '\\') !== self::COMPOSITE) {
                continue;
            }
            foreach ($type->xpath('arguments/argument[@name="configProviders"]/item') ?: [] as $item) {
                $out[(string) $item['name']] = trim((string) $item);
            }
        }

        return $out;
    }

    public function testTheProviderIsRegisteredForTheStorefront(): void
    {
        self::assertSame(
            ['tackquote_net_terms_config_provider' => self::PROVIDER],
            $this->providers('etc/frontend/di.xml')
        );
        self::assertTrue(class_exists(self::PROVIDER), 'the registered class exists');
    }

    public function testTheGlobalFileDoesNotTouchTheCompositeProvider(): void
    {
        $global = (string) file_get_contents(self::ROOT . '/etc/di.xml');
        $xml = simplexml_load_string($global);
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $declared = [];
        foreach ($xml->xpath('//type') ?: [] as $type) {
            $declared[] = ltrim((string) $type['name'], '\\');
        }

        // Positive control: the reader does see the global file's own types.
        self::assertContains('TackQuote\Quotes\Model\Payment\NetTermsMethod', $declared);
        self::assertNotContains(
            self::COMPOSITE,
            $declared,
            'a global registration is replaced by Magento_Checkout\'s frontend one and never reaches the checkout'
        );
    }
}

<?php
/**
 * The full-page-cache and XSS properties of the B2B display, which live in XML, a
 * template and a script, and so are asserted against those files.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Layout;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class B2bDisplayLayoutTest extends TestCase
{
    private function read(string $relative): string
    {
        $path = dirname(__DIR__, 3) . '/' . $relative;
        self::assertFileExists($path);

        return (string) file_get_contents($path);
    }

    public function testThePlaceholderSitsUnderThePriceAsASiblingInProductInfoMain(): void
    {
        $xml = simplexml_load_string($this->read('view/frontend/layout/catalog_product_view.xml'));
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $blocks = $xml->xpath(
            '//referenceContainer[@name="product.info.main"]/block[@name="tackquote.b2b.display"]'
        ) ?: [];

        self::assertCount(1, $blocks);
        self::assertSame('product.info.price', (string) $blocks[0]['after']);
        self::assertSame(\TackQuote\Quotes\Block\B2bDisplay::class, (string) $blocks[0]['class']);
        self::assertNotSame('false', (string) $blocks[0]['cacheable'], 'it must not turn off FPC for the page');
    }

    public function testTheCachedTemplateRendersNothingAboutTheCustomer(): void
    {
        $phtml = $this->read('view/frontend/templates/b2b-display.phtml');
        $block = $this->read('Block/B2bDisplay.php');

        foreach ([$phtml, $block] as $source) {
            self::assertDoesNotMatchRegularExpression(
                '/customerSession|getCustomer|isLoggedIn|getEmail|DataProvider|StorefrontClient/',
                $source,
                'customer data in a full-page-cached block is served to the next visitor'
            );
        }
        self::assertStringContainsString(' hidden></div>', $phtml);
    }

    public function testTheScriptInsertsTextNeverHtml(): void
    {
        $js = $this->read('view/frontend/web/js/b2b-display.js');

        self::assertStringContainsString('textContent', $js);
        self::assertDoesNotMatchRegularExpression('/innerHTML|outerHTML|insertAdjacentHTML|\.html\(|\$\(/', $js);
        self::assertStringContainsString("method: 'POST'", $js, 'a GET would be cacheable');
        self::assertStringContainsString("'form_key'", $js);
    }
}

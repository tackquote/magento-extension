<?php
/**
 * The storefront reads the styling settings back through the same rules, and the layout
 * hands them to the templates.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\ViewModel;

use Magento\Framework\App\Config\ScopeConfigInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Styling\StyleRules;
use TackQuote\Quotes\ViewModel\Styling;

/**
 * @covers \TackQuote\Quotes\ViewModel\Styling
 */
class StylingTest extends TestCase
{
    /**
     * @param array<string, string|null> $values Field id => stored value.
     * @param bool $configured
     * @return Styling
     */
    private function styling(array $values, bool $configured = true): Styling
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('getValue')->willReturnCallback(static function ($path) use ($values) {
            $field = substr((string) $path, strlen('tackquote_quotes/styling/'));

            return $values[$field] ?? null;
        });
        $config = $this->createMock(Config::class);
        $config->method('isConfigured')->willReturn($configured);

        return new Styling($scope, $config, new StyleRules());
    }

    public function testNothingSavedIsTheLookTheStorefrontAlwaysHad(): void
    {
        $styling = $this->styling([]);

        self::assertSame('', $styling->getAccentColor());
        self::assertSame('filled', $styling->getButtonStyle());
        self::assertSame('right', $styling->getLauncherPosition());
        self::assertSame(20, $styling->getLauncherOffset());
        self::assertSame('regular', $styling->getLauncherSize());
        self::assertSame('Quote list', $styling->getLauncherLabel());
        self::assertSame('', $styling->getLauncherClasses());
        self::assertFalse($styling->hasCustomCss());
        self::assertSame('', $styling->getInlineCss());
    }

    public function testSavedValuesReachTheTemplates(): void
    {
        $styling = $this->styling([
            'accent_color' => '#C00',
            'button_style' => 'outlined',
            'launcher_position' => 'left',
            'launcher_offset' => '32',
            'launcher_label' => 'My quote',
            'launcher_size' => 'compact',
            'custom_css' => '.x{}',
        ]);

        self::assertSame('#c00', $styling->getAccentColor());
        self::assertSame('outlined', $styling->getButtonStyle());
        self::assertSame(32, $styling->getLauncherOffset());
        self::assertSame('My quote', $styling->getLauncherLabel());
        self::assertSame('tackquote-app--launcher-left tackquote-app--launcher-compact', $styling->getLauncherClasses());
        self::assertTrue($styling->hasCustomCss());
        self::assertStringStartsWith(
            ':root{--tackquote-launcher-offset:32px;--tackquote-accent:#c00;--tackquote-accent-text:#fff}',
            $styling->getInlineCss()
        );
        self::assertStringEndsWith("\n.x{}", $styling->getInlineCss());
    }

    public function testValuesWrittenAroundTheAdminFormAreCheckedOnRead(): void
    {
        $styling = $this->styling([
            'accent_color' => 'red;}</style>',
            'button_style' => 'glow',
            'launcher_position' => 'top',
            'launcher_offset' => '-1',
            'launcher_size' => 'huge',
            'custom_css' => '</style><script>',
        ]);

        self::assertSame('', $styling->getAccentColor());
        self::assertSame('filled', $styling->getButtonStyle());
        self::assertSame('right', $styling->getLauncherPosition());
        self::assertSame(20, $styling->getLauncherOffset());
        self::assertSame('regular', $styling->getLauncherSize());
        self::assertStringNotContainsString('<', $styling->getInlineCss());
    }

    public function testNoCssWhileNoTackQuoteControlCanRender(): void
    {
        self::assertSame('', $this->styling(['accent_color' => '#c00'], false)->getInlineCss());
    }

    public function testTheLayoutHandsTheViewModelToBothTemplates(): void
    {
        $xml = simplexml_load_file(dirname(__DIR__, 3) . '/view/frontend/layout/default.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $xml);

        $head = $xml->xpath("//referenceBlock[@name='head.additional']/block[@name='tackquote.styling']");
        self::assertCount(1, $head, 'the <style> block sits in the page head');
        self::assertSame('TackQuote_Quotes::styling.phtml', (string) $head[0]['template']);

        foreach (['tackquote.styling', 'tackquote.quote.list'] as $name) {
            $argument = $xml->xpath("//block[@name='$name']/arguments/argument[@name='styling_view_model']");
            self::assertCount(1, $argument, $name . ' gets the styling view model');
            self::assertSame('object', (string) $argument[0]->attributes('xsi', true)['type']);
            self::assertSame(Styling::class, trim((string) $argument[0]));
        }
    }

    public function testTheStyleElementGoesThroughTheSecureRenderer(): void
    {
        $template = (string) file_get_contents(dirname(__DIR__, 3) . '/view/frontend/templates/styling.phtml');

        self::assertStringContainsString('$styling->getInlineCss()', $template);
        self::assertStringContainsString("\$secureRenderer->renderTag('style'", $template);
        self::assertStringNotContainsString('<style', str_replace('<style> element', '', $template));
    }
}

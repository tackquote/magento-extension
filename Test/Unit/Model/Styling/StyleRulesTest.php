<?php
/**
 * The styling validators and the CSS they produce.
 *
 * The two that matter most for safety: a hex colour is the only colour that reaches the CSS,
 * and the custom CSS can never end the <style> element it is printed in.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Styling;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Styling\StyleRules;

/**
 * @covers \TackQuote\Quotes\Model\Styling\StyleRules
 */
class StyleRulesTest extends TestCase
{
    /** @var StyleRules */
    private $rules;

    protected function setUp(): void
    {
        $this->rules = new StyleRules();
    }

    // ------------------------------------------------------------------ hex colour

    /**
     * @dataProvider hexColours
     */
    public function testAHexColourIsKeptInLowerCase(string $in, string $out): void
    {
        self::assertSame($out, $this->rules->normalizeHex($in));
    }

    /**
     * @return array<string, array{0: string, 1: string}>
     */
    public static function hexColours(): array
    {
        return [
            'six digits' => ['#1E73BE', '#1e73be'],
            'three digits' => ['#FA0', '#fa0'],
            'trimmed' => ['  #00ff00 ', '#00ff00'],
            'trailing newline trimmed' => ["#fff\n", '#fff'],
            'empty means the theme' => ['', ''],
            'blank means the theme' => ['   ', ''],
        ];
    }

    /**
     * @dataProvider notHexColours
     */
    public function testAnythingElseIsRefused(string $in): void
    {
        self::assertNull($this->rules->normalizeHex($in));
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function notHexColours(): array
    {
        return [
            'no hash' => ['1e73be'],
            'named colour' => ['red'],
            'four digits' => ['#1e73'],
            'eight digits' => ['#1e73be80'],
            'not hex' => ['#gggggg'],
            'css injection' => ['#fff;}body{display:none'],
            'style break-out' => ['#fff</style><script>'],
            'function' => ['rgb(0,0,0)'],
            'newline inside' => ["#ff\nf"],
            'newline before a second value' => ["#fff\n#000"],
        ];
    }

    // ------------------------------------------------------------------ offset

    /**
     * @dataProvider offsets
     */
    public function testTheOffsetIsAWholeNumberOfPixelsInRange(string $in, bool $valid, int $used): void
    {
        self::assertSame($valid, $this->rules->isValidOffset($in));
        self::assertSame($used, $this->rules->offset($in));
    }

    /**
     * @return array<string, array{0: string, 1: bool, 2: int}>
     */
    public static function offsets(): array
    {
        return [
            'zero' => ['0', true, 0],
            'default' => ['20', true, 20],
            'max' => ['200', true, 200],
            'over max' => ['201', false, 20],
            'negative' => ['-5', false, 20],
            'decimal' => ['12.5', false, 20],
            'unit' => ['20px', false, 20],
            'empty' => ['', false, 20],
            'injection' => ['20px;}body{x:y', false, 20],
        ];
    }

    // ------------------------------------------------------------------ custom CSS

    public function testTheCustomCssCanNeverEndTheStyleElement(): void
    {
        $out = $this->rules->sanitizeCustomCss(
            '.a{color:red}</style><script>alert(1)</script><!-- x --></STYLE >.b{}'
        );

        self::assertStringNotContainsString('<', $out);
        self::assertSame('.a{color:red}/style>script>alert(1)/script>!-- x -->/STYLE >.b{}', $out);
    }

    public function testTheCustomCssIsCappedAndCleaned(): void
    {
        $long = str_repeat('a', StyleRules::CUSTOM_CSS_MAX + 50);
        self::assertSame(StyleRules::CUSTOM_CSS_MAX, mb_strlen($this->rules->sanitizeCustomCss($long)));

        self::assertSame('.a{}', $this->rules->sanitizeCustomCss(".a\0{}"));
        self::assertTrue(mb_check_encoding($this->rules->sanitizeCustomCss(".a{content:'\xC3'}"), 'UTF-8'));
        // Multibyte text is cut by character, never inside one.
        $wide = str_repeat('é', StyleRules::CUSTOM_CSS_MAX + 1);
        self::assertSame(StyleRules::CUSTOM_CSS_MAX, mb_strlen($this->rules->sanitizeCustomCss($wide), 'UTF-8'));
    }

    public function testTheSaveCheckNamesWhatIsWrong(): void
    {
        self::assertNull($this->rules->customCssProblem('.tackquote-request-btn { border-radius: 0; }'));
        self::assertSame(StyleRules::CSS_HAS_LT, $this->rules->customCssProblem('.a{}</style>'));
        self::assertSame(
            StyleRules::CSS_TOO_LONG,
            $this->rules->customCssProblem(str_repeat('a', StyleRules::CUSTOM_CSS_MAX + 1))
        );
        self::assertNull($this->rules->customCssProblem(str_repeat('a', StyleRules::CUSTOM_CSS_MAX)));
    }

    // ------------------------------------------------------------------ launcher label

    public function testTheLauncherLabelIsCutAndHasNoControlCharacters(): void
    {
        self::assertSame('Bulk order list', $this->rules->launcherLabel("  Bulk\norder list "));
        self::assertSame(
            StyleRules::LAUNCHER_LABEL_MAX,
            mb_strlen($this->rules->launcherLabel(str_repeat('x', 60)))
        );
        self::assertSame('', $this->rules->launcherLabel('   '));
    }

    // ------------------------------------------------------------------ text colour

    public function testTheTextColourIsTheMoreReadableOfBlackAndWhite(): void
    {
        self::assertSame('#fff', $this->rules->readableTextOn('#1e73be'));
        self::assertSame('#fff', $this->rules->readableTextOn('#000'));
        self::assertSame('#000', $this->rules->readableTextOn('#ffff00'));
        self::assertSame('#000', $this->rules->readableTextOn('#FFF'));
    }

    // ------------------------------------------------------------------ the CSS

    public function testDefaultsProduceNoCssAtAll(): void
    {
        self::assertSame('', $this->rules->buildCss('', StyleRules::BUTTON_FILLED, '20', ''));
        self::assertSame('', $this->rules->buildCss('', 'nonsense', 'nonsense', '   '));
    }

    public function testAnAccentRepaintsTheButtonsWithReadableText(): void
    {
        $css = $this->rules->buildCss('#1E73BE', StyleRules::BUTTON_FILLED, '20', '');

        self::assertStringStartsWith(':root{--tackquote-accent:#1e73be;--tackquote-accent-text:#fff}', $css);
        self::assertStringContainsString('html:root body .action.tackquote-request-btn.tocart,', $css);
        self::assertStringContainsString('{background:var(--tackquote-accent);', $css);
        self::assertStringContainsString('.tackquote-b2b .tackquote-b2b__account-price{color:var(--tackquote-accent)}', $css);
        self::assertStringNotContainsString('!important', $css);
    }

    public function testAnInvalidAccentIsDroppedOnRead(): void
    {
        // A value written around the admin form (config:set, a database import).
        $css = $this->rules->buildCss('#fff;}body{display:none}', StyleRules::BUTTON_FILLED, '20', '');

        self::assertSame('', $css);
    }

    public function testOutlinedButtonsWorkWithAndWithoutAnAccent(): void
    {
        $plain = $this->rules->buildCss('', StyleRules::BUTTON_OUTLINED, '20', '');
        self::assertStringNotContainsString(':root{', $plain);
        self::assertStringContainsString('border:1px solid var(--tackquote-accent,currentColor)', $plain);

        $accented = $this->rules->buildCss('#c00', StyleRules::BUTTON_OUTLINED, '20', '');
        self::assertStringContainsString('--tackquote-accent:#c00', $accented);
        self::assertStringContainsString('background:transparent;border:1px solid var(--tackquote-accent,currentColor)', $accented);
    }

    public function testTheOffsetVariableIsPrintedOnlyWhenItDiffers(): void
    {
        self::assertSame(':root{--tackquote-launcher-offset:48px}', $this->rules->buildCss('', 'filled', '48', ''));
        self::assertSame(':root{--tackquote-launcher-offset:0px}', $this->rules->buildCss('', 'filled', 0, ''));
        self::assertSame('', $this->rules->buildCss('', 'filled', '999', ''));
    }

    public function testCustomCssComesLastAndSanitised(): void
    {
        $css = $this->rules->buildCss('#000', 'filled', '20', '.x{color:red}</style><b>');

        self::assertStringEndsWith("\n.x{color:red}/style>b>", $css);
        self::assertStringNotContainsString('<', $css);
        self::assertSame('.only{}', $this->rules->buildCss('', 'filled', '20', '.only{}'));
    }

    public function testOneOfFallsBackToTheDefault(): void
    {
        self::assertSame('left', $this->rules->oneOf('left', ['right', 'left'], 'right'));
        self::assertSame('right', $this->rules->oneOf('LEFT', ['right', 'left'], 'right'));
        self::assertSame('right', $this->rules->oneOf(null, ['right', 'left'], 'right'));
    }
}

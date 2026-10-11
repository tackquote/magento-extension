<?php
/**
 * The admin refuses a styling value it cannot use, and names what is wrong.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Config\Backend;

use Magento\Framework\Exception\LocalizedException;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config\Backend\StylingValue;
use TackQuote\Quotes\Model\Styling\StyleRules;

/**
 * @covers \TackQuote\Quotes\Model\Config\Backend\StylingValue
 */
class StylingValueTest extends TestCase
{
    /** @var StylingValue */
    private $backend;

    protected function setUp(): void
    {
        // check() needs nothing the framework constructor provides.
        $this->backend = (new \ReflectionClass(StylingValue::class))->newInstanceWithoutConstructor();
    }

    public function testAHexAccentIsSavedInLowerCaseAndEmptyIsAllowed(): void
    {
        self::assertSame('#1e73be', $this->backend->check('accent_color', ' #1E73BE '));
        self::assertSame('', $this->backend->check('accent_color', ''));
    }

    public function testAnythingElseAsTheAccentIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('The accent color must be a hex color such as #1e73be');

        $this->backend->check('accent_color', 'red');
    }

    public function testACssInjectionAsTheAccentIsRefused(): void
    {
        $this->expectException(LocalizedException::class);

        $this->backend->check('accent_color', '#fff;}body{display:none');
    }

    public function testTheOffsetMustBeAWholeNumberInRange(): void
    {
        self::assertSame('0', $this->backend->check('launcher_offset', '0'));
        self::assertSame('200', $this->backend->check('launcher_offset', ' 200 '));
        self::assertSame((string) StyleRules::OFFSET_DEFAULT, $this->backend->check('launcher_offset', ''));

        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('from 0 to 200');
        $this->backend->check('launcher_offset', '201');
    }

    public function testTheLauncherLabelIsTidiedNotRefused(): void
    {
        self::assertSame('Bulk list', $this->backend->check('launcher_label', " Bulk\tlist "));
    }

    public function testCustomCssWithALessThanSignIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('cannot contain the "<" character');

        $this->backend->check('custom_css', '.a{}</style><script>alert(1)</script>');
    }

    public function testCustomCssOverTheCapIsRefused(): void
    {
        $this->expectException(LocalizedException::class);
        $this->expectExceptionMessage('at most 10000 characters');

        $this->backend->check('custom_css', str_repeat('a', StyleRules::CUSTOM_CSS_MAX + 1));
    }

    public function testOrdinaryCustomCssIsSavedTrimmed(): void
    {
        self::assertSame(
            '.tackquote-request-btn { border-radius: 0; }',
            $this->backend->check('custom_css', "\n.tackquote-request-btn { border-radius: 0; }\n")
        );
    }

    public function testAnotherFieldPassesThrough(): void
    {
        self::assertSame('anything', $this->backend->check('something_else', 'anything'));
    }
}

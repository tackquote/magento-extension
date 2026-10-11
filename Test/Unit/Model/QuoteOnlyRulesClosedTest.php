<?php
/**
 * QuoteOnlyRules stays closed to extension without the `final` keyword.
 *
 * The class used to be declared `final` so the quote-only rule could only ever live in one
 * place. The Magento coding standard forbids `final` (Magento2.PHP.FinalImplementation, an
 * error, because a final class cannot be proxied or intercepted), so the keyword is gone and
 * this test keeps the intent instead: no class in the module may extend QuoteOnlyRules. A
 * subclass would be a second copy of the rule, which is the drift the class exists to stop.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use ReflectionClass;
use TackQuote\Quotes\Model\QuoteOnlyRules;

/**
 * @coversNothing
 */
class QuoteOnlyRulesClosedTest extends TestCase
{
    public function testTheClassIsNotFinalSoTheStandardIsMet(): void
    {
        self::assertFalse((new ReflectionClass(QuoteOnlyRules::class))->isFinal());
    }

    public function testNoClassInTheModuleExtendsIt(): void
    {
        $root = dirname(__DIR__, 3);
        $offenders = [];
        $scanned = 0;
        $files = new RecursiveIteratorIterator(
            new RecursiveDirectoryIterator($root, RecursiveDirectoryIterator::SKIP_DOTS)
        );
        foreach ($files as $file) {
            $path = $file->getPathname();
            $relative = substr($path, strlen($root) + 1);
            if ($file->getExtension() !== 'php'
                || preg_match('#^(vendor|Test|\.git)/#', $relative) === 1
            ) {
                continue;
            }
            $scanned++;
            $source = (string) file_get_contents($path);
            $pattern = '/\bextends\s+\\\\?(?:TackQuote\\\\Quotes\\\\Model\\\\)?QuoteOnlyRules\b/';
            if (preg_match($pattern, $source) === 1) {
                $offenders[] = $relative;
            }
        }

        self::assertGreaterThan(50, $scanned, 'the scan must actually read the module source');
        self::assertSame([], $offenders, 'QuoteOnlyRules is the one copy of the rule; do not subclass it');
    }
}

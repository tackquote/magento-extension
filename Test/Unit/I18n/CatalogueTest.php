<?php
/**
 * The translation catalogues: i18n/en_US.csv names every phrase the module translates, and
 * every other locale translates exactly that set.
 *
 * Magento reads a module's i18n/<locale>.csv as two columns, the English phrase and its
 * translation, and looks phrases up by their exact text. So a phrase missing from a locale
 * file silently shows English, a phrase whose placeholders (%1, %2) differ from the English
 * one prints the wrong value or a raw "%1", and a duplicate key leaves which translation wins
 * to load order. Each is checked here, for every locale the module ships. The locale list
 * matches the WooCommerce plugin's languages/ directory (de_DE, es_ES, fr_FR, it_IT, ja,
 * nl_NL, pt_BR), with Japanese under Magento's code ja_JP.
 *
 * The inventory half reads the module's own source: every __('...') and $t('...') literal in
 * PHP, PHTML, JS and Knockout templates, every system.xml label and group comment, and every
 * menu and ACL title must be a key in en_US.csv, or it can never be translated.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\I18n;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class CatalogueTest extends TestCase
{
    /** Every locale the module ships besides en_US. */
    private const LOCALES = ['de_DE', 'es_ES', 'fr_FR', 'it_IT', 'ja_JP', 'nl_NL', 'pt_BR'];

    /** Source directories scanned for phrases. Test/ is not shipped and is not scanned. */
    private const SOURCE_DIRS = [
        'Block',
        'Controller',
        'Cron',
        'Model',
        'Observer',
        'Plugin',
        'Setup',
        'ViewModel',
        'view'
    ];

    /** @var string */
    private $root;

    protected function setUp(): void
    {
        $this->root = dirname(__DIR__, 3);
    }

    // ------------------------------------------------------------------ catalogues

    public function testTheShippedLocalesAreExactlyTheExpectedOnes(): void
    {
        $files = glob($this->root . '/i18n/*.csv') ?: [];
        $found = array_map(static fn (string $file): string => basename($file, '.csv'), $files);
        sort($found);

        $expected = array_merge(['en_US'], self::LOCALES);
        sort($expected);

        self::assertSame($expected, $found);
    }

    public function testEnglishTranslatesEveryPhraseToItself(): void
    {
        $rows = $this->read('en_US');

        self::assertGreaterThan(300, count($rows), 'en_US.csv is unexpectedly small: the reader is broken');
        foreach ($rows as $key => $value) {
            self::assertSame($key, $value, 'en_US.csv must map a phrase to itself: ' . $key);
        }
    }

    /**
     * @dataProvider locales
     */
    public function testEveryLocaleHasExactlyTheEnglishKeys(string $locale): void
    {
        $english = array_keys($this->read('en_US'));
        $translated = array_keys($this->read($locale));

        self::assertSame(
            [],
            array_values(array_diff($english, $translated)),
            $locale . ': phrases with no translation'
        );
        self::assertSame(
            [],
            array_values(array_diff($translated, $english)),
            $locale . ': phrases en_US.csv does not have'
        );
        self::assertCount(count($english), $translated);
    }

    /**
     * @dataProvider locales
     */
    public function testEveryTranslationKeepsThePlaceholdersAndMarkup(string $locale): void
    {
        foreach ($this->read($locale) as $key => $value) {
            self::assertNotSame('', trim($value), $locale . ': empty translation for ' . $key);
            self::assertSame(
                $this->placeholders($key),
                $this->placeholders($value),
                $locale . ': the placeholders differ for ' . $key
            );
            self::assertSame($this->tags($key), $this->tags($value), $locale . ': the HTML differs for ' . $key);
        }
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function locales(): array
    {
        $out = [];
        foreach (self::LOCALES as $locale) {
            $out[$locale] = [$locale];
        }

        return $out;
    }

    // ------------------------------------------------------------------ inventory

    public function testEveryPhraseTheSourceTranslatesIsInEnglish(): void
    {
        $phrases = $this->sourcePhrases();
        $english = $this->read('en_US');

        // Positive controls: a reader that matches nothing would pass the check below.
        self::assertGreaterThan(250, count($phrases), 'too few phrases found: the reader is broken');
        self::assertArrayHasKey('Request a Quote', $phrases, 'a PHP __() literal');
        self::assertArrayHasKey('Testing…', $phrases, 'a $t() literal inside a PHTML script');
        self::assertArrayHasKey('Place Order', $phrases, 'a Knockout i18n binding');
        self::assertArrayHasKey('Storefront: styling', $phrases, 'a system.xml group label');
        self::assertArrayHasKey('Overview', $phrases, 'a menu title');

        $missing = [];
        foreach ($phrases as $phrase => $where) {
            if (preg_match('/^%\d+$/', $phrase) === 1) {
                continue; // A bare placeholder has nothing to translate.
            }
            if (!array_key_exists($phrase, $english)) {
                $missing[] = $phrase . '  (' . $where . ')';
            }
        }

        self::assertSame([], $missing, 'phrases missing from i18n/en_US.csv');
    }

    public function testOnlyLiteralsAreTranslated(): void
    {
        // __($variable) cannot be found by any phrase collector, so it can never be translated.
        self::assertSame([], $this->nonLiteralCalls());
    }

    /**
     * Every translatable phrase in the module's source, phrase => first place it was found.
     *
     * @return array<string, string>
     */
    private function sourcePhrases(): array
    {
        $out = [];
        foreach ($this->files() as $file) {
            $relative = substr($file, strlen($this->root) + 1);
            $source = (string) file_get_contents($file);
            $extension = pathinfo($file, PATHINFO_EXTENSION);

            if ($extension === 'php' || $extension === 'phtml') {
                foreach ($this->phpCalls($source) as $phrase) {
                    $out[$phrase] = $out[$phrase] ?? $relative;
                }
            }
            if ($extension === 'js' || $extension === 'phtml') {
                foreach ($this->jsCalls($source) as $phrase) {
                    $out[$phrase] = $out[$phrase] ?? $relative;
                }
            }
            if ($extension === 'html') {
                preg_match_all("/i18n:\s*'((?:[^'\\\\]|\\\\.)*)'/", $source, $matches);
                foreach ($matches[1] as $raw) {
                    $phrase = $this->unescape($raw);
                    $out[$phrase] = $out[$phrase] ?? $relative;
                }
            }
        }

        foreach ($this->xmlPhrases() as $phrase => $where) {
            $out[$phrase] = $out[$phrase] ?? $where;
        }

        return $out;
    }

    /**
     * The literal first argument of every __() call, read with PHP's own tokenizer.
     *
     * @param string $source
     * @return string[]
     */
    private function phpCalls(string $source): array
    {
        $out = [];
        $tokens = token_get_all($source);
        $count = count($tokens);
        for ($i = 0; $i < $count; $i++) {
            if (!$this->isTranslateCall($tokens, $i)) {
                continue;
            }
            $j = $this->next($tokens, $i + 1);
            if ($j === null || $tokens[$j] !== '(') {
                continue;
            }
            $phrase = '';
            $literal = false;
            for ($k = $this->next($tokens, $j + 1); $k !== null; $k = $this->next($tokens, $k + 1)) {
                $token = $tokens[$k];
                if (is_array($token) && $token[0] === T_CONSTANT_ENCAPSED_STRING) {
                    $phrase .= $this->phpLiteral($token[1]);
                    $literal = true;
                    continue;
                }
                if ($token === '.') {
                    continue;
                }
                break;
            }
            if ($literal) {
                $out[] = $phrase;
            }
        }

        return $out;
    }

    /**
     * Every __() call whose first argument is not a string literal, as "file: line".
     *
     * @return string[]
     */
    private function nonLiteralCalls(): array
    {
        $out = [];
        foreach ($this->files() as $file) {
            if (!in_array(pathinfo($file, PATHINFO_EXTENSION), ['php', 'phtml'], true)) {
                continue;
            }
            $tokens = token_get_all((string) file_get_contents($file));
            $count = count($tokens);
            for ($i = 0; $i < $count; $i++) {
                if (!$this->isTranslateCall($tokens, $i)) {
                    continue;
                }
                $j = $this->next($tokens, $i + 1);
                if ($j === null || $tokens[$j] !== '(') {
                    continue;
                }
                $k = $this->next($tokens, $j + 1);
                if ($k === null || !is_array($tokens[$k]) || $tokens[$k][0] !== T_CONSTANT_ENCAPSED_STRING) {
                    $out[] = substr(
                        $file,
                        strlen($this->root) + 1
                    ) . ': ' . (is_array($tokens[$i]) ? $tokens[$i][2] : '?');
                }
            }
        }

        return $out;
    }

    /**
     * Whether token $i is a call to the global __() (not a method or a declaration).
     *
     * @param array<int, mixed> $tokens
     * @param int $i
     * @return bool
     */
    private function isTranslateCall(array $tokens, int $i): bool
    {
        $token = $tokens[$i];
        if (!is_array($token) || $token[0] !== T_STRING || $token[1] !== '__') {
            return false;
        }
        $previous = $this->previous($tokens, $i - 1);
        if ($previous === null) {
            return true;
        }
        $before = $tokens[$previous];

        return !(is_array($before) && in_array($before[0], [T_OBJECT_OPERATOR, T_DOUBLE_COLON, T_FUNCTION], true));
    }

    /**
     * Every literal $t('...') / $t("...") and $.mage.__('...') in JavaScript.
     *
     * @param string $source
     * @return string[]
     */
    private function jsCalls(string $source): array
    {
        $out = [];
        $pattern = '/(?:\$t|\$\.mage\.__)\(\s*(?:\'((?:[^\'\\\\]|\\\\.)*)\'|"((?:[^"\\\\]|\\\\.)*)")\s*\)/';
        preg_match_all($pattern, $source, $matches, PREG_SET_ORDER);
        foreach ($matches as $match) {
            $out[] = $this->unescape(($match[1] ?? '') !== '' ? $match[1] : ($match[2] ?? ''));
        }

        return $out;
    }

    /**
     * system.xml labels and group comments marked translate, and menu and ACL titles.
     *
     * Field comments are left out on purpose: they are long and wrapped over several lines,
     * and Magento looks a comment up by its exact text, indentation included, so they stay
     * English (README, Known limitations).
     *
     * @return array<string, string>
     */
    private function xmlPhrases(): array
    {
        $out = [];
        $system = simplexml_load_file($this->root . '/etc/adminhtml/system.xml');
        self::assertInstanceOf(\SimpleXMLElement::class, $system);
        foreach ($system->xpath('//tab | //section | //group | //field') ?: [] as $node) {
            $translate = preg_split('/\s+/', (string) $node['translate']) ?: [];
            if (in_array('label', $translate, true) && (string) $node->label !== '') {
                $out[(string) $node->label] = 'etc/adminhtml/system.xml';
            }
            if ($node->getName() === 'group' && in_array(
                'comment',
                $translate,
                true
            ) && (string) $node->comment !== '') {
                $out[(string) $node->comment] = 'etc/adminhtml/system.xml';
            }
        }
        foreach (['etc/adminhtml/menu.xml', 'etc/acl.xml'] as $file) {
            $xml = simplexml_load_file($this->root . '/' . $file);
            self::assertInstanceOf(\SimpleXMLElement::class, $xml);
            foreach ($xml->xpath('//*[@title and @translate]') ?: [] as $node) {
                $out[(string) $node['title']] = $file;
            }
        }

        return $out;
    }

    /**
     * One locale file as key => translation, refusing anything Magento would misread.
     *
     * @param string $locale
     * @return array<string, string>
     */
    private function read(string $locale): array
    {
        $path = $this->root . '/i18n/' . $locale . '.csv';
        $bytes = (string) file_get_contents($path);
        self::assertTrue(mb_check_encoding($bytes, 'UTF-8'), $locale . '.csv is not valid UTF-8');
        self::assertStringStartsNotWith("\xEF\xBB\xBF", $bytes, $locale . '.csv starts with a byte order mark');

        $handle = fopen($path, 'rb');
        self::assertIsResource($handle);
        $out = [];
        $line = 0;
        while (($row = fgetcsv($handle, 0, ',', '"', '')) !== false) {
            $line++;
            self::assertCount(2, $row, sprintf('%s.csv row %d does not have exactly two columns', $locale, $line));
            [$key, $value] = $row;
            self::assertNotSame('', (string) $key, sprintf('%s.csv row %d has an empty phrase', $locale, $line));
            self::assertNotSame('', (string) $value, sprintf('%s.csv row %d has an empty translation', $locale, $line));
            self::assertArrayNotHasKey($key, $out, sprintf('%s.csv repeats the phrase "%s"', $locale, $key));
            $out[(string) $key] = (string) $value;
        }
        fclose($handle);

        return $out;
    }

    /**
     * @param string $text
     * @return string[]
     */
    private function placeholders(string $text): array
    {
        preg_match_all('/%\d+/', $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }

    /**
     * @param string $text
     * @return string[]
     */
    private function tags(string $text): array
    {
        preg_match_all('#</?[a-z]+>#', $text, $matches);
        $found = $matches[0];
        sort($found);

        return $found;
    }

    /**
     * The value of a PHP string literal token.
     *
     * @param string $token
     * @return string
     */
    private function phpLiteral(string $token): string
    {
        $quote = $token[0];
        $body = substr($token, 1, -1);

        return $quote === "'" ? str_replace(['\\\\', "\\'"], ['\\', "'"], $body) : stripcslashes($body);
    }

    /**
     * @param string $raw
     * @return string
     */
    private function unescape(string $raw): string
    {
        return (string) preg_replace('/\\\\([\'"\\\\])/', '$1', $raw);
    }

    /**
     * @param array<int, mixed> $tokens
     * @param int $from
     * @return int|null The next token index that is not whitespace or a comment.
     */
    private function next(array $tokens, int $from): ?int
    {
        $count = count($tokens);
        for ($i = $from; $i < $count; $i++) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $tokens
     * @param int $from
     * @return int|null The previous token index that is not whitespace or a comment.
     */
    private function previous(array $tokens, int $from): ?int
    {
        for ($i = $from; $i >= 0; $i--) {
            if (!is_array($tokens[$i]) || !in_array($tokens[$i][0], [T_WHITESPACE, T_COMMENT, T_DOC_COMMENT], true)) {
                return $i;
            }
        }

        return null;
    }

    /**
     * Every PHP, PHTML, JS and HTML file under the source directories.
     *
     * @return string[]
     */
    private function files(): array
    {
        $out = [];
        foreach (self::SOURCE_DIRS as $dir) {
            $base = $this->root . '/' . $dir;
            if (!is_dir($base)) {
                continue;
            }
            $iterator = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator(
                $base,
                \FilesystemIterator::SKIP_DOTS
            ));
            foreach ($iterator as $file) {
                if (in_array($file->getExtension(), ['php', 'phtml', 'js', 'html'], true)) {
                    $out[] = $file->getPathname();
                }
            }
        }
        sort($out);
        self::assertNotEmpty($out);

        return $out;
    }
}

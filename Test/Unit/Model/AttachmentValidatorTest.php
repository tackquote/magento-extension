<?php
/**
 * The attachment checks that run before any byte leaves the store.
 *
 * Real files on disk, so the sniffing is exercised on real bytes; only is_uploaded_file() is
 * replaced, because a unit test cannot make PHP receive an upload.
 *
 * Mutations (applied, red, restored):
 *   A1  drop the `$sniffed !== $byExtension` agreement -> testARenamedFileIsRefused
 *   A2  `count($files) + $alreadyAttached` without the already-attached count -> testAFourthFileAcrossRequestsIsRefused
 *   A3  check only the declared size, not the size on disk -> testTheSizeOnDiskDecides
 *   A4  drop the finfo agreement check -> testFinfoMustAgreeWithTheSignature
 *   A5  drop the isUploadedFile() check -> testAPathThatWasNotUploadedIsRefused
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\AttachmentValidator;

/**
 * @covers \TackQuote\Quotes\Model\AttachmentValidator
 */
class AttachmentValidatorTest extends TestCase
{
    /** @var string[] */
    private $paths = [];

    /** @var string[] Paths the fake treats as this request's uploads. */
    private $uploaded = [];

    /** @var string|null What the fake's finfo reports; null = ask the real finfo. */
    private $finfoSays = null;

    protected function tearDown(): void
    {
        foreach ($this->paths as $path) {
            if (is_file($path)) {
                unlink($path);
            }
        }
        $this->paths = [];
        $this->uploaded = [];
        $this->finfoSays = null;
    }

    private function validator(): AttachmentValidator
    {
        $uploaded = &$this->uploaded;
        $finfoSays = &$this->finfoSays;

        return new class ($uploaded, $finfoSays) extends AttachmentValidator {
            /** @var string[] */
            private $uploaded;

            /** @var string|null */
            private $finfoSays;

            /**
             * @param string[] $uploaded
             * @param string|null $finfoSays
             */
            public function __construct(array &$uploaded, &$finfoSays)
            {
                $this->uploaded = &$uploaded;
                $this->finfoSays = &$finfoSays;
            }

            protected function isUploadedFile(string $path): bool
            {
                return in_array($path, $this->uploaded, true);
            }

            protected function finfoMime(string $path): ?string
            {
                return $this->finfoSays ?? parent::finfoMime($path);
            }
        };
    }

    /**
     * A temp file with these bytes, registered as uploaded.
     *
     * @param string $bytes
     * @param string $name
     * @return array{name: string, tmp_name: string, size: int, error: int}
     */
    private function upload(string $bytes, string $name): array
    {
        $path = (string) tempnam(sys_get_temp_dir(), 'tqv');
        file_put_contents($path, $bytes);
        $this->paths[] = $path;
        $this->uploaded[] = $path;

        return ['name' => $name, 'tmp_name' => $path, 'size' => strlen($bytes), 'error' => 0];
    }

    private static function pdf(): string
    {
        return "%PDF-1.4\n1 0 obj\n<< /Type /Catalog >>\nendobj\ntrailer\n<< /Root 1 0 R >>\n%%EOF\n";
    }

    private static function png(): string
    {
        // A complete 1x1 PNG, so finfo agrees as well.
        return (string) base64_decode(
            'iVBORw0KGgoAAAANSUhEUgAAAAEAAAABCAYAAAAfFcSJAAAADUlEQVR42mNkYPhfDwAChwGA60e6kgAAAABJRU5ErkJggg=='
        );
    }

    public function testAPdfAndAPngPass(): void
    {
        $result = $this->validator()->validate([
            $this->upload(self::pdf(), 'spec sheet.pdf'),
            $this->upload(self::png(), 'drawing.PNG'),
        ]);

        self::assertTrue($result['ok']);
        self::assertSame('application/pdf', $result['files'][0]['mime']);
        self::assertSame('image/png', $result['files'][1]['mime']);
        self::assertSame('spec sheet.pdf', $result['files'][0]['name']);
    }

    public function testARenamedFileIsRefused(): void
    {
        $result = $this->validator()->validate([$this->upload(self::png(), 'invoice.pdf')]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('attach a PDF, JPEG or PNG file', $result['message']);
    }

    public function testAPlainTextFileIsRefusedWhateverItIsCalled(): void
    {
        $result = $this->validator()->validate([$this->upload("<?php echo 'x';\n", 'shell.png')]);

        self::assertFalse($result['ok']);
    }

    public function testFinfoMustAgreeWithTheSignature(): void
    {
        $this->finfoSays = 'text/html';

        self::assertFalse($this->validator()->validate([$this->upload(self::pdf(), 'a.pdf')])['ok']);
    }

    public function testAnUnacceptedExtensionIsRefusedEvenWithGoodBytes(): void
    {
        $result = $this->validator()->validate([$this->upload(self::pdf(), 'sheet.exe')]);

        self::assertFalse($result['ok']);
    }

    public function testFourFilesAreRefused(): void
    {
        $files = [];
        for ($i = 0; $i < 4; $i++) {
            $files[] = $this->upload(self::pdf(), 'f' . $i . '.pdf');
        }

        $result = $this->validator()->validate($files);

        self::assertFalse($result['ok']);
        self::assertSame('Attach at most 3 files.', $result['message']);
    }

    public function testAFourthFileAcrossRequestsIsRefused(): void
    {
        $result = $this->validator()->validate([$this->upload(self::pdf(), 'late.pdf')], 3);

        self::assertFalse($result['ok']);
    }

    public function testTheSizeOnDiskDecides(): void
    {
        $file = $this->upload(self::pdf() . str_repeat('0', AttachmentValidator::MAX_BYTES), 'big.pdf');
        $file['size'] = 1000; // The browser's claim is not trusted.

        $result = $this->validator()->validate([$file]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('at most 5 MB', $result['message']);
    }

    public function testPhpsOwnSizeRefusalIsReportedAsTooBig(): void
    {
        $result = $this->validator()->validate([['name' => 'a.pdf', 'tmp_name' => '', 'size' => 0, 'error' => 1]]);

        self::assertFalse($result['ok']);
        self::assertStringContainsString('at most 5 MB', $result['message']);
    }

    public function testAPathThatWasNotUploadedIsRefused(): void
    {
        $file = $this->upload(self::pdf(), 'a.pdf');
        $this->uploaded = [];

        self::assertFalse($this->validator()->validate([$file])['ok']);
    }

    public function testNoFilesIsAFailureNotASuccess(): void
    {
        self::assertFalse($this->validator()->validate([])['ok']);
    }

    public function testNormalizeAcceptsPhpAndLaminasShapesAndDropsEmptyInputs(): void
    {
        $validator = $this->validator();
        $php = [
            'name' => ['a.pdf', ''],
            'tmp_name' => ['/tmp/a', ''],
            'size' => [10, 0],
            'error' => [0, 4],
        ];
        $laminas = [
            ['name' => 'a.pdf', 'tmp_name' => '/tmp/a', 'size' => 10, 'error' => 0],
            ['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => 4],
        ];
        $expected = [['name' => 'a.pdf', 'tmp_name' => '/tmp/a', 'size' => 10, 'error' => 0]];

        self::assertSame($expected, $validator->normalize($php));
        self::assertSame($expected, $validator->normalize($laminas));
        self::assertSame([], $validator->normalize('nope'));
    }

    public function testSafeNameKeepsTheBaseNameOnly(): void
    {
        $validator = $this->validator();

        self::assertSame('passwd.pdf', $validator->safeName('../../etc/passwd.pdf'));
        self::assertSame('a_b_.pdf', $validator->safeName('a"<b>.pdf'));
        $long = $validator->safeName(str_repeat('x', 300) . '.pdf');
        self::assertSame(AttachmentValidator::MAX_NAME_LENGTH, strlen($long));
    }

    public function testReadBytesRefusesAShortRead(): void
    {
        $result = $this->validator()->validate([$this->upload(self::pdf(), 'a.pdf')]);
        $file = $result['files'][0];

        self::assertSame(self::pdf(), $this->validator()->readBytes($file));
        $file['size'] += 1;
        self::assertNull($this->validator()->readBytes($file));
    }
}

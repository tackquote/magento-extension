<?php
/**
 * The wholesale submission sequence: answers and files are checked before anything is sent;
 * each file is uploaded, then the application carries `{uploadId}` for that field. And the
 * net-terms application: built from the account email, refused without a customer id.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Forms;

use Magento\Framework\Escaper;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\AttachmentValidator;
use TackQuote\Quotes\Model\Forms\AccountApiClient;
use TackQuote\Quotes\Model\Forms\AccountIdentity;
use TackQuote\Quotes\Model\Forms\ApplicationSubmitter;
use TackQuote\Quotes\Model\Forms\CreditApplicationBuilder;
use TackQuote\Quotes\Model\Forms\FieldRenderer;
use TackQuote\Quotes\Model\Forms\FormDefinition;
use TackQuote\Quotes\Model\Payment\NetTermsStanding;

/**
 * @covers \TackQuote\Quotes\Model\Forms\ApplicationSubmitter
 */
class ApplicationSubmitterTest extends TestCase
{
    private const INSTALL = '0f0e0d0c-0b0a-4908-8706-050403020100';

    /** @var AccountApiClient&MockObject */
    private $client;

    /** @var AttachmentValidator&MockObject */
    private $attachments;

    /** @var string[] */
    private $calls = [];

    protected function setUp(): void
    {
        $this->client = $this->createMock(AccountApiClient::class);
        $this->attachments = $this->createMock(AttachmentValidator::class);
    }

    private function submitter(): ApplicationSubmitter
    {
        $definition = new FormDefinition();

        return new ApplicationSubmitter(
            $this->client,
            $definition,
            new FieldRenderer($this->escaper(), $definition),
            $this->attachments,
            new CreditApplicationBuilder($definition),
            $this->createMock(NetTermsStanding::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private function form(): array
    {
        return (new FormDefinition())->normalise(['fields' => [
            ['key' => 'companyName', 'type' => 'text', 'label' => 'Company', 'required' => true],
            ['key' => 'cert', 'type' => 'file', 'label' => 'Certificate', 'required' => true],
        ]]);
    }

    private function who(bool $namespaced = true): AccountIdentity
    {
        return new AccountIdentity(42, 'buyer@example.test', self::INSTALL, $namespaced);
    }

    private function goodFile(): void
    {
        $this->attachments->method('validateOne')->willReturn([
            'ok' => true,
            'file' => ['path' => '/tmp/php1', 'name' => 'cert.pdf', 'mime' => 'application/pdf', 'size' => 10],
        ]);
        $this->attachments->method('readBytes')->willReturn('%PDF-1.7 bytes');
    }

    public function testUploadsThenSubmitsWithTheUploadIdAsTheFileFieldsValue(): void
    {
        $this->goodFile();
        $this->client->expects($this->once())->method('uploadWholesaleFile')
            ->with('%PDF-1.7 bytes', 'cert.pdf', 'trade', 'cert', $this->anything())
            ->willReturnCallback(function () {
                $this->calls[] = 'upload';

                return ['ok' => true, 'status' => 201, 'data' => ['uploadId' => 'up-1']];
            });
        $this->client->expects($this->once())->method('submitWholesale')
            ->with('trade', ['companyName' => 'Acme', 'cert' => ['uploadId' => 'up-1']], $this->anything())
            ->willReturnCallback(function () {
                $this->calls[] = 'submit';

                return ['ok' => true, 'status' => 201, 'data' => ['status' => 'pending', 'message' => 'Thanks!']];
            });

        $out = $this->submitter()->submitWholesale(
            'trade',
            $this->form(),
            ['companyName' => 'Acme'],
            ['cert' => ['name' => 'cert.pdf', 'tmp_name' => '/tmp/php1', 'size' => 10, 'error' => 0]],
            $this->who()
        );

        self::assertSame(['kind' => 'pending', 'message' => 'Thanks!'], $out);
        self::assertSame(['upload', 'submit'], $this->calls);
    }

    public function testARefusedFileStopsEverythingBeforeAnyCall(): void
    {
        $this->attachments->method('validateOne')->willReturn([
            'ok' => false,
            'message' => 'cert.exe: attach a PDF, JPEG or PNG file.'
        ]);
        $this->client->expects($this->never())->method('uploadWholesaleFile');
        $this->client->expects($this->never())->method('submitWholesale');

        $out = $this->submitter()->submitWholesale(
            'trade',
            $this->form(),
            ['companyName' => 'Acme'],
            ['cert' => ['name' => 'cert.exe', 'tmp_name' => '/tmp/php1', 'size' => 10, 'error' => 0]],
            $this->who()
        );

        self::assertSame('error', $out['kind']);
    }

    public function testFilesNeedTheCustomerIdTheServerBindsThemTo(): void
    {
        $this->goodFile();
        $this->client->expects($this->never())->method('uploadWholesaleFile');
        $this->client->expects($this->never())->method('submitWholesale');

        $out = $this->submitter()->submitWholesale(
            'trade',
            $this->form(),
            ['companyName' => 'Acme'],
            ['cert' => ['name' => 'cert.pdf', 'tmp_name' => '/tmp/php1', 'size' => 10, 'error' => 0]],
            $this->who(false)
        );

        self::assertSame('error', $out['kind']);
    }

    public function testAFailedUploadSubmitsNothing(): void
    {
        $this->goodFile();
        $this->client->method('uploadWholesaleFile')->willReturn(['ok' => false, 'status' => 503]);
        $this->client->expects($this->never())->method('submitWholesale');

        $out = $this->submitter()->submitWholesale(
            'trade',
            $this->form(),
            ['companyName' => 'Acme'],
            ['cert' => ['name' => 'cert.pdf', 'tmp_name' => '/tmp/php1', 'size' => 10, 'error' => 0]],
            $this->who()
        );

        self::assertSame('error', $out['kind']);
        self::assertStringContainsString('try again shortly', $out['message']);
    }

    public function testFilesByFieldReadsBothBagShapesAndSkipsEmptySlots(): void
    {
        $laminas = [
            'cert' => ['name' => 'a.pdf', 'tmp_name' => '/tmp/a', 'size' => 1, 'error' => 0],
            'w9' => ['name' => '', 'tmp_name' => '', 'size' => 0, 'error' => 4],
        ];
        $php = [
            'name' => ['cert' => 'a.pdf', 'w9' => ''],
            'tmp_name' => ['cert' => '/tmp/a', 'w9' => ''],
            'size' => ['cert' => 1, 'w9' => 0],
            'error' => ['cert' => 0, 'w9' => 4],
        ];

        $submitter = $this->submitter();

        self::assertSame(['cert'], array_keys($submitter->filesByField($laminas)));
        self::assertSame(['cert'], array_keys($submitter->filesByField($php)));
        self::assertSame([], $submitter->filesByField(null));
    }

    public function testACreditApplicationGoesUnderTheAccountEmailNeverAPostedOne(): void
    {
        $this->client->expects($this->once())->method('submitCreditApplication')
            ->with(
                $this->callback(static function (array $payload): bool {
                    return $payload['contactEmail'] === 'buyer@example.test'
                        && $payload['legalBusinessName'] === 'Acme Ltd'
                        && !isset($payload['email']);
                }),
                $this->anything()
            )
            ->willReturn(['ok' => true, 'status' => 201, 'data' => ['status' => 'received']]);

        $out = $this->submitter()->submitCredit(
            ['legalBusinessName' => 'Acme Ltd', 'contactEmail' => 'someone-else@example.test', 'email' => 'x'],
            $this->who()
        );

        self::assertSame('success', $out['kind']);
    }

    public function testACreditApplicationWithoutACustomerIdIsNotSent(): void
    {
        $this->client->expects($this->never())->method('submitCreditApplication');

        $out = $this->submitter()->submitCredit(['legalBusinessName' => 'Acme'], $this->who(false));

        self::assertSame('error', $out['kind']);
    }

    /**
     * Magento's real Escaper, with the two collaborators it would otherwise fetch from the
     * ObjectManager (not booted in a unit test): the Laminas escaper and an inline
     * translator that is off, as on a storefront.
     */
    private function escaper(): Escaper
    {
        $escaper = new Escaper();
        $inline = $this->createMock(\Magento\Framework\Translate\InlineInterface::class);
        $inline->method('isAllowed')->willReturn(false);
        foreach (['escaper' => new \Magento\Framework\ZendEscaper(), 'translateInline' => $inline] as $name => $value) {
            $property = new \ReflectionProperty(Escaper::class, $name);
            $property->setAccessible(true);
            $property->setValue($escaper, $value);
        }

        return $escaper;
    }
}

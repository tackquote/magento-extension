<?php
/**
 * The attachment upload controller: fails closed, never fabricates an id.
 *
 * Mutations (applied, red, restored):
 *   U1  skip the merchant switch -> testNothingIsSentWhenTheMerchantSwitchIsOff
 *   U2  skip the capability check -> testNothingIsSentToAServerThatDoesNotTakeFiles
 *   U3  answer the ids gathered so far when a later file fails -> testOneRefusedFileFailsTheWholeBatch
 *   U4  accept an answer without a usable upload id -> testAnAnswerWithoutAnIdIsAFailure
 *   U5  send the typed/guest identity for a signed-in customer -> testASignedInCustomerIsAssertedByTheSessionEmail
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Controller\Quote;

use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Request\Http as HttpRequest;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Store\Model\Store;
use Magento\Store\Model\StoreManagerInterface;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Controller\Quote\Upload;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\AttachmentValidator;
use TackQuote\Quotes\Model\QuotePayloadBuilder;
use TackQuote\Quotes\Model\QuoteRequestConfig;
use TackQuote\Quotes\Model\ServerCapabilities;
use TackQuote\Quotes\Model\SubmissionThrottle;

/**
 * @covers \TackQuote\Quotes\Controller\Quote\Upload
 */
class UploadTest extends TestCase
{
    private const ID_A = '3f2b8c1e-9d4a-4b7e-8c21-5a6d7e8f9a0b';
    private const ID_B = '4a3c9d2f-0e5b-4c8f-9d32-6b7e8f9a0b1c';

    /** @var bool */
    private $switchOn = true;

    /** @var bool */
    private $serverTakesFiles = true;

    /** @var bool */
    private $loggedIn = false;

    /** @var StorefrontClient&MockObject */
    private $client;

    /** @var array<string, mixed>|null */
    private $responseData;

    /** @var int|null */
    private $responseCode;

    /** @var array<int, array<string, mixed>> Files the validator answers as valid. */
    private $valid = [];

    protected function setUp(): void
    {
        $this->switchOn = true;
        $this->serverTakesFiles = true;
        $this->loggedIn = false;
        $this->responseData = null;
        $this->responseCode = null;
        $this->valid = [
            ['path' => '/tmp/a', 'name' => 'a.pdf', 'mime' => 'application/pdf', 'size' => 10],
            ['path' => '/tmp/b', 'name' => 'b.png', 'mime' => 'image/png', 'size' => 20],
        ];
        $this->client = $this->getMockBuilder(StorefrontClient::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['uploadQuoteFile'])
            ->getMock();
    }

    private function controller(): Upload
    {
        $result = $this->createMock(JsonResult::class);
        $result->method('setHttpResponseCode')->willReturnCallback(function ($code) use (&$result) {
            $this->responseCode = (int) $code;

            return $result;
        });
        $result->method('setData')->willReturnCallback(function ($data) use (&$result) {
            $this->responseData = $data;

            return $result;
        });
        $resultFactory = $this->getMockBuilder(ResultFactory::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['create'])
            ->getMock();
        $resultFactory->method('create')->willReturn($result);

        $request = $this->getMockBuilder(HttpRequest::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getParam', 'getFiles'])
            ->getMock();
        $request->method('getParam')->willReturnCallback(function ($key, $default = null) {
            return $default;
        });
        $request->method('getFiles')->willReturn(['name' => ['a.pdf'], 'tmp_name' => ['/tmp/a'],
            'size' => [10], 'error' => [0]]);

        $context = $this->getMockBuilder(Context::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getRequest', 'getResultFactory'])
            ->getMock();
        $context->method('getRequest')->willReturn($request);
        $context->method('getResultFactory')->willReturn($resultFactory);

        $config = $this->getMockBuilder(QuoteRequestConfig::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isAttachmentsSwitchOn'])
            ->getMock();
        $config->method('isAttachmentsSwitchOn')->willReturnCallback(function () {
            return $this->switchOn;
        });

        $caps = $this->getMockBuilder(ServerCapabilities::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['supports'])
            ->getMock();
        $caps->method('supports')->willReturnCallback(function () {
            return $this->serverTakesFiles;
        });

        $validator = $this->getMockBuilder(AttachmentValidator::class)
            ->onlyMethods(['validate', 'readBytes'])
            ->getMock();
        $validator->method('validate')->willReturnCallback(function () {
            return ['ok' => true, 'files' => $this->valid];
        });
        $validator->method('readBytes')->willReturn('%PDF-bytes');

        $throttle = $this->getMockBuilder(SubmissionThrottle::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isExceededFor'])
            ->getMock();
        $throttle->method('isExceededFor')->willReturn(false);

        $customer = $this->createMock(CustomerInterface::class);
        $customer->method('getEmail')->willReturn('buyer@example.test');
        $session = $this->getMockBuilder(CustomerSession::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['isLoggedIn', 'getCustomerData'])
            ->getMock();
        $session->method('isLoggedIn')->willReturnCallback(function () {
            return $this->loggedIn;
        });
        $session->method('getCustomerData')->willReturn($customer);

        $store = $this->getMockBuilder(Store::class)
            ->disableOriginalConstructor()
            ->onlyMethods(['getId'])
            ->getMock();
        $store->method('getId')->willReturn(1);
        $storeManager = $this->createMock(StoreManagerInterface::class);
        $storeManager->method('getStore')->willReturn($store);

        return new Upload(
            $context,
            $config,
            $caps,
            $validator,
            new QuotePayloadBuilder(),
            $this->client,
            $throttle,
            $session,
            $storeManager,
            $this->createMock(FormKey::class),
            $this->createMock(LoggerInterface::class)
        );
    }

    private static function token(): string
    {
        return str_repeat('Ab1_-', 8) . 'xyz';
    }

    public function testNothingIsSentWhenTheMerchantSwitchIsOff(): void
    {
        $this->switchOn = false;
        $this->client->expects(self::never())->method('uploadQuoteFile');

        $this->controller()->execute();

        self::assertSame(403, $this->responseCode);
        self::assertFalse($this->responseData['success']);
    }

    public function testNothingIsSentToAServerThatDoesNotTakeFiles(): void
    {
        $this->serverTakesFiles = false;
        $this->client->expects(self::never())->method('uploadQuoteFile');

        $this->controller()->execute();

        self::assertSame(403, $this->responseCode);
    }

    public function testAGuestsFilesComeBackWithIdsAndTheIssuedToken(): void
    {
        $calls = [];
        $this->client->method('uploadQuoteFile')->willReturnCallback(
            function ($bytes, $name, $email, $token) use (&$calls) {
                $calls[] = [$name, $email, $token];
                $first = count($calls) === 1;

                return ['ok' => true, 'status' => 201, 'data' => $first
                    ? ['uploadId' => strtoupper(self::ID_A), 'uploadToken' => self::token(), 'size' => 10]
                    : ['uploadId' => self::ID_B, 'size' => 20]];
            }
        );

        $this->controller()->execute();

        self::assertTrue($this->responseData['success']);
        self::assertSame([self::ID_A, self::ID_B], array_column($this->responseData['files'], 'uploadId'));
        self::assertSame(self::token(), $this->responseData['uploadToken']);
        // The second file of the request carries the token the first one was issued.
        self::assertSame([['a.pdf', '', ''], ['b.png', '', self::token()]], $calls);
    }

    public function testASignedInCustomerIsAssertedByTheSessionEmail(): void
    {
        $this->loggedIn = true;
        $this->valid = [$this->valid[0]];
        $this->client->expects(self::once())->method('uploadQuoteFile')
            ->with('%PDF-bytes', 'a.pdf', 'buyer@example.test', '', 1)
            ->willReturn(['ok' => true, 'status' => 201, 'data' => ['uploadId' => self::ID_A]]);

        $this->controller()->execute();

        self::assertTrue($this->responseData['success']);
        self::assertArrayNotHasKey('uploadToken', $this->responseData);
    }

    public function testOneRefusedFileFailsTheWholeBatch(): void
    {
        $this->client->method('uploadQuoteFile')->willReturnOnConsecutiveCalls(
            ['ok' => true, 'status' => 201, 'data' => ['uploadId' => self::ID_A, 'uploadToken' => self::token()]],
            ['ok' => false, 'status' => 429, 'retryAfter' => 30]
        );

        $this->controller()->execute();

        self::assertSame(429, $this->responseCode);
        self::assertFalse($this->responseData['success']);
        self::assertArrayNotHasKey('files', $this->responseData);
        self::assertSame(30, $this->responseData['retryAfter']);
    }

    public function testAnAnswerWithoutAnIdIsAFailure(): void
    {
        // Signed in, so no guest token is needed: the missing id alone must fail it.
        $this->loggedIn = true;
        $this->valid = [$this->valid[0]];
        $this->client->method('uploadQuoteFile')->willReturn(['ok' => true, 'status' => 201, 'data' => ['size' => 1]]);

        $this->controller()->execute();

        self::assertSame(502, $this->responseCode);
        self::assertFalse($this->responseData['success']);
    }

    public function testAGuestUploadAnsweredWithoutATokenIsAFailure(): void
    {
        $this->valid = [$this->valid[0]];
        $this->client->method('uploadQuoteFile')
            ->willReturn(['ok' => true, 'status' => 201, 'data' => ['uploadId' => self::ID_A]]);

        $this->controller()->execute();

        self::assertSame(502, $this->responseCode);
    }
}

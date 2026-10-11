<?php
/**
 * Storefront attachment upload: `POST /tackquote/quote/upload`.
 *
 * HOW A FILE TRAVELS (TackQuote upload-then-claim)
 *   1. The browser posts the files here (multipart, `files[]`), with the form key.
 *   2. Every file is validated BEFORE any outbound call (Model\AttachmentValidator: at most
 *      three, 5 MB each, PDF/JPEG/PNG by leading bytes AND finfo AND extension; the declared
 *      browser type is never read).
 *   3. Each file's bytes go server to server to TackQuote, `POST /storefront/v1/quote-upload`
 *      with the store's key in X-Api-Key (Model\Api\StorefrontClient::uploadQuoteFile()).
 *      Nothing is written to pub/media, var/ or anywhere else in Magento.
 *   4. TackQuote answers an opaque upload id (and, for a guest, an upload token); the browser
 *      sends those with the quote request, whose TackQuote side claims the files.
 *
 * WHO — a signed-in customer is asserted by the account email from the session (the address
 * Controller\Quote\Submit also sends, so the claim matches); a guest is identified only by the
 * token TackQuote issues on the first upload. Guests may attach, exactly as they may request a
 * quote; TackQuote applies the seller's own "who can request quotes" setting to the upload.
 *
 * FAIL CLOSED — any failure is answered with a translatable message and no id. A partial
 * success (file 1 accepted, file 2 refused) answers a failure for the whole batch: the ids of
 * the accepted files are not returned, so they are never claimed and TackQuote's expiry sweep
 * removes them.
 *
 * Unauthenticated from the internet's point of view, like Submit: CSRF form key plus its own
 * per-IP limit (SubmissionThrottle, bucket `upload`), on top of TackQuote's per-key limit.
 *
 * LOGGING — opaque upload ids and HTTP statuses only. Never a file name, the bytes, an email
 * or a token.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Controller\Quote;

use Magento\Customer\Model\Session as CustomerSession;
use Magento\Framework\App\Action\Action;
use Magento\Framework\App\Action\Context;
use Magento\Framework\App\Action\HttpPostActionInterface;
use Magento\Framework\App\CsrfAwareActionInterface;
use Magento\Framework\App\Request\InvalidRequestException;
use Magento\Framework\App\RequestInterface;
use Magento\Framework\Controller\Result\Json as JsonResult;
use Magento\Framework\Controller\ResultFactory;
use Magento\Framework\Data\Form\FormKey;
use Magento\Store\Model\StoreManagerInterface;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Api\StorefrontClient;
use TackQuote\Quotes\Model\AttachmentValidator;
use TackQuote\Quotes\Model\QuotePayloadBuilder;
use TackQuote\Quotes\Model\QuoteRequestConfig;
use TackQuote\Quotes\Model\ServerCapabilities;
use TackQuote\Quotes\Model\SubmissionThrottle;

class Upload extends Action implements HttpPostActionInterface, CsrfAwareActionInterface
{
    /** Upload requests per visitor per window (the WooCommerce plugin's default). */
    private const RATE_LIMIT_MAX = 20;

    /** Seconds. */
    private const RATE_LIMIT_WINDOW = 600;

    /**
     * @var QuoteRequestConfig
     */
    private $quoteRequestConfig;

    /**
     * @var ServerCapabilities
     */
    private $capabilities;

    /**
     * @var AttachmentValidator
     */
    private $validator;

    /**
     * @var QuotePayloadBuilder
     */
    private $payloadBuilder;

    /**
     * @var StorefrontClient
     */
    private $storefrontClient;

    /**
     * @var SubmissionThrottle
     */
    private $throttle;

    /**
     * @var CustomerSession
     */
    private $customerSession;

    /**
     * @var StoreManagerInterface
     */
    private $storeManager;

    /**
     * @var FormKey
     */
    private $formKey;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param Context $context
     * @param QuoteRequestConfig $quoteRequestConfig
     * @param ServerCapabilities $capabilities
     * @param AttachmentValidator $validator
     * @param QuotePayloadBuilder $payloadBuilder
     * @param StorefrontClient $storefrontClient
     * @param SubmissionThrottle $throttle
     * @param CustomerSession $customerSession
     * @param StoreManagerInterface $storeManager
     * @param FormKey $formKey
     * @param LoggerInterface $logger
     */
    public function __construct(
        Context $context,
        QuoteRequestConfig $quoteRequestConfig,
        ServerCapabilities $capabilities,
        AttachmentValidator $validator,
        QuotePayloadBuilder $payloadBuilder,
        StorefrontClient $storefrontClient,
        SubmissionThrottle $throttle,
        CustomerSession $customerSession,
        StoreManagerInterface $storeManager,
        FormKey $formKey,
        LoggerInterface $logger
    ) {
        parent::__construct($context);
        $this->quoteRequestConfig = $quoteRequestConfig;
        $this->capabilities = $capabilities;
        $this->validator = $validator;
        $this->payloadBuilder = $payloadBuilder;
        $this->storefrontClient = $storefrontClient;
        $this->throttle = $throttle;
        $this->customerSession = $customerSession;
        $this->storeManager = $storeManager;
        $this->formKey = $formKey;
        $this->logger = $logger;
    }

    /**
     * @inheritDoc
     */
    public function execute()
    {
        /** @var JsonResult $result */
        $result = $this->resultFactory->create(ResultFactory::TYPE_JSON);
        $storeId = $this->storeId();
        $offStore = (string) __('Files cannot be attached on this store.');

        if (!$this->quoteRequestConfig->isAttachmentsSwitchOn($storeId)) {
            return $this->fail($result, 403, $offStore);
        }
        // Before any work, and before the capability ping a miss would cost.
        if ($this->throttle->isExceededFor('upload', self::RATE_LIMIT_MAX, self::RATE_LIMIT_WINDOW)) {
            return $this->fail(
                $result,
                429,
                (string) __('Too many files were sent in a short time. Please try again shortly.')
            );
        }
        if (!$this->capabilities->supports(ServerCapabilities::ATTACHMENTS, $storeId, true)) {
            return $this->fail($result, 403, $offStore);
        }

        $request = $this->getRequest();
        $files = $this->validator->normalize($this->postedFiles($request));
        $already = (int) $request->getParam('attached', 0);
        $checked = $this->validator->validate($files, $already);
        if (!$checked['ok']) {
            return $this->fail($result, 400, (string) $checked['message']);
        }

        $email = $this->signedInEmail();
        $token = '';
        if ($email === '') {
            $presented = trim((string) $request->getParam('upload_token', ''));
            if ($presented !== '') {
                if (!$this->payloadBuilder->isUploadToken($presented)) {
                    return $this->fail($result, 400, (string) __('That file could not be attached. Please try again.'));
                }
                $token = $presented;
            }
        }

        $accepted = [];
        foreach ($checked['files'] as $file) {
            $bytes = $this->validator->readBytes($file);
            if ($bytes === null) {
                return $this->fail($result, 400, (string) __('That file could not be attached. Please try again.'));
            }
            $answer = $this->storefrontClient->uploadQuoteFile($bytes, $file['name'], $email, $token, $storeId);
            unset($bytes);

            if (!$answer['ok']) {
                return $this->failUpload($result, $answer);
            }

            $data = $answer['data'] ?? [];
            $id = strtolower((string) ($data['uploadId'] ?? ''));
            if (!$this->payloadBuilder->isUploadId($id)) {
                $this->logger->warning('TackQuote quote-upload answered without a usable upload id.');

                return $this->fail($result, 502, (string) __('That file could not be attached. Please try again.'));
            }
            if ($email === '' && $token === '' && $this->payloadBuilder->isUploadToken($data['uploadToken'] ?? null)) {
                $token = (string) $data['uploadToken'];
            }

            $accepted[] = [
                'uploadId' => $id,
                'name' => $file['name'],
                'size' => (int) ($data['size'] ?? $file['size']),
                'mimeType' => $file['mime'],
            ];
        }

        // A guest's upload must come back with a token, or the request could never claim it.
        if ($email === '' && $token === '') {
            $this->logger->warning('TackQuote quote-upload answered no guest token.');

            return $this->fail($result, 502, (string) __('That file could not be attached. Please try again.'));
        }

        $this->logger->info(
            'TackQuote quote-upload accepted: ' . implode(',', array_column($accepted, 'uploadId'))
        );

        $out = ['success' => true, 'files' => $accepted];
        if ($token !== '') {
            $out['uploadToken'] = $token;
        }

        return $result->setData($out);
    }

    /**
     * The posted `files` entry, or null.
     *
     * Magento's HTTP request (Magento\Framework\App\Request\Http, a Laminas PhpEnvironment
     * request) exposes uploads through getFiles(); the interface does not declare it.
     *
     * @param RequestInterface $request
     * @return mixed
     */
    private function postedFiles(RequestInterface $request)
    {
        if (!method_exists($request, 'getFiles')) {
            return null;
        }
        $files = $request->getFiles('files');

        return is_object($files) && method_exists($files, 'toArray') ? $files->toArray() : $files;
    }

    /**
     * The signed-in customer's email, or '' for a guest.
     *
     * @return string
     */
    private function signedInEmail(): string
    {
        if (!$this->customerSession->isLoggedIn()) {
            return '';
        }

        // The account email: the same address Controller\Quote\Submit sends as buyerEmail for a
        // signed-in customer, which is what TackQuote matches the claim on.
        $customer = $this->customerSession->getCustomerData();

        return $customer !== null ? trim((string) $customer->getEmail()) : '';
    }

    /**
     * Shopper-facing answer for a refused upload call. Never the raw body.
     *
     * @param JsonResult $result
     * @param array $answer From StorefrontClient::uploadQuoteFile().
     * @return JsonResult
     */
    private function failUpload(JsonResult $result, array $answer): JsonResult
    {
        $status = (int) ($answer['status'] ?? 0);
        switch ($status) {
            case 429:
                $message = (string) __('Too many files were sent in a short time. Please try again shortly.');
                break;
            case 413:
                $message = (string) __(
                    '%1: files can be at most %2 MB.',
                    (string) __('file'),
                    AttachmentValidator::MAX_MB
                );
                break;
            case 400:
                $message = (string) ($answer['message'] ?? '') !== ''
                    ? (string) $answer['message']
                    : (string) __('That file could not be attached. Please try again.');
                break;
            case 403:
            case 404:
                $message = (string) __('Files cannot be attached on this store.');
                break;
            default:
                $message = (string) __('That file could not be attached. Please try again.');
        }

        $data = ['success' => false, 'message' => $message];
        if ($status === 429 && isset($answer['retryAfter'])) {
            $data['retryAfter'] = (int) $answer['retryAfter'];
        }

        return $result->setHttpResponseCode($status === 429 ? 429 : 502)->setData($data);
    }

    /**
     * The current store view's id, or null.
     *
     * @return int|null
     */
    private function storeId(): ?int
    {
        try {
            return (int) $this->storeManager->getStore()->getId();
        } catch (\Exception $e) {
            return null;
        }
    }

    /**
     * Build a failed JSON response.
     *
     * @param JsonResult $result
     * @param int $code
     * @param string $message
     * @return JsonResult
     */
    private function fail(JsonResult $result, int $code, string $message): JsonResult
    {
        return $result->setHttpResponseCode($code)->setData(['success' => false, 'message' => $message]);
    }

    /**
     * @inheritDoc
     */
    public function createCsrfValidationException(RequestInterface $request): ?InvalidRequestException
    {
        return new InvalidRequestException(
            $this->resultFactory->create(ResultFactory::TYPE_JSON)->setHttpResponseCode(400)->setData([
                'success' => false,
                'message' => __('Invalid form key. Please reload the page and try again.'),
            ])
        );
    }

    /**
     * @inheritDoc
     */
    public function validateForCsrf(RequestInterface $request): ?bool
    {
        // The same rule as Submit: the posted form_key against the session's own.
        $submitted = (string) $request->getParam('form_key', '');

        return $submitted !== '' && $submitted === $this->formKey->getFormKey();
    }
}

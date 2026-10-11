<?php
/**
 * Sends a wholesale or net-terms application to TackQuote and says what the shopper reads.
 *
 * WHOLESALE. Answers are checked against the LIVE form first (FormDefinition), then every
 * file is checked here (AttachmentValidator: PDF, JPEG or PNG by extension AND leading
 * bytes, at most 5 MB, narrowed by the field's own `accept` / `maxSizeMb`), and only then is
 * anything sent: each file to `POST /storefront/v1/wholesale-upload`, then the application
 * to `POST /storefront/v1/wholesale-signup[/<slug>]` with `{uploadId}` as each file field's
 * value. Files are streamed from PHP's own upload temp file and never saved in Magento.
 * The same sequence as the WooCommerce plugin (Tack_Storefront_Forms::submit_with_files()).
 *
 * NET TERMS. CreditApplicationBuilder, then `POST /storefront/v1/credit-application`.
 *
 * Every outcome is `{kind: success|pending|error, message}`. Never queued, never retried: a
 * person is waiting, and re-sending an application later is not what they asked for.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\AttachmentValidator;
use TackQuote\Quotes\Model\Payment\NetTermsStanding;

class ApplicationSubmitter
{
    /**
     * @var AccountApiClient
     */
    private $client;

    /**
     * @var FormDefinition
     */
    private $definition;

    /**
     * @var FieldRenderer
     */
    private $renderer;

    /**
     * @var AttachmentValidator
     */
    private $attachments;

    /**
     * @var CreditApplicationBuilder
     */
    private $creditBuilder;

    /**
     * @var NetTermsStanding
     */
    private $standing;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param AccountApiClient $client
     * @param FormDefinition $definition
     * @param FieldRenderer $renderer
     * @param AttachmentValidator $attachments
     * @param CreditApplicationBuilder $creditBuilder
     * @param NetTermsStanding $standing
     * @param LoggerInterface $logger
     */
    public function __construct(
        AccountApiClient $client,
        FormDefinition $definition,
        FieldRenderer $renderer,
        AttachmentValidator $attachments,
        CreditApplicationBuilder $creditBuilder,
        NetTermsStanding $standing,
        LoggerInterface $logger
    ) {
        $this->client = $client;
        $this->definition = $definition;
        $this->renderer = $renderer;
        $this->attachments = $attachments;
        $this->creditBuilder = $creditBuilder;
        $this->standing = $standing;
        $this->logger = $logger;
    }

    /**
     * Submit a wholesale application.
     *
     * @param string $slug Form slug ('' for the default form).
     * @param array $form The LIVE normalised form (WholesaleFormSource::get(..., true)).
     * @param array $posted `tack_sf` values.
     * @param array<string, array> $files Field key => one uploaded-file entry (filesByField()).
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @return array{kind: string, message: string}
     */
    public function submitWholesale(
        string $slug,
        array $form,
        array $posted,
        array $files,
        AccountIdentity $who,
        ?int $storeId = null
    ): array {
        if (!$who->isSignedIn()) {
            return $this->error((string) __('Please sign in to your account to apply.'));
        }
        $fields = (array) ($form['fields'] ?? []);
        $collected = $this->definition->collectAnswers($fields, $posted);
        if ($collected['error'] !== null) {
            return $this->error($collected['error']);
        }
        $values = $collected['values'];

        $picked = $this->definition->pickFiles($fields, $values, $files);
        if ($picked['error'] !== null) {
            return $this->error($picked['error']);
        }
        if ($picked['wanted'] !== [] && !$who->carriesCustomerId()) {
            // TackQuote binds an application file to the applicant's platform identity; an
            // email alone cannot hold one (the upload route answers 401).
            return $this->error((string) __('Files cannot be attached from this store yet. Please contact us.'));
        }

        // Every file is checked before anything is sent.
        $checked = [];
        foreach ($picked['wanted'] as $key => $field) {
            $result = $this->attachments->validateOne($files[$key]);
            if (!$result['ok']) {
                return $this->error((string) $result['message']);
            }
            $file = $result['file'];
            if (!in_array($file['mime'], $this->renderer->accept($field), true)
                || $file['size'] > $this->renderer->maxMb($field) * 1024 * 1024) {
                return $this->error((string) __(
                    '%1: this file type or size is not accepted for this field.',
                    (string) $field['label']
                ));
            }
            $checked[$key] = $file;
        }

        foreach ($checked as $key => $file) {
            $bytes = $this->attachments->readBytes($file);
            if ($bytes === null) {
                return $this->error((string) __('That file could not be attached. Please try again.'));
            }
            $upload = $this->client->uploadWholesaleFile($bytes, $file['name'], $slug, (string) $key, $who, $storeId);
            $uploadId = $upload['data']['uploadId'] ?? null;
            if (empty($upload['ok']) || !is_string($uploadId) || $uploadId === '') {
                $this->logger->warning(sprintf(
                    'TackQuote wholesale-upload was refused (HTTP %d).',
                    (int) ($upload['status'] ?? 0)
                ));

                return $this->error($this->friendly($upload));
            }
            $values[$key] = ['uploadId' => $uploadId];
        }

        $result = $this->client->submitWholesale($slug, $values, $who, $storeId);
        if (empty($result['ok'])) {
            return $this->error($this->friendly($result));
        }
        $status = (string) ($result['data']['status'] ?? '');
        $message = $this->plain($result['data']['message'] ?? '');
        if ($status === 'approved') {
            return ['kind' => 'success', 'message' => $message !== ''
                ? $message
                : (string) __('Your wholesale account has been approved.')];
        }

        return ['kind' => 'pending', 'message' => $message !== ''
            ? $message
            : (string) __('Your application has been received and is awaiting review. We will email you when it has been decided.')];
    }

    /**
     * Submit a net-terms application.
     *
     * @param array $posted `tack_ct` values.
     * @param AccountIdentity $who
     * @param int|null $storeId
     * @return array{kind: string, message: string}
     */
    public function submitCredit(array $posted, AccountIdentity $who, ?int $storeId = null): array
    {
        if (!$who->isSignedIn()) {
            return $this->error((string) __('Please sign in to your account to apply for net terms.'));
        }
        if (!$who->carriesCustomerId()) {
            return $this->error((string) __('Net terms applications are not available from this store right now. Please contact us.'));
        }
        $built = $this->creditBuilder->build($posted, $who->getEmail());
        if ($built['error'] !== null) {
            return $this->error($built['error']);
        }

        $result = $this->client->submitCreditApplication($built['payload'], $who, $storeId);
        if (empty($result['ok'])) {
            return $this->error($this->friendly($result));
        }
        $this->standing->forget($who->getCustomerId());
        if (($result['data']['status'] ?? null) === 'already_pending') {
            return ['kind' => 'pending', 'message' => (string) __('You already have a net terms application awaiting review. We will email you with the decision.')];
        }

        return ['kind' => 'success', 'message' => (string) __('Your net terms application has been submitted. We will email you with the decision.')];
    }

    /**
     * Uploaded files keyed by field, from Magento's request file bag for `tack_file`.
     *
     * Accepts both shapes a file bag takes for `tack_file[<key>]`: per-field entries
     * (`[key => {name, tmp_name, size, error}]`, Laminas) and PHP's transposed one
     * (`{name: [key => ...], tmp_name: [key => ...], ...}`). An empty slot is left out.
     *
     * @param mixed $bag
     * @return array<string, array>
     */
    public static function filesByField($bag): array
    {
        if (!is_array($bag)) {
            return [];
        }
        $out = [];
        if (isset($bag['tmp_name']) && is_array($bag['tmp_name'])) {
            foreach ($bag['tmp_name'] as $key => $tmp) {
                $out[(string) $key] = [
                    'name' => $bag['name'][$key] ?? '',
                    'tmp_name' => $tmp,
                    'size' => $bag['size'][$key] ?? 0,
                    'error' => $bag['error'][$key] ?? 4,
                ];
            }
        } else {
            foreach ($bag as $key => $entry) {
                if (is_array($entry) && isset($entry['tmp_name']) && !is_array($entry['tmp_name'])) {
                    $out[(string) $key] = $entry;
                }
            }
        }

        // UPLOAD_ERR_NO_FILE (4): the field was left empty.
        return array_filter($out, static function ($entry): bool {
            return (int) ($entry['error'] ?? 4) !== 4 && is_string($entry['tmp_name'] ?? null) && $entry['tmp_name'] !== '';
        });
    }

    /**
     * What a shopper reads for a failed call.
     *
     * TackQuote's own refusal text on a 4xx names the rule (a missing answer, a file type,
     * "sign in to attach files"), never another shopper's data, so it is shown; a transport
     * failure or a 5xx gets a generic sentence.
     *
     * @param array $result
     * @return string
     */
    private function friendly(array $result): string
    {
        $status = (int) ($result['status'] ?? 0);
        if ($status === 429) {
            return (string) __('Too many attempts. Please wait a few minutes and try again.');
        }
        $message = $this->plain($result['message'] ?? '');
        if ($status >= 400 && $status < 500 && $status !== 401 && $status !== 403 && $message !== '') {
            return $message;
        }

        return (string) __('We could not send your application right now. Please try again shortly.');
    }

    /**
     * @param mixed $text
     * @return string
     */
    private function plain($text): string
    {
        return is_string($text) ? mb_substr(trim(strip_tags($text)), 0, 500) : '';
    }

    /**
     * @param string $message
     * @return array{kind: string, message: string}
     */
    private function error(string $message): array
    {
        return ['kind' => 'error', 'message' => $message];
    }
}

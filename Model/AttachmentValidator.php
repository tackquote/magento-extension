<?php
/**
 * Checks the files a shopper attaches to a quote request, BEFORE any byte leaves the store.
 *
 * WHAT IS ACCEPTED — exactly what TackQuote accepts on `POST /storefront/v1/quote-upload`
 * (tack apps/api/src/modules/quotes/quote-request-uploads.ts: MAX_QUOTE_REQUEST_FILES = 3,
 * MAX_QUOTE_REQUEST_FILE_MB = 5; uploads/storefront-private-upload.ts: PDF, JPEG, PNG), and
 * the same limits the WooCommerce plugin applies (includes/class-tack-attachments.php).
 * Checking here as well is not a substitute for the server's own check: it keeps a file the
 * server would refuse from costing the shopper an upload and the store an API call, and it
 * gives a translatable message instead of a TackQuote error string.
 *
 * NEVER TRUSTS THE CLIENT TYPE — the browser's declared MIME type is ignored entirely. The
 * file's leading bytes decide (`%PDF-`, `FF D8 FF`, the 8-byte PNG signature: the rule
 * TackQuote applies), finfo must agree when PHP has it, and the extension of the file name
 * must name the same type. A renamed executable fails all three.
 *
 * NOTHING IS STORED — this class only reads PHP's own upload temp files. PHP deletes those at
 * the end of the request when they were not moved (php.net, "Handling file uploads": "The file
 * will be deleted from the temporary directory at the end of the request if it has not been
 * moved away or renamed"), and nothing here moves them. No Magento media directory, no var/.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model;

class AttachmentValidator
{
    /** Files on one quote request (TackQuote MAX_QUOTE_REQUEST_FILES). */
    public const MAX_FILES = 3;

    /** Megabytes per file (TackQuote MAX_QUOTE_REQUEST_FILE_MB). */
    public const MAX_MB = 5;

    /** Bytes per file. */
    public const MAX_BYTES = self::MAX_MB * 1024 * 1024;

    /** Longest file name sent on to TackQuote. */
    public const MAX_NAME_LENGTH = 200;

    /** Accepted extensions and the one type each may hold. */
    public const MIME_BY_EXTENSION = [
        'pdf' => 'application/pdf',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
    ];

    /** PHP's UPLOAD_ERR_* codes, named here so a unit test needs no PHP upload. */
    private const UPLOAD_OK = 0;
    private const UPLOAD_TOO_BIG_INI = 1;
    private const UPLOAD_TOO_BIG_FORM = 2;
    private const UPLOAD_NONE = 4;

    /**
     * One `files` entry from the request as a flat list of files.
     *
     * Accepts both shapes a PHP stack hands over: PHP's raw `$_FILES['files']` for a
     * `files[]` input (`name[]`, `tmp_name[]`, … in parallel arrays), and the per-file list
     * Laminas' PhpEnvironment request (which Magento's HTTP request extends) builds from it.
     * An input left empty (UPLOAD_ERR_NO_FILE) is not a file and is dropped.
     *
     * @param mixed $entry
     * @return array<int, array{name: string, tmp_name: string, size: int, error: int}>
     */
    public function normalize($entry): array
    {
        if (!is_array($entry)) {
            return [];
        }

        // Laminas shape: a list of per-file arrays.
        if (!isset($entry['tmp_name'])) {
            $files = [];
            foreach ($entry as $one) {
                if (is_array($one) && isset($one['tmp_name'])) {
                    foreach ($this->normalize($one) as $file) {
                        $files[] = $file;
                    }
                }
            }

            return $files;
        }

        $keys = ['name', 'tmp_name', 'size', 'error'];
        if (!is_array($entry['tmp_name'])) {
            $single = [];
            foreach ($keys as $key) {
                $single[$key] = [$entry[$key] ?? null];
            }
            $entry = $single;
        }

        $files = [];
        foreach ((array) $entry['tmp_name'] as $i => $tmp) {
            $error = isset($entry['error'][$i]) ? (int) $entry['error'][$i] : self::UPLOAD_NONE;
            if ($error === self::UPLOAD_NONE) {
                continue;
            }
            $files[] = [
                'name' => isset($entry['name'][$i]) ? (string) $entry['name'][$i] : '',
                'tmp_name' => is_string($tmp) ? $tmp : '',
                'size' => isset($entry['size'][$i]) ? (int) $entry['size'][$i] : 0,
                'error' => $error,
            ];
        }

        return $files;
    }

    /**
     * Validate every file. Either all pass, or the first failure is answered.
     *
     * @param array $files Entries from normalize(): name, tmp_name, size, error.
     * @param int $alreadyAttached Files this request already carries (the browser's count),
     *                             so a fourth file cannot arrive one upload at a time.
     * @return array{ok: bool, files?: array<int, array{path: string, name: string, mime: string, size: int}>,
     *               message?: string}
     */
    public function validate(array $files, int $alreadyAttached = 0): array
    {
        if ($files === []) {
            return ['ok' => false, 'message' => (string) __('That file could not be attached. Please try again.')];
        }

        if (count($files) + max(0, $alreadyAttached) > self::MAX_FILES) {
            return ['ok' => false, 'message' => (string) __('Attach at most %1 files.', self::MAX_FILES)];
        }

        $valid = [];
        foreach ($files as $file) {
            $checked = $this->validateOne($file);
            if (!$checked['ok']) {
                return $checked;
            }
            $valid[] = $checked['file'];
        }

        return ['ok' => true, 'files' => $valid];
    }

    /**
     * Check one file.
     *
     * @param array $file One entry from normalize(): name, tmp_name, size, error.
     * @return array{ok: bool, file?: array{path: string, name: string, mime: string, size: int},
     *               message?: string}
     */
    public function validateOne(array $file): array
    {
        $name = $this->safeName((string) ($file['name'] ?? ''));
        $display = $name !== '' ? $name : (string) __('file');
        $failed = ['ok' => false, 'message' => (string) __('That file could not be attached. Please try again.')];
        $tooBig = [
            'ok' => false,
            'message' => (string) __('%1: files can be at most %2 MB.', $display, self::MAX_MB),
        ];
        $wrongType = ['ok' => false, 'message' => (string) __('%1: attach a PDF, JPEG or PNG file.', $display)];

        $error = (int) ($file['error'] ?? self::UPLOAD_NONE);
        if ($error !== self::UPLOAD_OK) {
            return in_array($error, [self::UPLOAD_TOO_BIG_INI, self::UPLOAD_TOO_BIG_FORM], true) ? $tooBig : $failed;
        }

        $path = (string) ($file['tmp_name'] ?? '');
        if ($path === '' || !$this->isUploadedFile($path)) {
            return $failed;
        }

        // The size the browser declared AND the size on disk: either over the cap refuses.
        $size = $this->fileSize($path);
        if ($size <= 0) {
            return $failed;
        }
        if ($size > self::MAX_BYTES || (int) ($file['size'] ?? 0) > self::MAX_BYTES) {
            return $tooBig;
        }

        $byExtension = $this->mimeFromExtension($name);
        if ($byExtension === null) {
            return $wrongType;
        }

        // The leading bytes decide, and must agree with the extension.
        $sniffed = $this->sniffMime($path);
        if ($sniffed === null || $sniffed !== $byExtension) {
            return $wrongType;
        }

        return ['ok' => true, 'file' => ['path' => $path, 'name' => $name, 'mime' => $sniffed, 'size' => $size]];
    }

    /**
     * PDF, JPEG or PNG from the file's leading bytes, or null.
     *
     * The rule TackQuote applies (`%PDF-`, `FF D8 FF`, the PNG signature, in a file of at
     * least 8 bytes); finfo, when PHP has it, must name the same type.
     *
     * @param string $path
     * @return string|null
     */
    public function sniffMime(string $path): ?string
    {
        $head = $this->readHead($path, 8);
        if (strlen($head) < 8) {
            return null;
        }

        if (strpos($head, '%PDF-') === 0) {
            $magic = 'application/pdf';
        } elseif (substr($head, 0, 3) === "\xFF\xD8\xFF") {
            $magic = 'image/jpeg';
        } elseif ($head === "\x89PNG\r\n\x1a\n") {
            $magic = 'image/png';
        } else {
            return null;
        }

        $byFinfo = $this->finfoMime($path);
        if ($byFinfo !== null && $byFinfo !== '' && $byFinfo !== $magic) {
            return null;
        }

        return $magic;
    }

    /**
     * The type the file name's extension claims, or null for anything not accepted.
     *
     * @param string $name
     * @return string|null
     */
    public function mimeFromExtension(string $name): ?string
    {
        $dot = strrpos($name, '.');
        if ($dot === false) {
            return null;
        }
        $extension = strtolower(substr($name, $dot + 1));

        return self::MIME_BY_EXTENSION[$extension] ?? null;
    }

    /**
     * A file name safe to send and to show a seller: the base name, characters outside a
     * conservative set replaced, bounded. It never reaches a path on either side (TackQuote
     * stores under its own UUID key), but it is displayed and sent in a header there.
     *
     * @param string $raw
     * @return string
     */
    public function safeName(string $raw): string
    {
        $base = str_replace('\\', '/', $raw);
        $slash = strrpos($base, '/');
        if ($slash !== false) {
            $base = substr($base, $slash + 1);
        }
        $base = (string) preg_replace('/[^A-Za-z0-9._ -]+/', '_', $base);
        $base = trim($base, " .\t");

        if (strlen($base) > self::MAX_NAME_LENGTH) {
            $dot = strrpos($base, '.');
            $extension = $dot !== false ? substr($base, $dot) : '';
            $base = substr($base, 0, self::MAX_NAME_LENGTH - strlen($extension)) . $extension;
        }

        return $base;
    }

    /**
     * Is this a file PHP received in THIS request's upload? Overridden in unit tests only.
     *
     * @param string $path
     * @return bool
     */
    protected function isUploadedFile(string $path): bool
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- PHP's own "is this upload" check.
        return is_uploaded_file($path);
    }

    /**
     * Size on disk.
     *
     * @param string $path
     * @return int
     */
    protected function fileSize(string $path): int
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- PHP's own upload temp file, never a Magento path.
        $size = filesize($path);

        return $size === false ? 0 : (int) $size;
    }

    /**
     * The first bytes of the file.
     *
     * @param string $path
     * @param int $count
     * @return string
     */
    protected function readHead(string $path, int $count): string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- PHP's own upload temp file, never a URL.
        $head = file_get_contents($path, false, null, 0, $count);

        return is_string($head) ? $head : '';
    }

    /**
     * The whole validated file (at most MAX_BYTES), or null when it cannot be read in full.
     *
     * @param array $file A validate() answer (path, size).
     * @return string|null
     */
    public function readBytes(array $file): ?string
    {
        // phpcs:ignore Magento2.Functions.DiscouragedFunction -- PHP's own upload temp file, at most 5 MB.
        $bytes = file_get_contents((string) $file['path'], false, null, 0, self::MAX_BYTES + 1);
        if (!is_string($bytes) || strlen($bytes) !== (int) $file['size']) {
            return null;
        }

        return $bytes;
    }

    /**
     * The type finfo reports for the file, or null when PHP has no fileinfo extension.
     *
     * @param string $path
     * @return string|null
     */
    protected function finfoMime(string $path): ?string
    {
        if (!class_exists(\finfo::class)) {
            return null;
        }
        $finfo = new \finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file($path);

        return is_string($mime) ? $mime : null;
    }
}

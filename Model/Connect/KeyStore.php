<?php
/**
 * Writes the API key that "Connect with TackQuote" obtained, and clears it.
 *
 * The key lives where a pasted key lives, `tackquote_quotes/general/api_key`,
 * at the DEFAULT scope, ENCRYPTED. A pasted key is encrypted by the field's
 * backend model `Magento\Config\Model\Config\Backend\Encrypted`, whose
 * `beforeSave()` calls `EncryptorInterface::encrypt()` (read from
 * magento/magento2 2.4-develop, 2026-10-11). That backend model runs only on
 * the Stores > Configuration save path; `WriterInterface::save()` writes
 * `core_config_data` directly and runs no backend model, so this class
 * encrypts with the same encryptor itself. `Model\Config::getApiKey()`
 * decrypts both the same way.
 *
 * After a write the `config` cache type is cleaned, so the next request reads
 * the new value (UNVERIFIED on a store with a remote config cache: see README).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Connect;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use TackQuote\Quotes\Model\Config;

class KeyStore
{
    /**
     * @var WriterInterface
     */
    private $writer;

    /**
     * @var EncryptorInterface
     */
    private $encryptor;

    /**
     * @var TypeListInterface
     */
    private $cacheTypes;

    /**
     * @param WriterInterface $writer
     * @param EncryptorInterface $encryptor
     * @param TypeListInterface $cacheTypes
     */
    public function __construct(
        WriterInterface $writer,
        EncryptorInterface $encryptor,
        TypeListInterface $cacheTypes
    ) {
        $this->writer = $writer;
        $this->encryptor = $encryptor;
        $this->cacheTypes = $cacheTypes;
    }

    /**
     * Save a key Connect obtained, encrypted, and record how it arrived.
     *
     * @param string $apiKey
     * @param int $now
     * @return void
     */
    public function saveConnectedKey(string $apiKey, int $now): void
    {
        $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $this->writer->save(Config::API_KEY_PATH, $this->encryptor->encrypt($apiKey), $scope, 0);
        $this->writer->save(Config::XML_PATH_CONNECTED_VIA, Config::VIA_CONNECT, $scope, 0);
        $this->writer->save(Config::XML_PATH_CONNECTED_AT, (string) $now, $scope, 0);
        // Not the key: its SHA-256, so Config::getConnectedVia() can tell a key pasted later.
        $this->writer->save(Config::XML_PATH_CONNECTED_KEY_SHA256, hash('sha256', $apiKey), $scope, 0);
        $this->cacheTypes->cleanType('config');
    }

    /**
     * Remove the default-scope key and the Connect record.
     *
     * @return void
     */
    public function clear(): void
    {
        $scope = ScopeConfigInterface::SCOPE_TYPE_DEFAULT;
        $this->writer->delete(Config::API_KEY_PATH, $scope, 0);
        $this->writer->delete(Config::XML_PATH_CONNECTED_VIA, $scope, 0);
        $this->writer->delete(Config::XML_PATH_CONNECTED_AT, $scope, 0);
        $this->writer->delete(Config::XML_PATH_CONNECTED_KEY_SHA256, $scope, 0);
        $this->cacheTypes->cleanType('config');
    }
}

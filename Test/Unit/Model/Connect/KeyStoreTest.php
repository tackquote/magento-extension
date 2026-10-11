<?php
/**
 * Where a Connect key is written: the API key path, default scope, ENCRYPTED
 * (WriterInterface runs no backend model, so KeyStore encrypts itself), plus the
 * Connect record (via, time, the key's SHA-256, never the key).
 *
 * Mutants run (listed in the PR): save the key unencrypted, save the key
 * itself as the fingerprint, skip the config cache clean.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Connect;

use Magento\Framework\App\Cache\TypeListInterface;
use Magento\Framework\App\Config\Storage\WriterInterface;
use Magento\Framework\Encryption\EncryptorInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Connect\KeyStore;

/**
 * @covers \TackQuote\Quotes\Model\Connect\KeyStore
 */
class KeyStoreTest extends TestCase
{
    private const KEY = 'tack_sk_aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa';

    /** @var array<int, array> */
    private $calls = [];

    private function store(): KeyStore
    {
        $writer = $this->createMock(WriterInterface::class);
        $writer->method('save')->willReturnCallback(function (...$args): void {
            $this->calls[] = array_merge(['save'], $args);
        });
        $writer->method('delete')->willReturnCallback(function (...$args): void {
            $this->calls[] = array_merge(['delete'], $args);
        });
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static function ($v): string {
            return '0:3:' . base64_encode(strrev((string) $v));
        });
        $types = $this->createMock(TypeListInterface::class);
        $types->method('cleanType')->willReturnCallback(function ($type): void {
            $this->calls[] = ['clean', $type];
        });

        return new KeyStore($writer, $encryptor, $types);
    }

    public function testSavesTheKeyEncryptedAtTheDefaultScopeWithTheConnectRecord(): void
    {
        $this->store()->saveConnectedKey(self::KEY, 1700000000);
        $this->assertSame([
            ['save', 'tackquote_quotes/general/api_key', '0:3:' . base64_encode(strrev(self::KEY)), 'default', 0],
            ['save', Config::XML_PATH_CONNECTED_VIA, Config::VIA_CONNECT, 'default', 0],
            ['save', Config::XML_PATH_CONNECTED_AT, '1700000000', 'default', 0],
            ['save', Config::XML_PATH_CONNECTED_KEY_SHA256, hash('sha256', self::KEY), 'default', 0],
            ['clean', 'config'],
        ], $this->calls);
        $this->assertStringNotContainsString(self::KEY, json_encode($this->calls));
    }

    public function testClearRemovesTheKeyAndTheRecord(): void
    {
        $this->store()->clear();
        $this->assertSame([
            ['delete', 'tackquote_quotes/general/api_key', 'default', 0],
            ['delete', Config::XML_PATH_CONNECTED_VIA, 'default', 0],
            ['delete', Config::XML_PATH_CONNECTED_AT, 'default', 0],
            ['delete', Config::XML_PATH_CONNECTED_KEY_SHA256, 'default', 0],
            ['clean', 'config'],
        ], $this->calls);
    }
}

<?php
/**
 * The server-side half of a started connect: the verifier is stored encrypted,
 * only the state's hash is kept, and take() is single use and fails closed.
 *
 * Mutants run (listed in the PR): store the verifier in clear, delete AFTER the
 * checks, skip the state compare, skip the TTL.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Connect;

use Magento\Framework\Encryption\EncryptorInterface;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Connect\PendingConnect;

/**
 * @covers \TackQuote\Quotes\Model\Connect\PendingConnect
 */
class PendingConnectTest extends TestCase
{
    private const VERIFIER = 'dBjftJeZ4CVP-mB92K27uhbUJU1p1r_wW1gFWFOEjXk';
    private const STATE = 'c3RhdGUtc3RhdGUtc3RhdGUtc3RhdGUtc3RhdGUtc3Q';

    /** @var array<string, mixed> */
    private $rows = [];

    /** @var string[] */
    private $log = [];

    /** @var FlagManager&MockObject */
    private $flags;

    private function pending(): PendingConnect
    {
        $this->flags = $this->createMock(FlagManager::class);
        $this->flags->method('saveFlag')->willReturnCallback(function ($code, $value): bool {
            $this->log[] = 'save:' . $code;
            $this->rows[$code] = $value;
            return true;
        });
        $this->flags->method('getFlagData')->willReturnCallback(function ($code) {
            $this->log[] = 'get:' . $code;
            return $this->rows[$code] ?? null;
        });
        $this->flags->method('deleteFlag')->willReturnCallback(function ($code): bool {
            $this->log[] = 'delete:' . $code;
            unset($this->rows[$code]);
            return true;
        });
        $encryptor = $this->createMock(EncryptorInterface::class);
        $encryptor->method('encrypt')->willReturnCallback(static function ($v): string {
            return 'ENC(' . strrev((string) $v) . ')';
        });
        $encryptor->method('decrypt')->willReturnCallback(static function ($v): string {
            return preg_match('/^ENC\((.*)\)$/', (string) $v, $m) ? strrev($m[1]) : '';
        });

        return new PendingConnect($this->flags, $encryptor);
    }

    public function testStoresTheVerifierEncryptedAndOnlyTheStateHash(): void
    {
        $this->pending()->begin(7, self::VERIFIER, self::STATE, 'mgr_x', 1000);
        $row = $this->rows['tackquote_connect_pending_u7'];
        $this->assertSame('ENC(' . strrev(self::VERIFIER) . ')', $row['verifier']);
        $this->assertSame(hash('sha256', self::STATE), $row['state']);
        $this->assertStringNotContainsString(self::VERIFIER, json_encode($row));
        $this->assertStringNotContainsString(self::STATE, json_encode($row));
    }

    public function testTakeReturnsTheVerifierOnceAndDeletesBeforeChecking(): void
    {
        $pending = $this->pending();
        $pending->begin(7, self::VERIFIER, self::STATE, 'mgr_x', 1000);
        $this->log = [];
        $this->assertSame(self::VERIFIER, $pending->take(7, self::STATE, 1100));
        $this->assertSame(['get:tackquote_connect_pending_u7', 'delete:tackquote_connect_pending_u7'], $this->log);
        $this->assertNull($pending->take(7, self::STATE, 1100));
    }

    public function testAWrongStateIsRefusedAndStillSpendsThePendingConnect(): void
    {
        $pending = $this->pending();
        $pending->begin(7, self::VERIFIER, self::STATE, 'mgr_x', 1000);
        $this->assertNull($pending->take(7, 'another-state', 1100));
        $this->assertNull($pending->take(7, self::STATE, 1100));
    }

    public function testAnEmptyStateIsRefused(): void
    {
        $pending = $this->pending();
        $pending->begin(7, self::VERIFIER, self::STATE, 'mgr_x', 1000);
        $this->assertNull($pending->take(7, '', 1100));
    }

    public function testAnotherAdminUserFindsNothing(): void
    {
        $pending = $this->pending();
        $pending->begin(7, self::VERIFIER, self::STATE, 'mgr_x', 1000);
        $this->assertNull($pending->take(8, self::STATE, 1100));
        $this->assertSame(self::VERIFIER, $pending->take(7, self::STATE, 1100));
    }

    public function testExpiresAfterFifteenMinutes(): void
    {
        $pending = $this->pending();
        $pending->begin(7, self::VERIFIER, self::STATE, 'mgr_x', 1000);
        $this->assertNull($pending->take(7, self::STATE, 1000 + PendingConnect::TTL + 1));
    }

    public function testPressingAgainReplacesTheEarlierConnect(): void
    {
        $pending = $this->pending();
        $pending->begin(7, self::VERIFIER, self::STATE, 'mgr_x', 1000);
        $pending->begin(7, self::VERIFIER, 'second-state', 'mgr_y', 1001);
        $this->assertNull($pending->take(7, self::STATE, 1100));
    }
}

<?php
/**
 * How a target price and attached files travel on the quote request.
 *
 * Mutations (applied, red, restored):
 *   B1  accept a negative value in parseTargetPrice() -> testUnusableTargetsAreRefused ("negative float")
 *   B2  keep `targetPrice` on the lines for an older server -> testAnOlderServerGetsTheTargetsInTheNote
 *   B3  drop the UUID pattern check -> testAMalformedUploadIdIsRefused
 *   B4  drop the 3-file cap -> testFourIdsAreRefused
 *   B5  leave a null targetPrice on the line -> testANullTargetIsRemovedFromTheLine
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\QuotePayloadBuilder;

/**
 * @covers \TackQuote\Quotes\Model\QuotePayloadBuilder
 */
class QuotePayloadBuilderTest extends TestCase
{
    private const ID_A = '3f2b8c1e-9d4a-4b7e-8c21-5a6d7e8f9a0b';
    private const ID_B = '4a3c9d2f-0e5b-4c8f-9d32-6b7e8f9a0b1c';
    private const ID_C = '5b4d0e3a-1f6c-4d9a-8e43-7c8f9a0b1c2d';
    private const ID_D = '6c5e1f4b-2a7d-4e0b-9f54-8d9a0b1c2d3e';

    private static function token(): string
    {
        return str_repeat('Ab1_-', 8) . 'xyz';
    }

    /**
     * @return array<string, array{0: mixed, 1: float|null}>
     */
    public static function usableTargets(): array
    {
        return [
            'blank' => ['', null],
            'null' => [null, null],
            'zero' => ['0', 0.0],
            'decimal point' => ['12.5', 12.5],
            'decimal comma' => ['12,50', 12.5],
            'more than four decimals is rounded' => ['1.23456', 1.2346],
            'an integer' => [7, 7.0],
        ];
    }

    /**
     * @dataProvider usableTargets
     * @param mixed $raw
     * @param float|null $expected
     */
    public function testUsableTargetsParse($raw, ?float $expected): void
    {
        self::assertSame(['ok' => true, 'value' => $expected], (new QuotePayloadBuilder())->parseTargetPrice($raw));
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableTargets(): array
    {
        return [
            'negative' => ['-1'],
            'negative float' => [-0.5],
            'words' => ['cheap'],
            'exponent' => ['1e5'],
            'above the server bound' => ['10000000000000'],
            'two separators' => ['1.2.3'],
            'array' => [['1']],
        ];
    }

    /**
     * @dataProvider unusableTargets
     * @param mixed $raw
     */
    public function testUnusableTargetsAreRefused($raw): void
    {
        self::assertFalse((new QuotePayloadBuilder())->parseTargetPrice($raw)['ok']);
    }

    public function testANegativeTargetIsRefused(): void
    {
        self::assertSame(['ok' => false, 'value' => null], (new QuotePayloadBuilder())->parseTargetPrice('-0.01'));
    }

    /**
     * @return array<string, mixed>
     */
    private static function payload(): array
    {
        return [
            'note' => 'Please hurry',
            'currency' => 'EUR',
            'lineItems' => [
                ['sku' => 'A-1', 'name' => 'Bolt', 'quantity' => 2, 'unitPrice' => 10.0, 'targetPrice' => 8.25],
                ['sku' => 'B-2', 'name' => 'Nut', 'quantity' => 1, 'unitPrice' => 1.0, 'targetPrice' => null],
            ],
        ];
    }

    public function testANewServerGetsTheTargetPerLine(): void
    {
        $out = (new QuotePayloadBuilder())->applyTargetPrices(self::payload(), true, 'EUR');

        self::assertSame(8.25, $out['lineItems'][0]['targetPrice']);
        self::assertSame(10.0, $out['lineItems'][0]['unitPrice']);
        self::assertSame('Please hurry', $out['note']);
    }

    public function testANullTargetIsRemovedFromTheLine(): void
    {
        $out = (new QuotePayloadBuilder())->applyTargetPrices(self::payload(), true, 'EUR');

        self::assertArrayNotHasKey('targetPrice', $out['lineItems'][1]);
    }

    public function testAnOlderServerGetsTheTargetsInTheNote(): void
    {
        $out = (new QuotePayloadBuilder())->applyTargetPrices(self::payload(), false, 'EUR');

        self::assertArrayNotHasKey('targetPrice', $out['lineItems'][0]);
        self::assertArrayNotHasKey('targetPrice', $out['lineItems'][1]);
        self::assertSame("Please hurry\n\nTarget prices:\n- Bolt (A-1) x2: 8.25 EUR", $out['note']);
    }

    public function testFourDecimalsSurviveIntoTheNote(): void
    {
        $payload = self::payload();
        $payload['note'] = '';
        $payload['lineItems'][0]['targetPrice'] = 0.1234;

        $out = (new QuotePayloadBuilder())->applyTargetPrices($payload, false, 'EUR');

        self::assertSame("Target prices:\n- Bolt (A-1) x2: 0.1234 EUR", $out['note']);
    }

    public function testNoTargetsLeavesThePayloadAsItWas(): void
    {
        $payload = self::payload();
        unset($payload['lineItems'][0]['targetPrice'], $payload['lineItems'][1]['targetPrice']);

        self::assertSame($payload, (new QuotePayloadBuilder())->applyTargetPrices($payload, false, 'EUR'));
    }

    public function testUploadIdsAreLowercasedDeduplicatedAndForwardedWithTheToken(): void
    {
        $out = (new QuotePayloadBuilder())->attachmentFields(
            strtoupper(self::ID_A) . ',' . self::ID_A . ', ' . self::ID_B,
            self::token()
        );

        self::assertSame(['ok' => true, 'uploadIds' => [self::ID_A, self::ID_B], 'uploadToken' => self::token()], $out);
    }

    public function testNoIdsIsNotAnError(): void
    {
        self::assertSame(['ok' => true, 'uploadIds' => []], (new QuotePayloadBuilder())->attachmentFields('', ''));
    }

    public function testASignedInRequestNeedsNoToken(): void
    {
        self::assertSame(
            ['ok' => true, 'uploadIds' => [self::ID_A]],
            (new QuotePayloadBuilder())->attachmentFields([self::ID_A], '')
        );
    }

    public function testAMalformedUploadIdIsRefused(): void
    {
        self::assertFalse((new QuotePayloadBuilder())->attachmentFields('../../etc/passwd', self::token())['ok']);
        // A v1 UUID is not what TackQuote issues.
        self::assertFalse(
            (new QuotePayloadBuilder())->attachmentFields('3f2b8c1e-9d4a-1b7e-8c21-5a6d7e8f9a0b', self::token())['ok']
        );
    }

    public function testFourIdsAreRefused(): void
    {
        self::assertFalse(
            (new QuotePayloadBuilder())->attachmentFields([self::ID_A, self::ID_B, self::ID_C, self::ID_D], '')['ok']
        );
    }

    public function testAMalformedTokenIsRefused(): void
    {
        self::assertFalse((new QuotePayloadBuilder())->attachmentFields(self::ID_A, 'short')['ok']);
    }
}

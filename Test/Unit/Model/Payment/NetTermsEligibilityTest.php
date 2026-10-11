<?php
/**
 * When an order may go on net terms (the WooCommerce gateway's rules), and the tab's view.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Payment;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Payment\NetTermsEligibility;

/**
 * @covers \TackQuote\Quotes\Model\Payment\NetTermsEligibility
 */
class NetTermsEligibilityTest extends TestCase
{
    private static function standing(array $account = [], array $extra = []): array
    {
        return array_merge([
            'status' => 'standing',
            'state' => 'approved',
            'application' => null,
            'account' => array_merge(
                ['status' => 'active', 'termsDays' => 30, 'creditLimit' => '1000.00', 'currency' => 'USD', 'available' => '400.00'],
                $account
            ),
        ], $extra);
    }

    public function testAnActiveLineWithEnoughAvailableCreditIsEligible(): void
    {
        self::assertSame(
            ['eligible' => true, 'reason' => 'eligible', 'termsDays' => 30],
            (new NetTermsEligibility())->evaluate(self::standing(), '400.00', 'usd')
        );
    }

    /**
     * @return array<string, array{0: mixed, 1: mixed, 2: string, 3: string}>
     */
    public static function refusals(): array
    {

        return [
            'anonymous' => [['status' => 'anonymous'], 10, 'USD', 'not_standing'],
            'unlinked' => [['status' => 'unlinked', 'reason' => 'customer_not_linked'], 10, 'USD', 'not_standing'],
            'no line' => [array_merge(self::standing(), ['account' => null]), 10, 'USD', 'no_account'],
            'line on hold' => [self::standing(['status' => 'on_hold']), 10, 'USD', 'account_inactive'],
            'no terms days' => [self::standing(['termsDays' => 0]), 10, 'USD', 'no_terms_days'],
            'terms days as a string' => [self::standing(['termsDays' => '30']), 10, 'USD', 'no_terms_days'],
            'other currency' => [self::standing(), 10, 'EUR', 'currency_mismatch'],
            'one cent over available' => [self::standing(), '400.01', 'USD', 'insufficient_available_credit'],
            'no available, over the limit' => [self::standing(['available' => null]), '1000.01', 'USD', 'over_limit'],
            'no available and no limit' => [self::standing(['available' => null, 'creditLimit' => null]), 1, 'USD', 'no_limit'],
            'negative amount' => [self::standing(), -1, 'USD', 'bad_amount'],
            'not a number' => [self::standing(), 'abc', 'USD', 'bad_amount'],
            'not even an array' => ['standing', 1, 'USD', 'not_standing'],
        ];
    }

    /**
     * @dataProvider refusals
     * @param mixed $answer
     * @param mixed $amount
     */
    public function testRefusals($answer, $amount, string $currency, string $reason): void
    {
        $verdict = (new NetTermsEligibility())->evaluate($answer, $amount, $currency);

        self::assertFalse($verdict['eligible']);
        self::assertSame($reason, $verdict['reason']);
    }

    public function testTheTabView(): void
    {
        $e = new NetTermsEligibility();
        self::assertSame('active', $e->view(self::standing())['state']);
        self::assertSame('400.00', $e->view(self::standing())['available']);
        self::assertSame('pending', $e->view(['status' => 'standing', 'state' => 'pending', 'account' => null])['state']);
        self::assertSame('declined', $e->view(['status' => 'standing', 'state' => 'declined', 'account' => null])['state']);
        self::assertSame('none', $e->view(null)['state']);
        self::assertSame('none', $e->view(['status' => 'unlinked'])['state']);
    }
}

<?php
/**
 * Whether a TackQuote net-terms answer lets this order go on terms, and what the Net terms
 * tab shows. Pure: no I/O.
 *
 * The answer is `GET /storefront/v1/net-terms` (`NetTermsResult`, tack
 * storefront-core/storefront-results.ts): `{status: anonymous}`, `{status: unlinked}`, or
 * `{status: standing, state: none|pending|approved|declined, application, account:
 * {status, termsDays, creditLimit, currency, available?} | null}`. A port of the WooCommerce
 * gateway's Tack_Gateway_Net_Terms::evaluate() and the tab's net_terms_view(), so both
 * stores decide the same way.
 *
 * FAIL CLOSED: every check that cannot be confirmed is a refusal with a named reason.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Payment;

class NetTermsEligibility
{
    /** Money compared in ten-thousandths, so 0.1 + 0.2 never decides a credit check. */
    private const MONEY_SCALE = 10000;

    /**
     * May an order of `$amount` in `$currency` go on terms?
     *
     * @param mixed $answer The decoded net-terms answer.
     * @param mixed $amount Order grand total.
     * @param string $currency Order currency (ISO 4217).
     * @return array{eligible: bool, reason: string, termsDays: int}
     */
    public function evaluate($answer, $amount, string $currency): array
    {
        if (!is_array($answer) || ($answer['status'] ?? null) !== 'standing') {
            return $this->verdict('not_standing');
        }
        $account = isset($answer['account']) && is_array($answer['account']) ? $answer['account'] : null;
        if ($account === null) {
            return $this->verdict('no_account');
        }
        if (($account['status'] ?? null) !== 'active') {
            return $this->verdict('account_inactive');
        }
        $days = isset($account['termsDays']) && is_int($account['termsDays']) ? $account['termsDays'] : 0;
        if ($days <= 0) {
            return $this->verdict('no_terms_days');
        }
        $accountCurrency = isset($account['currency']) && is_string($account['currency'])
            ? strtoupper(trim($account['currency']))
            : '';
        if ($accountCurrency === '' || strtoupper(trim($currency)) !== $accountCurrency) {
            return $this->verdict('currency_mismatch');
        }
        if (!is_numeric($amount) || !is_finite((float) $amount) || (float) $amount < 0) {
            return $this->verdict('bad_amount');
        }
        $units = (int) round((float) $amount * self::MONEY_SCALE);

        // Remaining credit when the server sends it: the order must fit what is LEFT.
        $available = $this->number($account['available'] ?? null);
        if ($available !== null) {
            if ($units > (int) round($available * self::MONEY_SCALE)) {
                return $this->verdict('insufficient_available_credit');
            }
        } else {
            // An older server reports only the whole line.
            $limit = $this->number($account['creditLimit'] ?? null);
            if ($limit === null) {
                return $this->verdict('no_limit');
            }
            if ($units > (int) round($limit * self::MONEY_SCALE)) {
                return $this->verdict('over_limit');
            }
        }

        return ['eligible' => true, 'reason' => 'eligible', 'termsDays' => $days];
    }

    /**
     * What the Net terms tab shows.
     *
     * `active`: a standing line that is active, with positive terms days and a currency (what
     * checkout also requires). `pending`: an application under review and no active line.
     * `declined`: the latest application was declined and there is no active line. Anything
     * else, including an unknown shape or an unreachable TackQuote, is `none` (the tab shows
     * the application form and claims nothing).
     *
     * @param mixed $answer
     * @return array{state: string, termsDays: int, creditLimit: string, available: string, currency: string}
     */
    public function view($answer): array
    {
        $view = ['state' => 'none', 'termsDays' => 0, 'creditLimit' => '', 'available' => '', 'currency' => ''];
        if (!is_array($answer) || ($answer['status'] ?? null) !== 'standing') {
            return $view;
        }
        $account = isset($answer['account']) && is_array($answer['account']) ? $answer['account'] : null;
        $days = $account !== null && isset($account['termsDays']) && is_int($account['termsDays'])
            ? $account['termsDays']
            : 0;
        $currency = $account !== null && isset($account['currency']) && is_string($account['currency'])
            ? strtoupper(trim($account['currency']))
            : '';
        if ($account !== null && ($account['status'] ?? null) === 'active' && $days > 0
            && preg_match('/^[A-Z]{3}$/', $currency) === 1) {
            $view['state'] = 'active';
            $view['termsDays'] = $days;
            $view['currency'] = $currency;
            foreach (['creditLimit', 'available'] as $field) {
                $number = $this->number($account[$field] ?? null);
                if ($number !== null) {
                    $view[$field] = (string) $account[$field];
                }
            }

            return $view;
        }
        $state = $answer['state'] ?? null;
        if ($state === 'pending' || $state === 'declined') {
            $view['state'] = $state;
        }

        return $view;
    }

    /**
     * A finite number from a JSON value (the server sends money as strings), or null.
     *
     * @param mixed $value
     * @return float|null
     */
    private function number($value): ?float
    {
        if ((is_string($value) || is_int($value) || is_float($value)) && is_numeric($value)
            && is_finite((float) $value)) {
            return (float) $value;
        }

        return null;
    }

    /**
     * @param string $reason
     * @return array{eligible: bool, reason: string, termsDays: int}
     */
    private function verdict(string $reason): array
    {
        return ['eligible' => false, 'reason' => $reason, 'termsDays' => 0];
    }
}

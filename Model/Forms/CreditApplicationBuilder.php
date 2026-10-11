<?php
/**
 * Builds the net-terms (credit) application TackQuote's `POST /storefront/v1/credit-application`
 * takes, from the Net terms tab's posted values.
 *
 * Bounds are the server's SubmitCreditApplicationDto (tack credit/dto/credit-application.dto.ts):
 * legalBusinessName <= 200 (required), contactEmail (the ACCOUNT email, never a form
 * field), contactPhone <= 40, taxId <= 64, billingAddress {line1, line2, city, region,
 * postalCode, country}, requestedLimit 0..100,000,000, requestedTermsDays 0..365,
 * tradeReferences at most 10 of {companyName, contactName, email, phone} (this form offers
 * 3), notes <= 2000. Unknown keys are refused by the server (`forbidNonWhitelisted`), so
 * only these are ever set. A port of the WooCommerce plugin's
 * Tack_Storefront_Forms::validate_credit_payload(), so both stores send the same body.
 *
 * Pure: no I/O.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Forms;

class CreditApplicationBuilder
{
    /** Payment-terms lengths the form offers (days). */
    public const TERMS_DAYS = [15, 30, 45, 60, 90];

    /** Trade references the form offers. */
    public const MAX_REFERENCES = 3;

    public const MAX_REQUESTED_LIMIT = 100000000;

    /**
     * @var FormDefinition
     */
    private $definition;

    /**
     * @param FormDefinition $definition
     */
    public function __construct(FormDefinition $definition)
    {
        $this->definition = $definition;
    }

    /**
     * The payload, or why not.
     *
     * @param array $posted Raw posted values (`tack_ct[...]`).
     * @param string $accountEmail The signed-in customer's account email.
     * @return array{payload: array, error: string|null}
     */
    public function build(array $posted, string $accountEmail): array
    {
        $email = strtolower(trim($accountEmail));
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return $this->fail((string) __('Your account has no valid email address. Please add one under Account Information first.'));
        }
        $payload = ['contactEmail' => $email];

        $name = $this->line($posted['legalBusinessName'] ?? null);
        if ($name === '') {
            return $this->fail((string) __('Legal business name is required.'));
        }
        if (strlen($name) > 200) {
            return $this->fail((string) __('Legal business name is too long.'));
        }
        $payload['legalBusinessName'] = $name;

        foreach (['contactPhone' => [40, __('Contact phone is too long.')], 'taxId' => [64, __('Tax / VAT ID is too long.')]] as $key => [$max, $message]) {
            $value = $this->line($posted[$key] ?? null);
            if (strlen($value) > $max) {
                return $this->fail((string) $message);
            }
            if ($value !== '') {
                $payload[$key] = $value;
            }
        }

        $address = [];
        if (isset($posted['billingAddress']) && is_array($posted['billingAddress'])) {
            foreach (FormDefinition::ADDRESS_PARTS as $part) {
                $value = $this->line($posted['billingAddress'][$part] ?? null);
                if ($value !== '') {
                    $address[$part] = mb_substr($value, 0, 200);
                }
            }
        }
        if ($address !== []) {
            foreach (FormDefinition::ADDRESS_REQUIRED as $part) {
                if (empty($address[$part])) {
                    return $this->fail((string) __(
                        'Billing address: %1 is required.',
                        $this->definition->addressPartLabel($part)
                    ));
                }
            }
            $payload['billingAddress'] = $address;
        }

        $limit = $this->line($posted['requestedLimit'] ?? null);
        if ($limit !== '') {
            if (!is_numeric($limit) || (float) $limit < 0 || (float) $limit > self::MAX_REQUESTED_LIMIT) {
                return $this->fail((string) __('Requested credit limit must be a number between 0 and 100,000,000.'));
            }
            $payload['requestedLimit'] = round((float) $limit, 2);
        }

        $days = $this->line($posted['requestedTermsDays'] ?? null);
        if ($days !== '') {
            if (!ctype_digit($days) || !in_array((int) $days, self::TERMS_DAYS, true)) {
                return $this->fail((string) __('Please choose one of the offered payment terms.'));
            }
            $payload['requestedTermsDays'] = (int) $days;
        }

        $references = [];
        $caps = ['companyName' => 200, 'contactName' => 200, 'email' => 320, 'phone' => 40];
        foreach (array_values((array) ($posted['tradeReferences'] ?? [])) as $row) {
            if (count($references) >= self::MAX_REFERENCES) {
                break;
            }
            if (!is_array($row)) {
                continue;
            }
            $reference = [];
            foreach ($caps as $part => $cap) {
                $value = $this->line($row[$part] ?? null);
                if ($value === '') {
                    continue;
                }
                if (strlen($value) > $cap) {
                    return $this->fail((string) __('A trade reference entry is too long.'));
                }
                if ($part === 'email' && filter_var($value, FILTER_VALIDATE_EMAIL) === false) {
                    return $this->fail((string) __('A trade reference email address is not valid.'));
                }
                $reference[$part] = $value;
            }
            if ($reference !== []) {
                $references[] = $reference;
            }
        }
        if ($references !== []) {
            $payload['tradeReferences'] = $references;
        }

        $notes = isset($posted['notes']) && is_scalar($posted['notes']) ? trim(strip_tags((string) $posted['notes'])) : '';
        if (strlen($notes) > FormDefinition::MAX_TEXTAREA) {
            return $this->fail((string) __('Notes are too long (2,000 characters at most).'));
        }
        if ($notes !== '') {
            $payload['notes'] = $notes;
        }

        return ['payload' => $payload, 'error' => null];
    }

    /**
     * One line of plain text.
     *
     * @param mixed $value
     * @return string
     */
    private function line($value): string
    {
        return is_scalar($value) ? trim((string) preg_replace('/\s+/u', ' ', strip_tags((string) $value))) : '';
    }

    /**
     * @param string $message
     * @return array{payload: array, error: string}
     */
    private function fail(string $message): array
    {
        return ['payload' => [], 'error' => $message];
    }
}

<?php
/**
 * The net-terms application body: only the DTO's keys, within its bounds, the account email.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Forms;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Forms\CreditApplicationBuilder;
use TackQuote\Quotes\Model\Forms\FormDefinition;

/**
 * @covers \TackQuote\Quotes\Model\Forms\CreditApplicationBuilder
 */
class CreditApplicationBuilderTest extends TestCase
{
    private function build(array $posted, string $email = 'Buyer@Example.test'): array
    {
        return (new CreditApplicationBuilder(new FormDefinition()))->build($posted, $email);
    }

    public function testAFullApplication(): void
    {
        $result = $this->build([
            'legalBusinessName' => ' Acme Ltd ',
            'contactPhone' => '+44 1',
            'taxId' => 'GB1',
            'billingAddress' => ['line1' => '1 Road', 'city' => 'Leeds', 'postalCode' => 'LS1', 'country' => 'GB'],
            'requestedLimit' => '5000.5',
            'requestedTermsDays' => '30',
            'tradeReferences' => [['companyName' => 'Ref Co', 'email' => 'ref@example.test'], [], ['phone' => '']],
            'notes' => 'Thanks',
            'contactEmail' => 'ignored@example.test',
            'tenantId' => 'ignored',
        ]);

        self::assertNull($result['error']);
        self::assertSame([
            'contactEmail' => 'buyer@example.test',
            'legalBusinessName' => 'Acme Ltd',
            'contactPhone' => '+44 1',
            'taxId' => 'GB1',
            'billingAddress' => ['line1' => '1 Road', 'city' => 'Leeds', 'postalCode' => 'LS1', 'country' => 'GB'],
            'requestedLimit' => 5000.5,
            'requestedTermsDays' => 30,
            'tradeReferences' => [['companyName' => 'Ref Co', 'email' => 'ref@example.test']],
            'notes' => 'Thanks',
        ], $result['payload']);
    }

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function refusals(): array
    {
        return [
            'no name' => [[], 'Legal business name is required.'],
            'terms not offered' => [
                ['legalBusinessName' => 'A', 'requestedTermsDays' => '31'],
                'Please choose one of the offered payment terms.'
            ],
            'negative limit' => [
                ['legalBusinessName' => 'A', 'requestedLimit' => '-1'],
                'Requested credit limit must be a number between 0 and 100,000,000.'
            ],
            'partial address' => [
                ['legalBusinessName' => 'A', 'billingAddress' => ['line1' => 'x']],
                'Billing address: City is required.'
            ],
            'bad reference email' => [
                ['legalBusinessName' => 'A', 'tradeReferences' => [['email' => 'nope']]],
                'A trade reference email address is not valid.'
            ],
        ];
    }

    /**
     * @dataProvider refusals
     */
    public function testRefusals(array $posted, string $message): void
    {
        self::assertSame($message, $this->build($posted)['error']);
    }

    public function testNoAccountEmailRefuses(): void
    {
        self::assertNotNull($this->build(['legalBusinessName' => 'A'], '')['error']);
    }
}

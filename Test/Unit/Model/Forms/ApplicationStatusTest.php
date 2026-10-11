<?php
/**
 * The wholesale status reader fails closed: only a well-formed `application` answer with a
 * known state is believed; everything else is UNKNOWN (the tab shows the form, claims nothing).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Forms;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Forms\AccountApiClient;
use TackQuote\Quotes\Model\Forms\AccountIdentity;
use TackQuote\Quotes\Model\Forms\ApplicationStatus;

/**
 * @covers \TackQuote\Quotes\Model\Forms\ApplicationStatus
 */
class ApplicationStatusTest extends TestCase
{
    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function answers(): array
    {
        $ok = static fn (array $data): array => ['ok' => true, 'status' => 200, 'data' => $data];

        return [
            'approved' => [$ok(['status' => 'application', 'state' => 'approved']), ApplicationStatus::APPROVED],
            'pending' => [$ok(['status' => 'application', 'state' => 'pending']), ApplicationStatus::PENDING],
            'rejected' => [$ok(['status' => 'application', 'state' => 'rejected']), ApplicationStatus::DECLINED],
            'none' => [$ok(['status' => 'application', 'state' => 'none']), ApplicationStatus::NONE],
            'unknown state' => [$ok(['status' => 'application', 'state' => 'approvedish']), ApplicationStatus::UNKNOWN],
            'unlinked' => [
                $ok(['status' => 'unlinked', 'reason' => 'customer_not_linked']),
                ApplicationStatus::UNKNOWN
            ],
            'anonymous' => [$ok(['status' => 'anonymous']), ApplicationStatus::UNKNOWN],
            'a failed call carrying approved data' => [
                ['ok' => false, 'status' => 500, 'data' => ['status' => 'application', 'state' => 'approved']],
                ApplicationStatus::UNKNOWN,
            ],
            'older server 404' => [['ok' => false, 'status' => 404], ApplicationStatus::UNKNOWN],
        ];
    }

    /**
     * @dataProvider answers
     */
    public function testStateOf(array $result, string $expected): void
    {
        self::assertSame(
            $expected,
            (new ApplicationStatus($this->createMock(AccountApiClient::class)))->stateOf($result)
        );
    }

    public function testNoCustomerIdAsksNothingAndKnowsNothing(): void
    {
        $client = $this->createMock(AccountApiClient::class);
        $client->expects($this->never())->method('getWholesaleApplication');

        $status = new ApplicationStatus($client);
        self::assertSame(
            ApplicationStatus::UNKNOWN,
            $status->wholesale(new AccountIdentity(42, 'b@example.test', '', true))
        );
    }
}

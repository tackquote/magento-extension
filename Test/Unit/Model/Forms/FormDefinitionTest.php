<?php
/**
 * The wholesale submission payload: answers in the shapes the server validates, showIf, the
 * required rules, and which posted files belong to which field.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Forms;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Forms\FormDefinition;

/**
 * @covers \TackQuote\Quotes\Model\Forms\FormDefinition
 */
class FormDefinitionTest extends TestCase
{
    private function fields(): array
    {
        return (new FormDefinition())->normalise(['fields' => [
            ['key' => 'companyName', 'type' => 'text', 'label' => 'Company', 'required' => true],
            ['key' => 'eu', 'type' => 'checkbox', 'label' => 'EU business'],
            [
                'key' => 'vat',
                'type' => 'tax_id',
                'label' => 'VAT',
                'required' => true,
                'showIf' => ['field' => 'eu', 'equals' => true]
            ],
            ['key' => 'lines', 'type' => 'multiselect', 'label' => 'Lines', 'options' => ['A', 'B']],
            ['key' => 'ship', 'type' => 'address', 'label' => 'Ship to'],
            ['key' => 'cert', 'type' => 'file', 'label' => 'Certificate', 'required' => true],
            ['key' => 'bad key!', 'type' => 'text', 'label' => 'Dropped'],
            ['key' => 'companyName', 'type' => 'text', 'label' => 'Duplicate'],
        ]])['fields'];
    }

    public function testNormaliseKeepsOnlyUsableUniqueKeys(): void
    {
        self::assertSame(
            ['companyName', 'eu', 'vat', 'lines', 'ship', 'cert'],
            array_column($this->fields(), 'key')
        );
    }

    public function testTheSubmissionPayloadHasTheServerShapes(): void
    {
        $result = (new FormDefinition())->collectAnswers($this->fields(), [
            'companyName' => '  Acme <b>Ltd</b> ',
            'eu' => '1',
            'vat' => 'GB123',
            'lines' => ['B', 'Z', 'B'],
            'ship' => ['line1' => '1 Road', 'city' => 'Leeds', 'postalCode' => 'LS1', 'country' => 'GB', 'evil' => 'x'],
            'cert' => 'ignored: files are posted separately',
        ]);

        self::assertNull($result['error']);
        self::assertSame([
            'companyName' => 'Acme Ltd',
            'eu' => true,
            'vat' => 'GB123',
            'lines' => ['B'],
            'ship' => ['line1' => '1 Road', 'city' => 'Leeds', 'postalCode' => 'LS1', 'country' => 'GB'],
        ], $result['values']);
    }

    public function testAHiddenRequiredFieldIsNotDemandedAndItsAnswerIsDropped(): void
    {
        $result = (new FormDefinition())->collectAnswers($this->fields(), [
            'companyName' => 'Acme',
            'vat' => 'should be dropped',
        ]);

        self::assertNull($result['error']);
        self::assertArrayNotHasKey('vat', $result['values']);
        self::assertFalse($result['values']['eu']);
    }

    public function testAShownRequiredFieldMustBeAnswered(): void
    {
        $result = (new FormDefinition())->collectAnswers($this->fields(), ['companyName' => 'Acme', 'eu' => '1']);
        self::assertSame('VAT is required.', $result['error']);

        $result = (new FormDefinition())->collectAnswers($this->fields(), []);
        self::assertSame('Company is required.', $result['error']);
    }

    public function testAnAnsweredAddressNeedsItsRequiredParts(): void
    {
        $result = (new FormDefinition())->collectAnswers($this->fields(), [
            'companyName' => 'Acme',
            'ship' => ['line1' => '1 Road'],
        ]);
        self::assertSame('Ship to: City is required.', $result['error']);
    }

    public function testTooLongIsRefused(): void
    {
        $result = (new FormDefinition())->collectAnswers(
            $this->fields(),
            ['companyName' => str_repeat('a', FormDefinition::MAX_TEXT + 1)]
        );
        self::assertSame('Company is too long.', $result['error']);
    }

    public function testFilesBelongOnlyToShownFileFieldsAndARequiredOneMustBeThere(): void
    {
        $def = new FormDefinition();
        $missing = $def->pickFiles($this->fields(), [], ['other' => ['tmp_name' => '/tmp/x']]);
        self::assertSame('Certificate is required.', $missing['error']);

        $picked = $def->pickFiles(
            $this->fields(),
            [],
            ['cert' => ['tmp_name' => '/tmp/a'], 'other' => ['tmp_name' => '/tmp/x']]
        );
        self::assertNull($picked['error']);
        self::assertSame(['cert'], array_keys($picked['wanted']));
    }

    public function testNormaliseKeepsTheSellersCalendar(): void
    {
        $form = (new FormDefinition())->normalise([
            'fields' => [['key' => 'start', 'type' => 'date', 'min' => 'today']],
            'calendar' => ['timeZone' => 'Asia/Kolkata', 'today' => '2031-03-04'],
        ]);

        self::assertSame(['timeZone' => 'Asia/Kolkata', 'today' => '2031-03-04'], $form['calendar']);
    }

    /**
     * @return array<string, array{0: mixed}>
     */
    public static function unusableCalendars(): array
    {
        return [
            'absent (an older TackQuote server)' => [null],
            'not an object' => ['Asia/Kolkata'],
            'an impossible day' => [['timeZone' => 'Asia/Kolkata', 'today' => '2031-02-30']],
            'a day with a time' => [['timeZone' => 'Asia/Kolkata', 'today' => '2031-03-04T00:00:00Z']],
            'a zone that is not an IANA name' => [['timeZone' => '"><script>x</script>', 'today' => '2031-03-04']],
            'a zone that is not a string' => [['timeZone' => 5, 'today' => '2031-03-04']],
        ];
    }

    /**
     * @dataProvider unusableCalendars
     * @param mixed $calendar
     */
    public function testAnUnusableCalendarIsIgnored($calendar): void
    {
        $raw = ['fields' => [['key' => 'start', 'type' => 'date', 'min' => 'today']]];
        if ($calendar !== null) {
            $raw['calendar'] = $calendar;
        }

        self::assertNull((new FormDefinition())->normalise($raw)['calendar']);
    }

    public function testOneAndThreePartZonesAreAccepted(): void
    {
        $definition = new FormDefinition();

        self::assertNotNull($definition->calendar(['calendar' => ['timeZone' => 'UTC', 'today' => '2031-03-04']]));
        self::assertNotNull(
            $definition->calendar(['calendar' => ['timeZone' => 'America/Indiana/Knox', 'today' => '2031-03-04']])
        );
    }
    /**
     * The seller's today is re-derived at render time from the zone and the store clock,
     * clamped to [sent, sent + 1 day].
     *
     * @return array<string, array{0: string, 1: string, 2: string, 3: string}>
     */
    public static function renderTimeDays(): array
    {
        return [
            // 18:00 UTC on Mar 4 is 23:30 in Kolkata: the sent day holds.
            'same day' => ['Asia/Kolkata', '2031-03-04', '2031-03-04T18:00:00Z', '2031-03-04'],
            // A cached definition read after the seller's midnight (00:30 on Mar 5 in Kolkata).
            'rolled over past the seller\'s midnight' => [
                'Asia/Kolkata', '2031-03-04', '2031-03-04T19:00:00Z', '2031-03-05',
            ],
            'a store clock a day behind keeps the sent day' => [
                'Asia/Kolkata', '2031-03-04', '2031-03-03T10:00:00Z', '2031-03-04',
            ],
            'a store clock days ahead is held to the day after' => [
                'Asia/Kolkata', '2031-03-04', '2031-03-09T10:00:00Z', '2031-03-05',
            ],
            'the day after rolls over a month end' => [
                'Asia/Kolkata', '2031-02-28', '2031-03-09T10:00:00Z', '2031-03-01',
            ],
            // US DST ends 2026-11-01 06:00 UTC; 05:30 UTC is 01:30 EDT on Nov 1.
            'DST boundary in New York' => [
                'America/New_York', '2026-10-31', '2026-11-01T05:30:00Z', '2026-11-01',
            ],
            'the repeated hour after the change is still the same day' => [
                'America/New_York', '2026-11-01', '2026-11-01T06:30:00Z', '2026-11-01',
            ],
            // A name that passes the IANA pattern but that PHP does not know.
            'an unknown zone falls back to the sent day' => [
                'Mars/Olympus_Mons', '2031-03-04', '2031-03-09T10:00:00Z', '2031-03-04',
            ],
        ];
    }

    /**
     * @dataProvider renderTimeDays
     */
    public function testTheSellersTodayIsReDerivedAtRenderTime(
        string $zone,
        string $sent,
        string $now,
        string $expected
    ): void {
        $calendar = (new FormDefinition())->calendar(
            ['calendar' => ['timeZone' => $zone, 'today' => $sent]],
            new DateTimeImmutable($now)
        );

        self::assertSame(['timeZone' => $zone, 'today' => $expected], $calendar);
    }

    public function testNormaliseKeepsTheSentDaySoTheCacheCanBeReDerived(): void
    {
        // normalise() reads no clock: the cached form holds the server's day, and calendar()
        // on that cached form moves it on.
        $form = (new FormDefinition())->normalise([
            'fields' => [['key' => 'start', 'type' => 'date', 'min' => 'today']],
            'calendar' => ['timeZone' => 'UTC', 'today' => '2020-01-01'],
        ]);

        self::assertSame('2020-01-01', $form['calendar']['today']);
        self::assertSame('2020-01-02', (new FormDefinition())->calendar($form)['today']);
    }
}

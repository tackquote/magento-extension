<?php
/**
 * The wholesale submission payload: answers in the shapes the server validates, showIf, the
 * required rules, and which posted files belong to which field.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Forms;

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
}

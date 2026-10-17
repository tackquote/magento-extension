<?php
/**
 * The wholesale form renderer: every TackQuote field type, the names the POST controller
 * reads back, escaping of the seller's and the shopper's text, and file fields.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Forms;

use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Forms\FieldRenderer;
use TackQuote\Quotes\Model\Forms\FormDefinition;

/**
 * @covers \TackQuote\Quotes\Model\Forms\FieldRenderer
 */
class FieldRendererTest extends TestCase
{
    private function renderer(): FieldRenderer
    {
        return new FieldRenderer($this->escaper(), new FormDefinition());
    }

    private function field(array $field): array
    {
        return (new FormDefinition())->normalise(['fields' => [$field]])['fields'][0];
    }

    /**
     * @return array<string, array{0: array, 1: string}>
     */
    public static function singleLineTypes(): array
    {
        return [
            'text' => [['key' => 'company', 'type' => 'text', 'label' => 'Company'], 'type="text"'],
            'email' => [['key' => 'email', 'type' => 'email', 'label' => 'Email'], 'type="email"'],
            'tel' => [['key' => 'phone', 'type' => 'tel', 'label' => 'Phone'], 'type="tel"'],
            'number' => [['key' => 'staff', 'type' => 'number', 'label' => 'Staff'], 'type="number"'],
            'date' => [['key' => 'since', 'type' => 'date', 'label' => 'Since'], 'type="date"'],
            'tax_id' => [['key' => 'vat', 'type' => 'tax_id', 'label' => 'VAT'], 'type="text"'],
            'unknown type renders as text' => [['key' => 'x', 'type' => 'rating', 'label' => 'X'], 'type="text"'],
        ];
    }

    /**
     * @dataProvider singleLineTypes
     */
    public function testSingleLineTypesRenderAnInputNamedForThePost(array $raw, string $type): void
    {
        $html = $this->renderer()->render($this->field($raw));

        self::assertStringContainsString($type, $html);
        self::assertStringContainsString('name="tack_sf[' . $raw['key'] . ']"', $html);
    }

    public function testTextareaSelectMultiselectCheckboxAndAddress(): void
    {
        $r = $this->renderer();
        self::assertStringContainsString(
            '<textarea id="tq-sf-about" name="tack_sf[about]"',
            $r->render($this->field(['key' => 'about', 'type' => 'textarea', 'label' => 'About']))
        );

        $select = $r->render(
            $this->field(['key' => 'size', 'type' => 'select', 'label' => 'Size', 'options' => ['S', 'L']]),
            'L'
        );
        self::assertStringContainsString('<select id="tq-sf-size" name="tack_sf[size]"', $select);
        self::assertStringContainsString('<option value="L" selected="selected">L</option>', $select);

        $multi = $r->render(
            $this->field(['key' => 'lines', 'type' => 'multiselect', 'label' => 'Lines', 'options' => ['A', 'B']]),
            ['B']
        );
        self::assertSame(2, substr_count($multi, 'name="tack_sf[lines][]"'));
        self::assertStringContainsString('value="B" checked="checked"', $multi);

        $box = $r->render($this->field([
            'key' => 'terms',
            'type' => 'checkbox',
            'label' => 'Agree',
            'required' => true
        ]));
        self::assertStringContainsString(
            'type="checkbox" id="tq-sf-terms" name="tack_sf[terms]" value="1" required',
            $box
        );

        $address = $r->render($this->field([
            'key' => 'ship',
            'type' => 'address',
            'label' => 'Ship to',
            'required' => true
        ]));
        foreach (FormDefinition::ADDRESS_PARTS as $part) {
            self::assertStringContainsString('name="tack_sf[ship][' . $part . ']"', $address);
        }
        // Only the parts the server requires are required in the browser.
        self::assertSame(count(FormDefinition::ADDRESS_REQUIRED), substr_count($address, 'maxlength="200" required'));
    }

    public function testAFileFieldPostsUnderTackFileAndAcceptsOnlyWhatThisStoreChecks(): void
    {
        $field = $this->field([
            'key' => 'certificate',
            'type' => 'file',
            'label' => 'Resale certificate',
            'required' => true,
            'accept' => ['application/pdf', 'application/msword'],
            'maxSizeMb' => 9,
        ]);
        $html = $this->renderer()->render($field);

        self::assertStringContainsString('type="file" id="tq-sf-certificate" name="tack_file[certificate]"', $html);
        // A type this store cannot sniff is dropped from `accept`; the cap never exceeds 5 MB.
        self::assertStringContainsString('accept="application&#x2F;pdf"', $html);
        self::assertStringContainsString('up to 5 MB', $html);
        self::assertStringContainsString(' required/>', $html);
    }

    public function testAFileFieldWithoutFilesAllowedRendersANoteAndNoInput(): void
    {
        $html = $this->renderer()->render(
            $this->field(['key' => 'certificate', 'type' => 'file', 'label' => 'Certificate']),
            null,
            false
        );

        self::assertStringNotContainsString('type="file"', $html);
        self::assertStringContainsString('Sign in to your account to attach files.', $html);
    }

    public function testTheSellersAndTheShoppersTextAreEscaped(): void
    {
        $html = $this->renderer()->render(
            $this->field(['key' => 'company', 'type' => 'text', 'label' => '<script>x</script>', 'help' => '"><img>']),
            '"><script>y</script>'
        );

        self::assertStringNotContainsString('<script>', $html);
        self::assertStringNotContainsString('"><img>', $html);
    }

    public function testAConditionalFieldIsNeverBrowserRequiredButCarriesItsCondition(): void
    {
        $html = $this->renderer()->render($this->field([
            'key' => 'vat',
            'type' => 'text',
            'label' => 'VAT',
            'required' => true,
            'showIf' => ['field' => 'eu', 'equals' => true],
        ]));

        self::assertStringNotContainsString(' required', substr($html, strpos($html, '<input')));
        self::assertStringContainsString('data-tq-show-if-field="eu"', $html);
        self::assertStringContainsString('data-tq-show-if-equals="true"', $html);
    }

    /**
     * "No dates in the past" follows the SELLER's calendar. A day far from this machine's
     * own today proves the min comes from the calendar, not from gmdate().
     */
    public function testAPastDatesRefusedFieldUsesTheSellersToday(): void
    {
        $form = (new FormDefinition())->normalise([
            'fields' => [['key' => 'start', 'type' => 'date', 'label' => 'Start', 'min' => 'today']],
            'calendar' => ['timeZone' => 'America/Argentina/Buenos_Aires', 'today' => '2031-03-04'],
        ]);
        $html = $this->renderer()->render($form['fields'][0], null, true, $form['calendar']);

        self::assertMatchesRegularExpression(
            '/<input type="date"[^>]*name="tack_sf\[start\]"[^>]*min="2031-03-04"/',
            $html
        );
        self::assertStringContainsString(
            'data-tq-seller-zone="America&#x2F;Argentina&#x2F;Buenos_Aires" data-tq-seller-today="2031-03-04"',
            $html
        );
        self::assertStringContainsString(
            '<div class="note tq-seller-calendar" id="tq-sf-start-calendar" hidden="hidden">'
            . 'Dates follow the seller&#039;s calendar (Buenos Aires). Today there is '
            . '<span class="tq-seller-day">2031-03-04</span>.</div>',
            $html
        );
    }

    public function testWithoutACalendarAPastDatesRefusedFieldKeepsThisStoresToday(): void
    {
        $field = $this->field(['key' => 'start', 'type' => 'date', 'label' => 'Start', 'min' => 'today']);
        $html = $this->renderer()->render($field);

        self::assertStringContainsString('min="' . gmdate('Y-m-d') . '"', $html);
        self::assertStringNotContainsString('data-tq-seller', $html);
        self::assertStringNotContainsString('tq-seller-calendar', $html);
    }

    public function testADateFieldThatAllowsPastDatesGetsNoMinAndNoHint(): void
    {
        $field = $this->field(['key' => 'since', 'type' => 'date', 'label' => 'Since']);
        $calendar = ['timeZone' => 'Asia/Kolkata', 'today' => '2031-03-04'];
        $html = $this->renderer()->render($field, null, true, $calendar);

        self::assertStringNotContainsString('min=', $html);
        self::assertStringNotContainsString('tq-seller', $html);
    }

    /**
     * Magento's real Escaper, with the two collaborators it would otherwise fetch from the
     * ObjectManager (not booted in a unit test): the Laminas escaper and an inline
     * translator that is off, as on a storefront.
     */
    private function escaper(): Escaper
    {
        $escaper = new Escaper();
        $inline = $this->createMock(\Magento\Framework\Translate\InlineInterface::class);
        $inline->method('isAllowed')->willReturn(false);
        foreach (['escaper' => new \Magento\Framework\ZendEscaper(), 'translateInline' => $inline] as $name => $value) {
            $property = new \ReflectionProperty(Escaper::class, $name);
            $property->setAccessible(true);
            $property->setValue($escaper, $value);
        }

        return $escaper;
    }
}

<?php
/**
 * The wholesale block renders a "No dates in the past" field against the seller's today
 * NOW, not the day stored in the cached form definition.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Block\Account;

use Magento\Framework\Escaper;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Block\Account\WholesaleForm;
use TackQuote\Quotes\Model\Forms\FieldRenderer;
use TackQuote\Quotes\Model\Forms\FormDefinition;

/**
 * @covers \TackQuote\Quotes\Block\Account\WholesaleForm::renderField
 */
class WholesaleFormCalendarTest extends TestCase
{
    public function testACachedDefinitionMovesOnToTheSellersTodayAtRender(): void
    {
        $definition = new FormDefinition();
        // A definition cached long ago: the seller's today then was 2020-01-01 (UTC). The
        // store clock is far past it, so the render-time day is held to the day after.
        $form = $definition->normalise([
            'fields' => [['key' => 'start', 'type' => 'date', 'label' => 'Start', 'min' => 'today']],
            'calendar' => ['timeZone' => 'UTC', 'today' => '2020-01-01'],
        ]);

        $block = (new \ReflectionClass(WholesaleForm::class))->newInstanceWithoutConstructor();
        $set = function (string $name, $value) use ($block): void {
            $property = new \ReflectionProperty(WholesaleForm::class, $name);
            $property->setAccessible(true);
            $property->setValue($block, $value);
        };
        $set('form', $form);
        $set('definition', $definition);
        $set('renderer', new FieldRenderer($this->escaper(), $definition));

        $html = $block->renderField($form['fields'][0]);

        self::assertStringContainsString(' min="2020-01-02"', $html);
        self::assertStringContainsString('data-tq-seller-today="2020-01-02"', $html);
    }

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

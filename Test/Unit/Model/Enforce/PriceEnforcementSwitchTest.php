<?php
/**
 * The cart charges account prices only with the switch on, a working connection, and a
 * catalog entered excluding tax (TackQuote prices are net).
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Enforce;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Enforce\PriceEnforcementSwitch;

/**
 * @covers \TackQuote\Quotes\Model\Enforce\PriceEnforcementSwitch
 */
class PriceEnforcementSwitchTest extends TestCase
{
    /**
     * @return array<string, array{0: bool, 1: bool, 2: bool}>
     */
    public static function cases(): array
    {
        return [
            'on, net catalog' => [true, false, true],
            'on, catalog including tax' => [true, true, false],
            'off' => [false, false, false],
        ];
    }

    /**
     * @dataProvider cases
     */
    public function testIsOn(bool $enabled, bool $inclTax, bool $expected): void
    {
        $config = $this->createMock(Config::class);
        $config->method('isEnforcePricesEnabled')->with(4)->willReturn($enabled);
        $config->method('catalogPricesIncludeTax')->with(4)->willReturn($inclTax);

        self::assertSame($expected, (new PriceEnforcementSwitch($config))->isOn(4));
    }
}

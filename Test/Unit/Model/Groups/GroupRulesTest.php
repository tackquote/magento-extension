<?php
/**
 * The buyer-group rules: WooCommerce 1.11.0's parsing and decisions, ported.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Groups;

use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Groups\BuyerGroupAnswer;
use TackQuote\Quotes\Model\Groups\GroupRules;

/**
 * @covers \TackQuote\Quotes\Model\Groups\GroupRules
 * @covers \TackQuote\Quotes\Model\Groups\BuyerGroupAnswer
 */
class GroupRulesTest extends TestCase
{
    private static function grouped(string $code): BuyerGroupAnswer
    {
        return new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, $code);
    }

    private static function answerOf(string $status): BuyerGroupAnswer
    {
        return new BuyerGroupAnswer($status);
    }

    public function testParseMapUpperCasesCodesSkipsJunkAndLaterLinesWin(): void
    {
        $map = GroupRules::parseMap("# comment\ncheckmo: trade, Gold ,TRADE\n\nno colon here\n: GOLD\nbanktransfer:\n"
            . "checkmo: retail");

        self::assertSame(['checkmo' => ['RETAIL']], $map);
    }

    public function testParseMapKeepsAKeyOnlyWhenItNamesACode(): void
    {
        self::assertSame(['purchaseorder' => ['TRADE']], GroupRules::parseMap("purchaseorder: TRADE\ncheckmo: , ,"));
        self::assertSame([], GroupRules::parseMap(null));
        self::assertSame([], GroupRules::parseMap('   '));
    }

    public function testCategoryMapKeepsPositiveIdsOnly(): void
    {
        self::assertSame(
            [12 => ['RETAIL', '@GUESTS'], 40 => ['GOLD']],
            GroupRules::parseCategoryMap("12: retail, @guests\nbulk: GOLD\n0: GOLD\n-3: GOLD\n40: GOLD")
        );
    }

    public function testDiscountMapReadsTheThreeWooCommerceShapes(): void
    {
        $rules = GroupRules::parseDiscountMap(
            "tier3: free_only\nTIER2: free | flatrate\nGOLD: percent=15 | flatrate, tablerate_bestway\n"
            . "SILVER: percent=100\nBAD: percent=0\nWORSE: percent=101\nNOPE: half"
        );

        self::assertSame(['mode' => 'free_only', 'percent' => 100.0, 'methods' => []], $rules['TIER3']);
        self::assertSame(['mode' => 'free', 'percent' => 100.0, 'methods' => ['flatrate']], $rules['TIER2']);
        self::assertSame(
            ['mode' => 'percent', 'percent' => 15.0, 'methods' => ['flatrate', 'tablerate_bestway']],
            $rules['GOLD']
        );
        self::assertSame('free', $rules['SILVER']['mode'], '100% is free');
        self::assertArrayNotHasKey('BAD', $rules);
        self::assertArrayNotHasKey('WORSE', $rules);
        self::assertArrayNotHasKey('NOPE', $rules);
    }

    public function testMirrorMapNeedsACodeAndAPositiveGroupId(): void
    {
        self::assertSame(
            ['GOLD' => 4, 'TRADE' => 5],
            GroupRules::parseMirrorMap("gold: 4\nTRADE: 5\n@GUESTS: 2\nSILVER: 0\nBRONZE: four\nSILVER2: -1")
        );
    }

    public function testTheAdminRefusesExactlyTheLinesThatWouldNotParse(): void
    {
        self::assertSame([2, 4], GroupRules::invalidLines("checkmo: TRADE\nno colon\n# fine\ncheck mo: TRADE", 'methods'));
        self::assertSame([2], GroupRules::invalidLines("checkmo: TRADE\nbanktransfer: TR~ADE", 'methods'));
        self::assertSame([1], GroupRules::invalidLines("bulk: GOLD\n12: GOLD, @GUESTS", 'categories'));
        self::assertSame([2, 3], GroupRules::invalidLines("GOLD: percent=15\nX: half\n@GUESTS: free", 'discounts'));
        self::assertSame([2], GroupRules::invalidLines("GOLD: 4\nGOLD: General", 'mirror'));
        self::assertSame([], GroupRules::invalidLines("\n\n", 'methods'));
    }

    public function testAudienceKeepsAnOutageApartFromARealNoGroup(): void
    {
        self::assertSame('GOLD', GroupRules::audienceOf(self::grouped('gold')));
        self::assertSame('@GUESTS', GroupRules::audienceOf(self::answerOf(BuyerGroupAnswer::GUEST)));
        self::assertSame('@GUESTS', GroupRules::audienceOf(self::answerOf(BuyerGroupAnswer::NONE)));
        self::assertSame('@GUESTS', GroupRules::audienceOf(self::answerOf(BuyerGroupAnswer::ANONYMOUS)));
        self::assertNull(GroupRules::audienceOf(self::answerOf(BuyerGroupAnswer::UNAVAILABLE)));
    }

    public function testAGroupWithoutACodeIsOutsideEveryGroup(): void
    {
        $answer = new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, '   ');

        self::assertSame(BuyerGroupAnswer::NONE, $answer->getStatus());
        self::assertSame('@GUESTS', GroupRules::audienceOf($answer));
    }

    public function testTaxExemptRidesOnlyOnGroupedAndNone(): void
    {
        self::assertTrue((new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'A', true))->isTaxExempt());
        self::assertTrue((new BuyerGroupAnswer(BuyerGroupAnswer::NONE, '', true))->isTaxExempt());
        self::assertFalse((new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE, '', true))->isTaxExempt());
        self::assertFalse((new BuyerGroupAnswer(BuyerGroupAnswer::GUEST, '', true))->isTaxExempt());
        self::assertSame(BuyerGroupAnswer::UNAVAILABLE, (new BuyerGroupAnswer('maybe'))->getStatus());
    }

    public function testCategoriesAreHiddenFromTheGroupsTheirLineNames(): void
    {
        $map = [12 => ['RETAIL', '@GUESTS'], 40 => ['GOLD'], 7 => ['SILVER']];

        self::assertSame([40], GroupRules::hiddenCategoryRoots($map, self::grouped('Gold'), false));
        self::assertSame([12], GroupRules::hiddenCategoryRoots($map, self::grouped('RETAIL'), false));
        self::assertSame([], GroupRules::hiddenCategoryRoots($map, self::grouped('PLATINUM'), false));
        self::assertSame([12], GroupRules::hiddenCategoryRoots($map, self::answerOf(BuyerGroupAnswer::GUEST), false));
        self::assertSame([12], GroupRules::hiddenCategoryRoots($map, self::answerOf(BuyerGroupAnswer::NONE), true));
    }

    public function testAnUnknownGroupShowsEverythingUnlessTheMerchantChoseTheStrictReading(): void
    {
        $map = [12 => ['RETAIL'], 40 => ['GOLD']];
        $unknown = self::answerOf(BuyerGroupAnswer::UNAVAILABLE);

        self::assertSame([], GroupRules::hiddenCategoryRoots($map, $unknown, false));
        self::assertSame([12, 40], GroupRules::hiddenCategoryRoots($map, $unknown, true));
    }

    public function testTheAdminAndIntegrationsAreNeverFiltered(): void
    {
        $exempt = self::answerOf(BuyerGroupAnswer::EXEMPT);

        self::assertSame([], GroupRules::hiddenCategoryRoots([12 => ['@GUESTS']], $exempt, true));
        self::assertTrue(GroupRules::isPermitted(['checkmo' => ['TRADE']], 'checkmo', $exempt, true));
    }

    public function testAnUnconfiguredMethodIsUnrestricted(): void
    {
        $map = ['checkmo' => ['TRADE']];

        self::assertTrue(GroupRules::isPermitted($map, 'banktransfer', self::answerOf(BuyerGroupAnswer::NONE), true));
        self::assertTrue(GroupRules::isPermitted(['checkmo' => []], 'checkmo', self::answerOf(BuyerGroupAnswer::NONE), true));
    }

    public function testARestrictedMethodIsForTheGroupsItNames(): void
    {
        $map = ['checkmo' => ['TRADE', 'GOLD']];

        self::assertTrue(GroupRules::isPermitted($map, 'checkmo', self::grouped('trade'), false));
        self::assertFalse(GroupRules::isPermitted($map, 'checkmo', self::grouped('SILVER'), false));
        self::assertFalse(GroupRules::isPermitted($map, 'checkmo', self::answerOf(BuyerGroupAnswer::NONE), false));
        self::assertFalse(GroupRules::isPermitted($map, 'checkmo', self::answerOf(BuyerGroupAnswer::GUEST), false));
        self::assertFalse(GroupRules::isPermitted($map, 'checkmo', self::answerOf(BuyerGroupAnswer::ANONYMOUS), false));
        self::assertTrue(
            GroupRules::isPermitted(['checkmo' => ['@GUESTS']], 'checkmo', self::answerOf(BuyerGroupAnswer::GUEST), false)
        );
    }

    public function testAnOutageLeavesARestrictedMethodAvailableUnlessTheMerchantChoseStrict(): void
    {
        $map = ['checkmo' => ['TRADE']];
        $unknown = self::answerOf(BuyerGroupAnswer::UNAVAILABLE);

        self::assertTrue(GroupRules::isPermitted($map, 'checkmo', $unknown, false));
        self::assertFalse(GroupRules::isPermitted($map, 'checkmo', $unknown, true));
    }

    public function testAShippingRateIsGovernedByItsOwnLineFirstThenItsCarrier(): void
    {
        $map = ['tablerate' => ['TRADE'], 'tablerate_bestway' => ['GOLD']];

        self::assertSame('tablerate_bestway', GroupRules::shippingRuleKey($map, 'tablerate', 'bestway'));
        self::assertSame('tablerate', GroupRules::shippingRuleKey($map, 'tablerate', 'other'));
        // The WooCommerce lesson: evaluated ONCE, so the carrier rule is not short-circuited.
        $key = GroupRules::shippingRuleKey(['flatrate' => ['TRADE']], 'flatrate', 'flatrate');
        self::assertFalse(GroupRules::isPermitted(['flatrate' => ['TRADE']], $key, self::grouped('GOLD'), false));
    }

    public function testDiscountCoverageAndPrices(): void
    {
        self::assertTrue(GroupRules::discountCovers([], 'ups', 'GND'));
        self::assertTrue(GroupRules::discountCovers(['flatrate'], 'flatrate', 'flatrate'));
        self::assertTrue(GroupRules::discountCovers(['tablerate_bestway'], 'tablerate', 'bestway'));
        self::assertFalse(GroupRules::discountCovers(['flatrate'], 'tablerate', 'bestway'));

        $pct = ['mode' => 'percent', 'percent' => 15.0, 'methods' => []];
        self::assertSame(8.5, GroupRules::discountedPrice(10.0, $pct));
        self::assertSame(8.49, GroupRules::discountedPrice(9.99, $pct), 'rounded to 2 decimals');
        self::assertSame(0.0, GroupRules::discountedPrice(12.0, ['mode' => 'free', 'percent' => 100.0, 'methods' => []]));
        self::assertSame(0.0, GroupRules::discountedPrice(0.0, $pct), 'a free rate stays free');
    }
}

<?php
/**
 * The Overview's rows: what each tab counts, where each row links, and that links are left
 * out for an admin without the configuration permission.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\ViewModel\Adminhtml;

use Magento\Backend\Model\UrlInterface;
use Magento\Framework\App\Config\ScopeConfigInterface;
use Magento\Framework\AuthorizationInterface;
use Magento\Framework\Module\ModuleListInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Model\Config\Source\QuoteOnlyScope;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;
use TackQuote\Quotes\ViewModel\Adminhtml\Overview;
use TackQuote\Quotes\ViewModel\Styling;

/**
 * @covers \TackQuote\Quotes\ViewModel\Adminhtml\Overview
 */
class OverviewTest extends TestCase
{
    /**
     * @param array<string, bool> $flags
     * @param bool $allowed
     * @param array<int, array{0: string, 1: array}> $urls Collects getUrl() calls.
     * @return Overview
     */
    private function overview(array $flags = [], bool $allowed = true, array &$urls = []): Overview
    {
        $scope = $this->createMock(ScopeConfigInterface::class);
        $scope->method('isSetFlag')->willReturnCallback(static function ($path) use ($flags) {
            return $flags[$path] ?? false;
        });
        $auth = $this->createMock(AuthorizationInterface::class);
        $auth->method('isAllowed')->with('TackQuote_Quotes::config')->willReturn($allowed);
        $url = $this->createMock(UrlInterface::class);
        $url->method('getUrl')->willReturnCallback(static function ($route, $params = []) use (&$urls) {
            $urls[] = [$route, $params];

            return 'https://admin.test/' . $route . (isset($params['_fragment']) ? '#' . $params['_fragment'] : '');
        });
        $modules = $this->createMock(ModuleListInterface::class);
        $modules->method('getOne')->willReturn(['setup_version' => '9.9.9']);

        return new Overview(
            $this->createMock(Config::class),
            $this->createMock(QuoteOnlyScope::class),
            $this->createMock(GroupRulesConfig::class),
            $this->createMock(Styling::class),
            $modules,
            $scope,
            $auth,
            $url
        );
    }

    public function testThereIsOneTabPerAreaInThePluginsOrder(): void
    {
        self::assertSame(
            ['Connection', 'Storefront', 'B2B pricing', 'Buyer groups', 'Forms', 'Orders', 'Help'],
            array_values($this->overview()->getTabs())
        );
    }

    public function testStorefrontRowsReadTheirSwitchesAndLinkToTheirGroups(): void
    {
        $overview = $this->overview([
            'tackquote_quotes/storefront/show_button' => true,
            'tackquote_quotes/quote_request/enable_attachments' => true,
        ]);
        $rows = $overview->getStorefrontRows();

        self::assertCount(7, $rows);
        self::assertSame(2, $overview->countOn($rows));
        self::assertSame(7, $overview->countSwitches($rows));
        self::assertSame(
            [
                'storefront',
                'storefront',
                'storefront',
                'quote_request',
                'quote_request',
                'quote_request',
                'quote_request'
            ],
            array_column($rows, 'group')
        );
    }

    public function testValueRowsAreNotCountedAsSwitches(): void
    {
        $overview = $this->overview();
        $rows = [
            ['label' => 'a', 'on' => true],
            ['label' => 'b', 'on' => null, 'value' => 'x'],
            ['label' => 'c', 'on' => false],
        ];

        self::assertSame(1, $overview->countOn($rows));
        self::assertSame(2, $overview->countSwitches($rows));
    }

    public function testFormsAndOrdersRowsLinkToTheirGroups(): void
    {
        $overview = $this->overview(['payment/tackquote_net_terms/active' => true]);

        self::assertSame(['forms', 'forms', 'net_terms'], array_column($overview->getFormsRows(), 'group'));
        self::assertSame([false, false, true], array_column($overview->getFormsRows(), 'on'));
        self::assertSame(['quote_checkout'], array_column($overview->getOrdersRows(), 'group'));
    }

    /**
     * The rows that depend on the server's capability list carry `server` (the template then
     * shows the server-check note beside them; devstore Q/A finding #2). Mutation (applied, red,
     * restored): drop the flag from the Attachments row -> this test.
     */
    public function testTheRowsThatNeedTheServerSayWhichTheyAre(): void
    {
        $overview = $this->overview([]);
        $flags = static function (array $rows): array {
            $out = [];
            foreach ($rows as $row) {
                $out[$row['label']] = $row['server'] ?? false;
            }

            return $out;
        };

        $storefront = $flags($overview->getStorefrontRows());
        self::assertSame(['Target price per product', 'Attachments'], array_keys(array_filter($storefront)));
        self::assertSame(
            ['"Wholesale account" tab', '"Net terms" tab', 'Pay on net terms at checkout'],
            array_keys(array_filter($flags($overview->getFormsRows())))
        );

        $template = (string) file_get_contents(dirname(__DIR__, 4) . '/view/adminhtml/templates/dashboard.phtml');
        self::assertStringContainsString(
            "!empty(\$row['server']) && \$row['on'] === true && \$serverNote !== ''",
            $template
        );
    }

    public function testAGroupLinkOpensThatGroupExpanded(): void
    {
        $urls = [];
        $link = $this->overview([], true, $urls)->getGroupUrl('styling');

        self::assertSame('https://admin.test/adminhtml/system_config/edit#tackquote_quotes_styling-link', $link);
        self::assertSame(
            [[
                'adminhtml/system_config/edit',
                ['section' => 'tackquote_quotes', '_fragment' => 'tackquote_quotes_styling-link']
            ]],
            $urls
        );
    }

    public function testLinksFollowTheConfigurationPermission(): void
    {
        self::assertTrue($this->overview([], true)->canConfigure());
        self::assertFalse($this->overview([], false)->canConfigure());
    }

    public function testOnlyAHexColourIsASwatch(): void
    {
        $overview = $this->overview();

        self::assertTrue($overview->isSwatch('#1a2b3c'));
        self::assertTrue($overview->isSwatch('#fff'));
        self::assertFalse($overview->isSwatch('red'));
        self::assertFalse($overview->isSwatch('#fff;background:url(x)'));
        self::assertFalse($overview->isSwatch(''));
    }

    public function testTheHelpTabShowsTheModuleVersion(): void
    {
        self::assertSame('9.9.9', $this->overview()->getModuleVersion());
    }
}

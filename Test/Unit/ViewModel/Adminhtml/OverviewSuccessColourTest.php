<?php
/**
 * The Overview's positive status lines are green, not the admin's yellow.
 *
 * Magento's admin paints EVERY `.message` with one background, @alert__background-color
 * (#fffbbb, theme-adminhtml-backend web/css/source/components/_messages.less, read in 2.4.8-p5),
 * and `.message-success` changes only the icon: so "Ready" and "Connected to TackQuote" showed a
 * green check on yellow (devstore Q/A finding #10). The module's admin stylesheet gives
 * `message-success` a light green inside the Overview only.
 *
 * Mutations (applied, red, restored):
 *   G1  drop the .message-success rule -> testTheOverviewPaintsSuccessMessagesGreen
 *   G2  "Ready" rendered as message-notice -> testReadyIsASuccessMessage
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\ViewModel\Adminhtml;

use PHPUnit\Framework\TestCase;

/**
 * @coversNothing
 */
class OverviewSuccessColourTest extends TestCase
{
    private const ROOT = __DIR__ . '/../../../..';

    public function testTheOverviewPaintsSuccessMessagesGreen(): void
    {
        $less = (string) file_get_contents(self::ROOT . '/view/adminhtml/web/css/source/_module.less');

        self::assertMatchesRegularExpression('/@tq-overview-success__background:\s*#[0-9a-f]{3,6};/', $less);
        $overview = substr($less, (int) strpos($less, "\n.tq-overview {"));
        $overview = substr($overview, 0, (int) strpos($overview, "\n}\n"));
        self::assertMatchesRegularExpression(
            '/\.message-success\s*\{\s*background:\s*@tq-overview-success__background;\s*\}/',
            $overview,
            'scoped to the Overview (.tq-overview), not every admin page'
        );
    }

    public function testReadyIsASuccessMessage(): void
    {
        $template = (string) file_get_contents(self::ROOT . '/view/adminhtml/templates/dashboard.phtml');

        self::assertStringContainsString("\$allOk ? 'message-success success' : 'message-warning warning'", $template);
        // The test-connection answer lands in #tq-test-result, inside .tq-overview, as message-success.
        self::assertStringContainsString('id="tq-test-result"', $template);
        self::assertStringContainsString(
            "'message message-' + type",
            (string) file_get_contents(self::ROOT . '/view/adminhtml/web/js/test-connection.js')
        );
    }
}

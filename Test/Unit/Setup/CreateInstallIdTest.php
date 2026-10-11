<?php
/**
 * The install id: a version 4 UUID, created once and never replaced.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Setup;

use Magento\Framework\DB\Adapter\AdapterInterface;
use Magento\Framework\DB\Select;
use Magento\Framework\Setup\ModuleDataSetupInterface;
use PHPUnit\Framework\TestCase;
use TackQuote\Quotes\Model\Config;
use TackQuote\Quotes\Setup\Patch\Data\CreateInstallId;

/**
 * @covers \TackQuote\Quotes\Setup\Patch\Data\CreateInstallId
 */
class CreateInstallIdTest extends TestCase
{
    public function testUuid4IsAVersion4VariantOneUuid(): void
    {
        $id = CreateInstallId::uuid4(str_repeat("\xff", 16));
        self::assertMatchesRegularExpression('/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/', $id);
        self::assertTrue(CreateInstallId::isUuid($id));
        self::assertFalse(CreateInstallId::isUuid('not-a-uuid'));
    }

    private function dataSetup(string|false $existing, array &$written): ModuleDataSetupInterface
    {
        $select = $this->createMock(Select::class);
        $select->method('from')->willReturnSelf();
        $select->method('where')->willReturnSelf();
        $connection = $this->createMock(AdapterInterface::class);
        $connection->method('select')->willReturn($select);
        $connection->method('fetchOne')->willReturn($existing);
        $connection->method('insertOnDuplicate')->willReturnCallback(
            function ($table, array $row) use (&$written) {
                $written[] = $row;

                return 1;
            }
        );
        $setup = $this->createMock(ModuleDataSetupInterface::class);
        $setup->method('getConnection')->willReturn($connection);
        $setup->method('getTable')->willReturnArgument(0);

        return $setup;
    }

    public function testAFreshInstallGetsAnId(): void
    {
        $written = [];
        (new CreateInstallId($this->dataSetup(false, $written)))->apply();

        self::assertCount(1, $written);
        self::assertSame(Config::XML_PATH_INSTALL_ID, $written[0]['path']);
        self::assertSame('default', $written[0]['scope']);
        self::assertTrue(CreateInstallId::isUuid($written[0]['value']));
    }

    public function testAnExistingIdIsNeverReplaced(): void
    {
        $written = [];
        (new CreateInstallId($this->dataSetup('0f0e0d0c-0b0a-4908-8706-050403020100', $written)))->apply();

        self::assertSame([], $written);
    }
}

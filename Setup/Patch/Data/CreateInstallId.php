<?php
/**
 * Gives this Magento install its own id, once.
 *
 * WHY. TackQuote reads a signed-in Magento customer as `magento_customer` +
 * `<install id>:<customer id>` (tack storefront-core/storefront-buyer.ts). The install id is
 * what keeps customer 7 of this store apart from customer 7 of another Magento store on the
 * same TackQuote workspace, and it must never change: a seller links that pair to a buyer
 * when approving a wholesale or net-terms application, and a new id would orphan every link.
 *
 * WHY A DATA PATCH. A patch runs exactly once per install, at `bin/magento setup:upgrade`,
 * and Magento records it in `patch_list`. Generating the id lazily on a storefront request
 * instead would race: two first requests on two web nodes could each write a different id,
 * and an application filed under the losing one would never link.
 *
 * Idempotent anyway: an id already stored (a restored database, a re-run) is kept.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Setup\Patch\Data;

use Magento\Framework\Setup\ModuleDataSetupInterface;
use Magento\Framework\Setup\Patch\DataPatchInterface;
use TackQuote\Quotes\Model\Config;

class CreateInstallId implements DataPatchInterface
{
    /**
     * @var ModuleDataSetupInterface
     */
    private $moduleDataSetup;

    /**
     * @param ModuleDataSetupInterface $moduleDataSetup
     */
    public function __construct(ModuleDataSetupInterface $moduleDataSetup)
    {
        $this->moduleDataSetup = $moduleDataSetup;
    }

    /**
     * @inheritdoc
     */
    public function apply()
    {
        $connection = $this->moduleDataSetup->getConnection();
        $table = $this->moduleDataSetup->getTable('core_config_data');
        $existing = $connection->fetchOne(
            $connection->select()
                ->from($table, ['value'])
                ->where('path = ?', Config::XML_PATH_INSTALL_ID)
                ->where('scope = ?', 'default')
                ->where('scope_id = ?', 0)
        );
        if (is_string($existing) && self::isUuid($existing)) {
            return $this;
        }

        $connection->insertOnDuplicate(
            $table,
            [
                'scope' => 'default',
                'scope_id' => 0,
                'path' => Config::XML_PATH_INSTALL_ID,
                'value' => self::uuid4(random_bytes(16)),
            ],
            ['value']
        );

        return $this;
    }

    /**
     * A version 4 UUID from 16 random bytes (RFC 9562 section 5.4), lower case.
     *
     * @param string $bytes Exactly 16 bytes.
     * @return string
     */
    public static function uuid4(string $bytes): string
    {
        if (strlen($bytes) !== 16) {
            throw new \InvalidArgumentException('A UUID needs exactly 16 bytes.');
        }
        $bytes[6] = chr((ord($bytes[6]) & 0x0f) | 0x40);
        $bytes[8] = chr((ord($bytes[8]) & 0x3f) | 0x80);
        $hex = bin2hex($bytes);

        return substr($hex, 0, 8) . '-' . substr($hex, 8, 4) . '-' . substr($hex, 12, 4) . '-'
            . substr($hex, 16, 4) . '-' . substr($hex, 20, 12);
    }

    /**
     * Whether a stored value is a UUID this module can use.
     *
     * @param string $value
     * @return bool
     */
    public static function isUuid(string $value): bool
    {
        return preg_match(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/',
            strtolower(trim($value))
        ) === 1;
    }

    /**
     * @inheritdoc
     */
    public static function getDependencies()
    {
        return [];
    }

    /**
     * @inheritdoc
     */
    public function getAliases()
    {
        return [];
    }
}

<?php
/**
 * Refuses to save a buyer-group rule field with a line that would not parse.
 *
 * At runtime an unreadable line is skipped (GroupRules), because a typo must not take the
 * checkout down. That makes a typo silent, and WooCommerce's settings note why silence is the
 * dangerous case: "a typo silently hid a payment method from every customer"
 * (class-tack-settings.php, OPTION_GROUP_CODES). So the admin names the bad lines instead of
 * saving them. The format is chosen from the field's path.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups\Config;

use Magento\Framework\App\Config\Value;
use Magento\Framework\Exception\LocalizedException;
use TackQuote\Quotes\Model\Groups\GroupRules;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;

class RuleMapBackend extends Value
{
    /** Field id (last path segment) => GroupRules format. */
    private const FORMATS = [
        GroupRulesConfig::CATALOG_MAP => GroupRules::FORMAT_CATEGORIES,
        GroupRulesConfig::PAYMENT_MAP => GroupRules::FORMAT_METHODS,
        GroupRulesConfig::SHIPPING_MAP => GroupRules::FORMAT_METHODS,
        GroupRulesConfig::DISCOUNT_MAP => GroupRules::FORMAT_DISCOUNTS,
        GroupRulesConfig::MIRROR_MAP => GroupRules::FORMAT_MIRROR,
    ];

    /**
     * @return $this
     * @throws LocalizedException
     */
    public function beforeSave()
    {
        $value = (string) $this->getValue();
        $parts = explode('/', (string) $this->getPath());
        $format = self::FORMATS[(string) end($parts)] ?? null;

        if ($format !== null && trim($value) !== '') {
            $bad = GroupRules::invalidLines($value, $format);
            if ($bad !== []) {
                throw new LocalizedException(__(
                    'Buyer group rules: line %1 of "%2" could not be read. Check the format shown under the field.',
                    implode(', ', $bad),
                    (string) ($this->getFieldConfig()['label'] ?? end($parts))
                ));
            }
        }

        return parent::beforeSave();
    }
}

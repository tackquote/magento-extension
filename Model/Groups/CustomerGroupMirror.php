<?php
/**
 * Optional: put a customer in the Magento customer group the merchant maps to their TackQuote
 * buyer group, on sign-in and again at most every 5 minutes.
 *
 * WHAT IT IS. WooCommerce 1.10.0's role mirror (class-tack-role-mirror.php) gives a customer an
 * extra WordPress role `tackquote_<code>` so themes and other plugins can key on it. Magento's
 * equivalent of "something other modules key on" is the customer group (catalog price rules,
 * tier prices, tax class, cart rules), and a customer has exactly ONE. So this is a mapping the
 * merchant writes, `CODE: <customer group id>`, and the mirror MOVES the customer, which is why
 * its safeguards are stricter than WooCommerce's:
 *
 *   - only mapped codes act; an unmapped group, no group, an outage or an unknown answer
 *     changes nothing it did not do itself;
 *   - it moves a customer only OUT OF the website's default group (what a new account gets)
 *     or out of the group it put them in itself. A customer the merchant placed in any other
 *     group by hand is never moved (WooCommerce: "a role the plugin did not add is NEVER
 *     removed");
 *   - what it did is recorded per customer (`flag` table, Magento\Framework\FlagManager:
 *     {from, to}), and only that is ever undone: when TackQuote stops placing the customer in a
 *     mapped group, the customer goes back to the group they came from, and only if they are
 *     still in the group the mirror chose. A group changed by hand in between ends the record
 *     and is left alone;
 *   - it honours "Only for these customer groups": a customer outside the selection is not
 *     looked up (unless they are in the group the mirror put them in);
 *   - every change is logged with the customer id and the two group ids, never an email.
 *
 * It never drives the TackQuote rules: those read the buyer group from TackQuote at request
 * time, as before. NOTE the trust caveat it inherits: TackQuote finds the buyer by email, which
 * Magento confirms only with account confirmation on, and a customer group can carry prices;
 * see the README.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\Groups;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\FlagManager;
use Psr\Log\LoggerInterface;

class CustomerGroupMirror
{
    public const RESULT_OFF = 'off';
    public const RESULT_THROTTLED = 'throttled';
    public const RESULT_SKIPPED = 'skipped';
    public const RESULT_OUTAGE = 'outage';
    public const RESULT_KEPT = 'kept';
    public const RESULT_ASSIGNED = 'assigned';
    public const RESULT_RESTORED = 'restored';
    public const RESULT_FORGOTTEN = 'forgotten';
    public const RESULT_FAILED = 'failed';

    /** Seconds between two checks of the same customer (WooCommerce INTERVAL). */
    public const INTERVAL = 300;

    private const FLAG_PREFIX = 'tackquote_group_mirror_';
    private const CHECK_PREFIX = 'tackquote_gm_checked_';

    /**
     * @var GroupRulesConfig
     */
    private $config;

    /**
     * @var BuyerGroupResolver
     */
    private $resolver;

    /**
     * @var CustomerRepositoryInterface
     */
    private $customers;

    /**
     * @var GroupManagementInterface
     */
    private $groupManagement;

    /**
     * @var GroupRepositoryInterface
     */
    private $groups;

    /**
     * @var FlagManager
     */
    private $flags;

    /**
     * @var CacheInterface
     */
    private $cache;

    /**
     * @var LoggerInterface
     */
    private $logger;

    /**
     * @param GroupRulesConfig $config
     * @param BuyerGroupResolver $resolver
     * @param CustomerRepositoryInterface $customers
     * @param GroupManagementInterface $groupManagement
     * @param GroupRepositoryInterface $groups
     * @param FlagManager $flags
     * @param CacheInterface $cache
     * @param LoggerInterface $logger
     */
    public function __construct(
        GroupRulesConfig $config,
        BuyerGroupResolver $resolver,
        CustomerRepositoryInterface $customers,
        GroupManagementInterface $groupManagement,
        GroupRepositoryInterface $groups,
        FlagManager $flags,
        CacheInterface $cache,
        LoggerInterface $logger
    ) {
        $this->config = $config;
        $this->resolver = $resolver;
        $this->customers = $customers;
        $this->groupManagement = $groupManagement;
        $this->groups = $groups;
        $this->flags = $flags;
        $this->cache = $cache;
        $this->logger = $logger;
    }

    /**
     * Bring one customer's group in line with their TackQuote group.
     *
     * @param int $customerId
     * @param int $storeId
     * @param bool $force True on sign-in: skip the 5-minute throttle.
     * @return array{result: string, groupId: int|null} groupId is the customer's group after
     *         the call when it changed, otherwise null.
     */
    public function sync(int $customerId, int $storeId, bool $force = false): array
    {
        if ($customerId < 1 || !$this->config->isMirrorActive($storeId)) {
            return $this->out(self::RESULT_OFF);
        }
        $checkId = self::CHECK_PREFIX . $storeId . '_' . $customerId;
        if (!$force && $this->cache->load($checkId)) {
            return $this->out(self::RESULT_THROTTLED);
        }
        $this->cache->save('1', $checkId, [BuyerGroupResolver::CACHE_TAG], self::INTERVAL);

        try {
            $customer = $this->customers->getById($customerId);
        } catch (\Exception $e) {
            return $this->out(self::RESULT_SKIPPED);
        }

        $current = (int) $customer->getGroupId();
        $record = $this->record($customerId);
        $ours = $record !== null && $current === $record['to'];

        $selected = $this->config->getCustomerGroups($storeId);
        if ($selected !== [] && !$ours && !in_array($current, $selected, true)) {
            return $this->out(self::RESULT_SKIPPED);
        }

        $answer = $this->resolver->resolve($storeId, $customerId, (string) $customer->getEmail());
        $status = $answer->getStatus();
        if ($status !== BuyerGroupAnswer::GROUPED && $status !== BuyerGroupAnswer::NONE) {
            // Could not ask, or no identity reached TackQuote: change nothing.
            return $this->out(self::RESULT_OUTAGE);
        }

        $map = $this->config->getMirrorMap($storeId);
        $desired = $status === BuyerGroupAnswer::GROUPED ? ($map[$answer->getCode()] ?? null) : null;

        if ($desired !== null) {
            if ($current === $desired) {
                return $this->out(self::RESULT_KEPT);
            }
            if (!$ours && $current !== $this->defaultGroupId($storeId)) {
                // Placed in this group by someone else: never moved.
                return $this->out(self::RESULT_SKIPPED);
            }
            if (!$this->groupExists($desired)) {
                $this->logger->warning(sprintf(
                    'TackQuote customer-group mirror: group %d in the mapping does not exist; customer %d not moved.',
                    $desired,
                    $customerId
                ));

                return $this->out(self::RESULT_SKIPPED);
            }
            $from = $ours ? $record['from'] : $current;
            if (!$this->move($customer, $desired)) {
                return $this->out(self::RESULT_FAILED);
            }
            $this->flags->saveFlag($this->flagCode($customerId), ['from' => $from, 'to' => $desired]);
            $this->logger->info(sprintf(
                'TackQuote customer-group mirror: customer %d moved from group %d to group %d.',
                $customerId,
                $current,
                $desired
            ));

            return $this->out(self::RESULT_ASSIGNED, $desired);
        }

        if ($record === null) {
            return $this->out(self::RESULT_KEPT);
        }
        if (!$ours) {
            // The group was changed by hand since the mirror moved this customer: leave it.
            $this->flags->deleteFlag($this->flagCode($customerId));

            return $this->out(self::RESULT_FORGOTTEN);
        }

        $back = $this->groupExists($record['from']) ? $record['from'] : $this->defaultGroupId($storeId);
        if (!$this->move($customer, $back)) {
            return $this->out(self::RESULT_FAILED);
        }
        $this->flags->deleteFlag($this->flagCode($customerId));
        $this->logger->info(sprintf(
            'TackQuote customer-group mirror: customer %d returned from group %d to group %d.',
            $customerId,
            $current,
            $back
        ));

        return $this->out(self::RESULT_RESTORED, $back);
    }

    /**
     * What the mirror did to this customer, or null.
     *
     * @param int $customerId
     * @return array{from: int, to: int}|null
     */
    private function record(int $customerId): ?array
    {
        $data = $this->flags->getFlagData($this->flagCode($customerId));
        if (!is_array($data) || !isset($data['from'], $data['to'])
            || !is_numeric($data['from']) || !is_numeric($data['to'])
        ) {
            return null;
        }

        return ['from' => (int) $data['from'], 'to' => (int) $data['to']];
    }

    /**
     * Move a customer into a customer group.
     *
     * @param \Magento\Customer\Api\Data\CustomerInterface $customer
     * @param int $groupId
     * @return bool
     */
    private function move($customer, int $groupId): bool
    {
        try {
            $customer->setGroupId($groupId);
            $this->customers->save($customer);
        } catch (\Exception $e) {
            $this->logger->warning(sprintf(
                'TackQuote customer-group mirror could not save customer %d.',
                (int) $customer->getId()
            ));

            return false;
        }

        return true;
    }

    /**
     * Whether a customer group with this id exists.
     *
     * @param int $groupId
     * @return bool
     */
    private function groupExists(int $groupId): bool
    {
        if ($groupId < 1) {
            return false;
        }
        try {
            $this->groups->getById($groupId);
        } catch (\Exception $e) {
            return false;
        }

        return true;
    }

    /**
     * Default customer group of the store.
     *
     * @param int $storeId
     * @return int
     */
    private function defaultGroupId(int $storeId): int
    {
        try {
            return (int) $this->groupManagement->getDefaultGroup($storeId)->getId();
        } catch (\Exception $e) {
            return -1;
        }
    }

    /**
     * Flag code that records the customer's mirrored group.
     *
     * @param int $customerId
     * @return string
     */
    private function flagCode(int $customerId): string
    {
        return self::FLAG_PREFIX . $customerId;
    }

    /**
     * Build a mirror result.
     *
     * @param string $result
     * @param int|null $groupId
     * @return array{result: string, groupId: int|null}
     */
    private function out(string $result, ?int $groupId = null): array
    {
        return ['result' => $result, 'groupId' => $groupId];
    }
}

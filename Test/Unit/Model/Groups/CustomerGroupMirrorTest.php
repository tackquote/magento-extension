<?php
/**
 * The customer-group mirror's safeguards: only mapped codes act, only the default group (or the
 * mirror's own group) is moved out of, only what the mirror did is undone, an outage changes
 * nothing, and the selection of customer groups is honoured.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Test\Unit\Model\Groups;

use Magento\Customer\Api\CustomerRepositoryInterface;
use Magento\Customer\Api\Data\CustomerInterface;
use Magento\Customer\Api\Data\GroupInterface;
use Magento\Customer\Api\GroupManagementInterface;
use Magento\Customer\Api\GroupRepositoryInterface;
use Magento\Framework\App\CacheInterface;
use Magento\Framework\Exception\NoSuchEntityException;
use Magento\Framework\FlagManager;
use PHPUnit\Framework\MockObject\MockObject;
use PHPUnit\Framework\TestCase;
use Psr\Log\LoggerInterface;
use TackQuote\Quotes\Model\Groups\BuyerGroupAnswer;
use TackQuote\Quotes\Model\Groups\BuyerGroupResolver;
use TackQuote\Quotes\Model\Groups\CustomerGroupMirror;
use TackQuote\Quotes\Model\Groups\GroupRulesConfig;

/**
 * @covers \TackQuote\Quotes\Model\Groups\CustomerGroupMirror
 */
class CustomerGroupMirrorTest extends TestCase
{
    private const DEFAULT_GROUP = 1;
    private const GOLD_GROUP = 4;
    private const HAND_GROUP = 9;

    /** @var GroupRulesConfig&MockObject */
    private $config;

    /** @var BuyerGroupResolver&MockObject */
    private $resolver;

    /** @var CustomerRepositoryInterface&MockObject */
    private $customers;

    /** @var CustomerInterface&MockObject */
    private $customer;

    /** @var int */
    private $groupId = self::DEFAULT_GROUP;

    /** @var int[] Groups the customer was saved with. */
    private $savedGroups = [];

    /** @var array<string, mixed> */
    private $flags = [];

    /** @var array<string, string> */
    private $cacheStore = [];

    /** @var CustomerGroupMirror */
    private $mirror;

    protected function setUp(): void
    {
        $this->config = $this->createMock(GroupRulesConfig::class);
        $this->config->method('isMirrorActive')->willReturn(true);
        $this->config->method('getMirrorMap')->willReturn(['GOLD' => self::GOLD_GROUP]);

        $this->resolver = $this->createMock(BuyerGroupResolver::class);

        $this->customer = $this->createMock(CustomerInterface::class);
        $this->customer->method('getId')->willReturn(42);
        $this->customer->method('getEmail')->willReturn('buyer@example.test');
        $this->customer->method('getGroupId')->willReturnCallback(function () {
            return $this->groupId;
        });
        $this->customer->method('setGroupId')->willReturnCallback(function ($id) {
            $this->groupId = (int) $id;

            return $this->customer;
        });
        $this->customers = $this->createMock(CustomerRepositoryInterface::class);
        $this->customers->method('getById')->willReturn($this->customer);
        $this->customers->method('save')->willReturnCallback(function ($c) {
            $this->savedGroups[] = (int) $c->getGroupId();

            return $c;
        });

        $default = $this->createMock(GroupInterface::class);
        $default->method('getId')->willReturn(self::DEFAULT_GROUP);
        $management = $this->createMock(GroupManagementInterface::class);
        $management->method('getDefaultGroup')->willReturn($default);

        $groups = $this->createMock(GroupRepositoryInterface::class);
        $groups->method('getById')->willReturnCallback(function ($id) {
            if ((int) $id === 404) {
                throw new NoSuchEntityException(__('No such group'));
            }

            return $this->createMock(GroupInterface::class);
        });

        $flags = $this->createMock(FlagManager::class);
        $flags->method('getFlagData')->willReturnCallback(function ($code) {
            return $this->flags[$code] ?? null;
        });
        $flags->method('saveFlag')->willReturnCallback(function ($code, $value) {
            $this->flags[$code] = $value;

            return true;
        });
        $flags->method('deleteFlag')->willReturnCallback(function ($code) {
            unset($this->flags[$code]);

            return true;
        });

        $cache = $this->createMock(CacheInterface::class);
        $cache->method('load')->willReturnCallback(function ($id) {
            return $this->cacheStore[$id] ?? false;
        });
        $cache->method('save')->willReturnCallback(function ($data, $id) {
            $this->cacheStore[$id] = $data;

            return true;
        });

        $this->mirror = new CustomerGroupMirror(
            $this->config,
            $this->resolver,
            $this->customers,
            $management,
            $groups,
            $flags,
            $cache,
            $this->createMock(LoggerInterface::class)
        );
    }

    private function tackSays(BuyerGroupAnswer $answer): void
    {
        $this->resolver->method('resolve')->willReturn($answer);
    }

    private function sync(bool $force = true): array
    {
        return $this->mirror->sync(42, 1, $force);
    }

    public function testAMappedGroupMovesACustomerOutOfTheDefaultGroupAndIsRecorded(): void
    {
        $this->tackSays(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'gold'));

        self::assertSame(['result' => 'assigned', 'groupId' => self::GOLD_GROUP], $this->sync());
        self::assertSame([self::GOLD_GROUP], $this->savedGroups);
        self::assertSame(['from' => self::DEFAULT_GROUP, 'to' => self::GOLD_GROUP], $this->flags['tackquote_group_mirror_42']);
    }

    public function testACustomerPlacedInAnotherGroupByHandIsNeverMoved(): void
    {
        $this->groupId = self::HAND_GROUP;
        $this->tackSays(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD'));

        self::assertSame('skipped', $this->sync()['result']);
        self::assertSame([], $this->savedGroups);
    }

    public function testAnUnmappedCodeChangesNothing(): void
    {
        $this->tackSays(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'SILVER'));

        self::assertSame('kept', $this->sync()['result']);
        self::assertSame([], $this->savedGroups);
    }

    public function testAnOutageChangesNothing(): void
    {
        $this->groupId = self::GOLD_GROUP;
        $this->flags['tackquote_group_mirror_42'] = ['from' => self::DEFAULT_GROUP, 'to' => self::GOLD_GROUP];
        $this->tackSays(new BuyerGroupAnswer(BuyerGroupAnswer::UNAVAILABLE));

        self::assertSame('outage', $this->sync()['result']);
        self::assertSame([], $this->savedGroups);
    }

    public function testLeavingTheGroupReturnsTheCustomerWhereTheyCameFrom(): void
    {
        $this->groupId = self::GOLD_GROUP;
        $this->flags['tackquote_group_mirror_42'] = ['from' => 3, 'to' => self::GOLD_GROUP];
        $this->tackSays(new BuyerGroupAnswer(BuyerGroupAnswer::NONE));

        self::assertSame(['result' => 'restored', 'groupId' => 3], $this->sync());
        self::assertSame([3], $this->savedGroups);
        self::assertArrayNotHasKey('tackquote_group_mirror_42', $this->flags);
    }

    public function testAGroupChangedByHandSinceIsLeftAloneAndForgotten(): void
    {
        $this->groupId = self::HAND_GROUP;
        $this->flags['tackquote_group_mirror_42'] = ['from' => self::DEFAULT_GROUP, 'to' => self::GOLD_GROUP];
        $this->tackSays(new BuyerGroupAnswer(BuyerGroupAnswer::NONE));

        self::assertSame('forgotten', $this->sync()['result']);
        self::assertSame([], $this->savedGroups);
        self::assertArrayNotHasKey('tackquote_group_mirror_42', $this->flags);
    }

    public function testAMissingTargetGroupMovesNobody(): void
    {
        $this->config = $this->createMock(GroupRulesConfig::class);
        $this->setUpWithMap(['GOLD' => 404]);
        $this->tackSays(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'GOLD'));

        self::assertSame('skipped', $this->sync()['result']);
        self::assertSame([], $this->savedGroups);
    }

    public function testACustomerOutsideTheSelectedGroupsIsNotLookedUp(): void
    {
        $this->config = $this->createMock(GroupRulesConfig::class);
        $this->setUpWithMap(['GOLD' => self::GOLD_GROUP], [2]);
        $this->resolver->expects(self::never())->method('resolve');

        self::assertSame('skipped', $this->sync()['result']);
    }

    public function testTheCheckIsThrottledButSignInForcesIt(): void
    {
        $this->resolver->expects(self::exactly(2))->method('resolve')
            ->willReturn(new BuyerGroupAnswer(BuyerGroupAnswer::GROUPED, 'SILVER'));

        self::assertSame('kept', $this->sync(false)['result']);
        self::assertSame('throttled', $this->sync(false)['result']);
        self::assertSame('kept', $this->sync(true)['result']);
    }

    public function testOffDoesNothing(): void
    {
        $this->config = $this->createMock(GroupRulesConfig::class);
        $this->config->method('isMirrorActive')->willReturn(false);
        $this->rebuild();
        $this->resolver->expects(self::never())->method('resolve');

        self::assertSame('off', $this->sync()['result']);
    }

    /**
     * @param array<string, int> $map
     * @param int[] $selected
     */
    private function setUpWithMap(array $map, array $selected = []): void
    {
        $this->config->method('isMirrorActive')->willReturn(true);
        $this->config->method('getMirrorMap')->willReturn($map);
        $this->config->method('getCustomerGroups')->willReturn($selected);
        $this->rebuild();
    }

    private function rebuild(): void
    {
        $reflection = new \ReflectionProperty(CustomerGroupMirror::class, 'config');
        $reflection->setAccessible(true);
        $reflection->setValue($this->mirror, $this->config);
    }
}

<?php
/**
 * Whether Magento cron has run this module's jobs yet, for the admin Overview.
 *
 * The storefront quote form reads two things from cache only, so that no shopper waits for a
 * call to TackQuote: the seller's registration policy (the company and custom fields) and the
 * server's capability list (the attachment control). The jobs in etc/crontab.xml are what put
 * them there. On a store whose cron has never run, the form quietly shows contact fields only
 * and no attachment control; this class lets the Overview say so instead.
 *
 * Read through Magento_Cron's own schedule collection (the `cron_schedule` table that
 * Magento\Cron\Observer\ProcessCronQueueObserver writes), filtered to this module's job codes.
 * A job has run when a row carries an `executed_at` time. Magento prunes finished rows after
 * the "success history" lifetime (Stores > Configuration > Advanced > System > Cron, 60 minutes
 * by default), so "no row" means "has not run recently", which is the condition that matters:
 * the cached entries the jobs write live for one hour.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\ViewModel\Adminhtml;

use Magento\Cron\Model\ResourceModel\Schedule\CollectionFactory;
use Magento\Framework\View\Element\Block\ArgumentInterface;

class CronStatus implements ArgumentInterface
{
    /** The job codes declared in etc/crontab.xml. */
    public const JOB_CODES = [
        'tackquote_warm_registration_config',
        'tackquote_warm_server_capabilities',
    ];

    /**
     * @var CollectionFactory
     */
    private $scheduleCollectionFactory;

    /**
     * @var string|null|false False until read.
     */
    private $lastRun = false;

    /**
     * @param CollectionFactory $scheduleCollectionFactory
     */
    public function __construct(CollectionFactory $scheduleCollectionFactory)
    {
        $this->scheduleCollectionFactory = $scheduleCollectionFactory;
    }

    /**
     * Whether either job has started at least once within the kept history.
     *
     * @return bool
     */
    public function hasRun(): bool
    {
        return $this->getLastRunAt() !== null;
    }

    /**
     * When this module's cron jobs last ran.
     *
     * The latest `executed_at` of this module's jobs (UTC, as stored), or null. The template
     * shows it in the admin's locale and time zone (AbstractBlock::formatDate()).
     *
     * @return string|null
     */
    public function getLastRunAt(): ?string
    {
        if ($this->lastRun === false) {
            $collection = $this->scheduleCollectionFactory->create();
            $collection->addFieldToFilter('job_code', ['in' => self::JOB_CODES]);
            $collection->addFieldToFilter('executed_at', ['notnull' => true]);
            $collection->setOrder('executed_at', 'DESC');
            $collection->setPageSize(1);

            $value = $collection->getFirstItem()->getData('executed_at');
            $this->lastRun = is_string($value) && $value !== '' ? $value : null;
        }

        return $this->lastRun;
    }
}

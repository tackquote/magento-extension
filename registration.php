<?php
/**
 * Magento 2 module registration for TackQuote_Quotes.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

use Magento\Framework\Component\ComponentRegistrar;

ComponentRegistrar::register(
    ComponentRegistrar::MODULE,
    'TackQuote_Quotes',
    __DIR__
);

<?php
/**
 * The visitor outside the storefront and the APIs: adminhtml, crontab, CLI. EXEMPT.
 *
 * Declared as the GLOBAL preference (etc/di.xml) so that anything resolving
 * VisitorContextInterface outside the areas that override it gets an answer instead of an
 * "interface cannot be instantiated" error — and the answer is "quote-only does not apply",
 * because quote-only mode is a storefront policy and the admin must keep working (admin
 * order creation drives the same Quote::addProduct()). The guards are not even declared in
 * those areas; this is the second line, not the first.
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteOnly;

class NoVisitorContext implements VisitorContextInterface
{
    /**
     * No visitor is exempt here.
     *
     * @return bool
     */
    public function isExempt(): bool
    {
        return true;
    }

    /**
     * No visitor is signed in here.
     *
     * @return bool
     */
    public function isLoggedIn(): bool
    {
        return false;
    }

    /**
     * Customer group of a guest.
     *
     * @return int
     */
    public function getCustomerGroupId(): int
    {
        return 0;
    }

    /**
     * No customer id: there is no visitor.
     *
     * @return int|null
     */
    public function getCustomerId(): ?int
    {
        return null;
    }

    /**
     * No email address: there is no visitor.
     *
     * @return string
     */
    public function getCustomerEmail(): string
    {
        return '';
    }
}

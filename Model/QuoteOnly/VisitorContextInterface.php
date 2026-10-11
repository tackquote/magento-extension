<?php
/**
 * Who is asking — the four facts quote-only mode decides on, independent of HOW the caller
 * reached Magento.
 *
 * WHY AN INTERFACE. Quote-only mode used to read Magento\Customer\Model\Session directly,
 * which is right for the storefront and meaningless in the Web API and GraphQL areas: a REST
 * or GraphQL caller is identified by a token (or by nothing), not by a session. Magento's
 * own abstraction for that is Magento\Authorization\Model\UserContextInterface, which is
 * bound to a CompositeUserContext with area-specific members — webapi_rest:
 * token, OAuth, guest (module-webapi/etc/webapi_rest/di.xml:14-31); graphql: token, guest
 * (module-graphql/etc/graphql/di.xml:11-21). Each area therefore gets its own
 * implementation through an area-scoped `<preference>`:
 *
 *   frontend                                   Model\QuoteOnly\SessionVisitorContext
 *   webapi_rest, webapi_soap, graphql          Model\QuoteOnly\ApiVisitorContext
 *   everything else (global etc/di.xml)        Model\QuoteOnly\NoVisitorContext — EXEMPT
 *
 * Area-scoped di.xml is Adobe's documented mechanism ("Each module can have a global and
 * area-specific di.xml file" — developer.adobe.com/commerce/php/development/build/
 * dependency-injection-file, "Areas and application entry points").
 *
 * @package TackQuote_Quotes
 */

declare(strict_types=1);

namespace TackQuote\Quotes\Model\QuoteOnly;

interface VisitorContextInterface
{
    /**
     * A caller quote-only mode must never refuse: the merchant's admin or an integration
     * (which includes TackQuote converting an accepted quote into an order). A storefront
     * shopper is never exempt.
     *
     * @return bool
     */
    public function isExempt(): bool;

    /**
     * Whether the visitor is a signed-in customer.
     *
     * @return bool
     */
    public function isLoggedIn(): bool;

    /**
     * Magento customer group id; 0 (NOT LOGGED IN) for a guest.
     *
     * @return int
     */
    public function getCustomerGroupId(): int;

    /**
     * Customer id of the visitor, or null for a guest.
     *
     * @return int|null Null for a guest.
     */
    public function getCustomerId(): ?int;

    /**
     * The signed-in customer's account email, or '' for a guest. Read lazily: only the
     * "except approved wholesale accounts" scope needs it.
     *
     * @return string
     */
    public function getCustomerEmail(): string;
}

# TackQuote for Magento 2

> Part of the TackQuote integrations family. All platforms are listed on the TackQuote GitHub organization page: [github.com/tackquote](https://github.com/tackquote) (TackQuote integrations index).

A Magento 2 Composer module (`tackquote/module-quotes`, module name `TackQuote_Quotes`)
that adds B2B quoting to the storefront: a **"Request a Quote"** button on product pages, a
multi-product **quote list** with its own drawer, and an admin dashboard with a connection
test. Submissions create real quote requests — and, where the seller's policy allows it,
real buyer companies — in TackQuote. It is the Magento counterpart to the TackQuote
WooCommerce plugin.

Magento's product catalog sync, inventory pull, quote-to-checkout and order import are
handled entirely outside this module, by
`apps/api/src/modules/integrations/magento/{magento.controller,magento.service}.ts` against
the Magento Admin REST API, configured under Settings → Integrations → Magento in the
seller portal. This module does not touch any of that. It exists to add the storefront
entry points, which a REST-API-only connector cannot do because nothing calls out from the
storefront to TackQuote without a module installed on the Magento side.

Order sync **from** Magento to TackQuote also needs no module component:
`CommercePullCronService` (`apps/api/src/modules/integrations/commerce-pull-cron.service.ts`)
pulls Magento orders on a schedule via the Admin REST API. It explicitly bypasses its
"skip platforms that have webhooks" rule for Magento (`row.type !== 'magento'`) because
Magento has no outbound payment webhooks, so Tack always pulls for this platform — unlike
WooCommerce, which needs the plugin to push.

## What this module does

### Storefront — single-product requests

- A "Request a Quote" button renders under the add-to-cart area on the product view page
  (`view/frontend/layout/catalog_product_view.xml`, `Block/RequestQuote.php`,
  `view/frontend/templates/button.phtml`).
- Clicking it opens a multi-step modal (built on `Magento_Ui/js/modal/modal`, so it
  inherits the active theme) seeded with just that product.
- Products with required options — configurable, bundle, grouped, or anything with required
  custom options (`Model/ProductOptionRequirement.php`) — must have their options chosen
  first. Quoting a bare parent SKU would name something the seller cannot fulfil.

### Storefront — multi-product quote list

- An "Add to Quote" control appears on the product page and, optionally, inside each
  product tile on category and search listings (`Block/ListingButton.php`, attached to
  Magento's own `category.product.addto` container — the extension point Magento_Wishlist
  uses — so no core file is edited and no catalog template is overridden).
- The list lives in the browser under the localStorage key **`tack_quote_list`**, never in
  the Magento cart. Quoting must not touch stock, cart totals or checkout, and Magento's
  own cart is internally called a "quote" (Magento_Quote), so building on it would collide
  with that name.
- A floating widget with an item count opens a drawer where rows can have their quantity
  changed or be removed (`Block/QuoteList.php`, `view/frontend/templates/quote-list.phtml`,
  `view/frontend/web/js/quote-app.js`). The drawer's submit button opens the same form,
  seeded with the whole list.
- On a listing tile there is no option UI, so products requiring a selection link through
  to their product page instead of being added.
- The list is capped at 50 items in the browser and again at 50 server-side, matching the
  API's own `MAX_ITEMS`.

**Prices are always re-resolved server-side.** The browser stores and sends only a SKU, a
display name and a quantity — never a price. `Controller/Quote/Submit.php` looks every SKU
up through `ProductRepositoryInterface` and prices it via `Model/ProductQuoteResolver.php`,
which reads the real customer-visible price through the price-info pipeline (`final_price`,
falling back to `getFinalPrice()` then `getPrice()`), so special prices, catalog price rules
and tier prices are honoured. localStorage is fully editable by the shopper, so a posted
price would let anyone quote themselves any amount. For a configurable, the chosen variant
is resolved to the child SKU actually being quoted, and the selection is folded into the
quote note as human-readable text ("Size: M, Color: Blue").

### Storefront: quote page, cart button, target prices and attachments

All four are off by default, under *Quote page, cart button and attachments*
(`Model/QuoteRequestConfig.php`). They work with the same quote list and the same form as
above; nothing here adds a second form or a second submit path.

- **Quote page.** `GET /tackquote/quote` (`Controller/Quote/Index.php`,
  `Block/QuotePage.php`, `view/frontend/templates/quote-page.phtml`) shows the quote list as
  a table: quantities can be changed, rows removed, a target price entered per row (when that
  switch is on) and a message added. Its button opens the shared form with the whole list and
  the message as the note. No CMS page is needed; the quote list gets a link to the page
  (label configurable). While the page is switched off the URL answers Magento's 404 page.
  The page is the same for every visitor (the rows are drawn in the browser), so it is safe
  for the full-page cache.
- **Cart-page button.** "Request a quote for your cart" (`Block/CartQuoteButton.php`,
  `view/frontend/layout/checkout_cart_index.xml`, under "Proceed to Checkout") copies the
  SKUs and quantities of the cart into the quote list, in the browser, from the `cart`
  customer-data section the minicart already uses (`view/frontend/web/js/cart-seed.js`), and
  opens the list. The cart is not changed and the two stay separate afterwards. Bundle and
  gift-card items, and items with custom options, are left out and named to the shopper:
  their cart SKU is built from the chosen options, so no product carries it. Not shown while
  quote-only mode applies to the visitor (that mode has its own panel on the cart page).
- **Target price per line.** An optional "Target price" field per row in the form's last
  step and on the quote page. It is sent as `lineItems[].targetPrice`, and TackQuote stores it
  as the price the buyer asked for; it never sets the quoted price, which is still resolved
  server-side from the catalog. A value that is not a non-negative number is refused with a
  message rather than dropped. Sent per line only to a TackQuote server whose `ping` lists
  `targetPrice`; an older server gets the target prices written into the note instead.
- **Attachments.** Up to 3 files per request, PDF, JPEG or PNG, 5 MB each (TackQuote's own
  limits). The browser posts them to `POST /tackquote/quote/upload`
  (`Controller/Quote/Upload.php`, form key, its own per-IP limit of 20 per 10 minutes).
  `Model/AttachmentValidator.php` checks every file before anything is sent: the count, the
  size on disk, and the type from the file's leading bytes, which finfo and the file name's
  extension must both agree with; the type the browser declares is ignored. Each file is then
  sent to TackQuote (`POST /v1/storefront/v1/quote-upload`) and PHP's temporary copy is left
  for PHP to delete at the end of the request: **nothing is saved in Magento**. TackQuote
  answers an id per file; the quote request names those ids and TackQuote attaches all of
  them or refuses the request. A signed-in customer's files are tied to the account email; a
  guest's to a token TackQuote issues on the first upload. The control appears only when
  TackQuote's `ping` lists `attachments` (checked by cron every 5 minutes,
  `Cron/WarmServerCapabilities.php`, cached by `Model/ServerCapabilities.php`). Any failure is
  shown to the shopper and no file is reported as attached that was not.

**What is sent to TackQuote, in addition to the quote request above:** each attached file's
name (made safe: base name only, unusual characters replaced, at most 200 characters) and its
bytes; for a signed-in customer, the account email on each upload; for a guest, the upload
token. Target prices travel on the quote request's lines (or in its note).

### Storefront — company registration

- The form renders the **seller's own registration policy**, fetched from TackQuote rather
  than hardcoded: whether companies and/or individuals are accepted (`mode`,
  `allowCompany`), which built-in company fields the seller marked required
  (`requiredCompanyFields`), and the seller's custom questions (`customFields`, including
  their type, required flag, select options and help text). Cached by
  `Model/RegistrationConfigProvider.php`; the response is visitor-independent, so it is
  safe to render into full-page-cached HTML.
- **The policy is never fetched while a shopper waits.** `Block/QuoteList.php` renders on
  every storefront page and reads cache only (`getCached()`), so a cold cache costs nothing;
  `Cron/WarmRegistrationConfig.php` does the fetching every 5 minutes
  (`etc/crontab.xml`). **Magento cron must be running** or the policy is never warmed and
  the form permanently degrades to contact fields only — see Known limitations.
- Submitting registers a real buyer and, where the policy allows, a real company plus
  membership — TackQuote runs the identical sequence as its own buyer-portal registration
  route (`PluginRegistrationService.register` → `BuyersService::registerWithCompany`).
- **Approval is honoured.** When the seller requires company approval, the API returns
  `awaitingApproval: true` and the storefront says "Request received — your account needs
  approval" instead of a plain success, so the buyer is not left waiting for access nobody
  granted. The quote is still created either way.
- A signed-in shopper's first/last name are prefilled client-side from Magento's
  `customer-data` (private-content) section rather than server-rendered, because these
  blocks are cacheable. Server-side, `Submit.php` overrides the posted email with the
  authenticated customer's own address, so a quote is always attributed to the signed-in
  identity.

### Storefront — the submit endpoint

- Everything posts to this module's own controller, `POST /tackquote/quote/submit`
  (`Controller/Quote/Submit.php`), so the TackQuote API key stays server-side and never
  reaches the browser — the same principle as the WooCommerce plugin's `admin-ajax.php`
  handler.
- It is CSRF-validated against the session form key. The key is **not** server-rendered:
  pages are full-page-cached, so a baked-in key would be shared by every visitor and fail
  for all of them. The template emits an empty `input[name="form_key"]`, which
  Magento_PageCache's `form-key-provider.js` fills from the `form_key` cookie; the JS also
  reads that cookie directly as a fallback.
- From the internet's point of view the endpoint is unauthenticated (the API key
  authenticates the store, not the visitor), so it is also IP-rate-limited
  (`Model/SubmissionThrottle.php`) and duplicate-suppressed
  (`Model/IdempotencyGuard.php`) so a double-click or a browser retry collapses into one
  quote.
- Only an allow-list of company fields is forwarded, so a crafted form cannot smuggle
  arbitrary keys into the company record.

### Admin

- A top-level **TackQuote** entry in the admin sidebar (`etc/adminhtml/menu.xml`) with
  **Dashboard** and **Settings** items.
- The **Dashboard** (`Controller/Adminhtml/Dashboard/Index.php`,
  `Block/Adminhtml/Dashboard.php`, `view/adminhtml/templates/dashboard.phtml`) shows a
  live/not-live status banner, a **setup checklist** naming exactly what is missing
  (TackQuote enabled → API key set → quote button turned on, each with the fix), the
  configured API base URL, whether a key is set, the current button label, and a **Test
  connection** button.
- **Test connection** exists in two places, both calling
  `Model\Api\Client::testConnection()` through the admin controller
  `Controller/Adminhtml/Connection/Test.php` (`POST tackquote/connection/test`):
  the dashboard button, and an inline button in **Stores → Configuration → TackQuote**
  (`Block/Adminhtml/System/Config/TestConnection.php`,
  `view/adminhtml/templates/system/config/test-connection.phtml`). The configuration screen
  is where an admin actually pastes a key, so testing without leaving the page is what stops
  stores sitting misconfigured.
  The controller distinguishes *disabled*, *no key set* and *configured but rejected*,
  because those need completely different fixes.
- **Connect with TackQuote** (see the section below): a card on the Dashboard, and a compact
  one beside the API key field in **Stores → Configuration → TackQuote**, show how the store
  is connected (*Connected via Connect with TackQuote*, *Connected via API key* or *Not
  connected*) and offer **Connect with TackQuote** and **Disconnect**.
- Two separate ACL resources on purpose (`etc/acl.xml`): `TackQuote_Quotes::dashboard`
  (under a top-level `TackQuote_Quotes::tackquote`) backs the menu and connection test, so
  day-to-day TackQuote access can be granted **without** store-configuration rights;
  `TackQuote_Quotes::config` sits under `Magento_Config::config` because that is where
  Magento resolves a `system.xml` section's `<resource>`.

## Configuration

**Stores → Configuration → TackQuote → TackQuote Settings** (`etc/adminhtml/system.xml`,
read through `Model/Config.php`). The section is scoped to default/website — not per store
view.

| Group | Field | Notes |
| --- | --- | --- |
| Connection | **Enable TackQuote** | Master switch. |
| Connection | **TackQuote API Base URL** | Default `https://api.tackquote.com/v1`. Include `/v1`, no trailing slash. |
| Connection | **Connect with TackQuote** | Button (no stored value): gets the API key below without copying it. See *Connect with TackQuote*. |
| Connection | **TackQuote API Key** | Stored encrypted (`Magento\Config\Model\Config\Backend\Encrypted`) and decrypted on read. Needs the `quotes:write` scope. |
| Connection | **Verify** | Inline "Test connection" button. |
| Request a Quote button | **Show quote button on product pages** | Single-product request button. |
| Request a Quote button | **Button label** | |
| Request a Quote button | **Show "Add to Quote" button** | Turns on the multi-product quote list. |
| Request a Quote button | **"Add to Quote" label** | |
| Request a Quote button | **Show on category and search results** | Adds "Add to Quote" to listing tiles. |
| Request a Quote button | **Quote list submit label** | Label on the drawer's submit button. |
| Quote page, cart button and attachments | **Enable the quote page** | Off by default. `/tackquote/quote` plus a link from the quote list. Needs "Add to Quote". |
| Quote page, cart button and attachments | **Quote page link label** | |
| Quote page, cart button and attachments | **Ask for a target price per product** | Off by default. Optional target price per row in the form and on the quote page. |
| Quote page, cart button and attachments | **Show a quote button on the cart page** | Off by default. Copies the cart into the quote list. Needs "Add to Quote". |
| Quote page, cart button and attachments | **Cart button label** | |
| Quote page, cart button and attachments | **Allow attachments on quote requests** | Off by default. Up to 3 files, PDF/JPEG/PNG, 5 MB each, sent to TackQuote. |
| Quote-Only Store | **Enable quote-only mode** | Off by default. See *Quote-only mode*. |
| Quote-Only Store | **Applies to** | Everyone / Guests only / Selected customer groups / Everyone except approved wholesale accounts. |
| Quote-Only Store | **Show "Price on request" instead of prices** | Off by default. For the shoppers the mode applies to. |
| Quote-Only Store | **Customer groups** | For *Selected customer groups*. |
| Product (Catalog > Products) | **Quote only (TackQuote)** | Yes/No product attribute, default No. One product quote-only for everyone. |
| B2B Display | **Show account price** | Off by default. "Your account price" for a signed-in customer. Display only. |
| B2B Display | **Show volume pricing table** | Off by default. The customer's quantity breaks. Display only. |
| B2B Display | **Show order limits notice** | Off by default. "Order between X and Y units". Not enforced in the cart yet. |
| B2B Display | **Show buyer group badge** | Off by default. The customer's TackQuote buyer group. |
| B2B Display | **Only for these customer groups** | Empty = every signed-in customer. See *B2B display* below. |

Every storefront control is additionally gated on the module being enabled *and* an API key
being present — offering a control that cannot submit would only produce a dead end.

## The TackQuote endpoints this module calls

`Model/Api/Client.php` calls TackQuote's dedicated Magento plugin routes
(`apps/api/src/modules/integrations/magento/magento-plugin.controller.ts`,
`MagentoPluginController`), authenticated by the store's TackQuote API key rather than a
seller JWT:

| Route | Scope | Used for |
| --- | --- | --- |
| `GET /v1/integrations/magento/ping` | none | Connection test. Deliberately unscoped so a *wrong key* is distinguishable from a *wrong base URL*. Its `capabilities` list is also read (cached) to decide whether target prices and attachments may be sent. |
| `GET /v1/integrations/magento/registration-config` | none | The seller's registration policy, to render the form. Exposes form shape only. |
| `POST /v1/integrations/magento/quote-requests` | `quotes:write` | Register the buyer/company and create the quote. Carries `lineItems[].targetPrice` and `uploadIds` / `uploadToken` only when `ping` lists `targetPrice` / `attachments`. |
| `POST /v1/storefront/v1/quote-upload` | `quotes:write` | Attachments: one file's raw bytes, server to server, `X-Api-Key` only (`Model/Api/StorefrontClient.php`). |
| `POST /v1/storefront-connect/magento/requests` | no key | Connect with TackQuote: open a connect request (server to server). |
| `POST /v1/storefront-connect/magento/exchange` | no key | Connect with TackQuote: redeem the one-time code with the PKCE verifier for the API key. |
| `POST /v1/integrations/magento/plugin-key/revoke` | none | Disconnect: revokes the key the request presents, and only that key. |
| `GET /v1/storefront/v1/wholesale-price` | none | B2B display: the signed-in customer's account price for one SKU. |
| `GET /v1/storefront/v1/quantity-breaks` | none | B2B display: the customer's quantity-break ladder for one SKU. |
| `GET /v1/storefront/v1/order-limits` | none | B2B display: quantity limits for one SKU. |
| `GET /v1/storefront/v1/buyer-group` | none | B2B display: the customer's buyer group. |
| `GET /v1/storefront/v1/price-access` | none | Quote-only *Everyone except approved wholesale accounts*: is this signed-in customer's wholesale application approved? |

The five `/storefront/v1` reads are TackQuote's platform-neutral storefront API
(`apps/api/src/modules/storefront-api/storefront-v1.controller.ts`), called by
`Model/Api/StorefrontClient.php` with the key in `X-Api-Key` **only** — that API refuses a
request carrying a second credential in `Authorization`. They need no scope.

That controller is separate from `MagentoController`, which is JWT-guarded and drives the
opposite direction (Tack → the store: connect, sync, checkout). Quotes are tagged with the
`source` this module sends (`magento`) plus `plugin-request`, and the store's currency is
forwarded rather than assumed.

`ping` also answers `connectedVia` (`connect` or `api_key`), how the presented key was
issued; the module records the same fact itself, so it does not depend on it.

## Connect with TackQuote

Connect this store by signing in to TackQuote and approving, instead of copying an API key.
The WooCommerce plugin has the same flow; the TackQuote side is shared
(`apps/api/src/modules/integrations/storefront-connect/` and `magento/connect/` in tack).
Pasting a key keeps working exactly as before.

1. **Start.** An admin presses **Connect with TackQuote** (Dashboard or configuration). The
   controller (`Controller/Adminhtml/Connect/Start.php`) is POST-only, so Magento checks the
   admin form key first, and needs both `TackQuote_Quotes::connect` and
   `TackQuote_Quotes::config`. The store's base URL must be https (see below). The module
   makes a PKCE code verifier and a random `state` (`Model/Connect/Pkce.php`, RFC 7636 S256)
   and asks TackQuote, server to server, to open a connect request naming the store, its
   Admin address and its return route `tackquote/connect/callback`, with the verifier's
   challenge. The verifier, encrypted with the installation's crypt key, and the state's
   SHA-256 are kept in Magento's flag table for this admin user for 15 minutes
   (`Model/Connect/PendingConnect.php`). The browser goes to the consent page TackQuote
   answered with, only after `Model/Connect/ConnectUrlPolicy.php` accepted it (https,
   `app.tackquote.com` for the default API URL, the Magento consent page, this request).
2. **Approve.** On TackQuote the admin signs in (or creates an account) and approves or
   cancels.
3. **Return.** TackQuote sends the browser back to `tackquote/connect/callback`
   (`Controller/Adminhtml/Connect/Callback.php`). The pending connect of this admin user is
   taken once and the `state` compared; only then is the one-time code exchanged, server to
   server, with the verifier for an API key carrying `quotes:write` and `buyers:write` (the
   wholesale and net-terms application forms); never `orders:write`. The key is saved where a
   pasted key is, at the default scope, encrypted (`Model/Connect/KeyStore.php`), and tested.
   The Dashboard says whether the test passed.
4. **Disconnect** (`Controller/Adminhtml/Connect/Disconnect.php`, POST) revokes the key in
   TackQuote when Connect issued it, then removes it from this store. A pasted key is only
   removed from this store; revoke it in TackQuote yourself.

**What is sent to TackQuote:** this store's base URL, its Admin address, the store name, the
module and Magento versions, the store locale, the random state and the code challenge. No
customer, order or product data.

**HTTPS.** Connect needs the store's base URL (`web/secure/base_url`, or
`web/unsecure/base_url` when *Use Secure URLs in Admin* is off) to be https. The one
exception is a development store on plain http whose host is `localhost`, a single-label
name, a `.local`, `.localhost`, `.test` or `.internal` name, or a private IP address, the same
rule as the WooCommerce plugin; TackQuote applies it again.

## Quote-only mode (B2B catalog)

**Stores > Configuration > TackQuote > TackQuote Settings > Quote-Only Store (B2B Catalog).**
Turns the whole storefront into a B2B catalog: Add to Cart and checkout are refused and
shoppers request a quote instead. Off by default.

Three parts, each matching the WooCommerce plugin (1.11.0):

- **The store-wide mode** (this section's switch), scoped by *Applies to*.
- **Per product:** the product attribute **Quote only (TackQuote)** (Catalog > Products >
  *product* > Product Details), Yes/No, default No. A Yes product is quote-only for every
  storefront visitor **even while the store-wide mode is off**: its own Add to Cart blocks
  are removed from its page, its tiles show **"Available on quote"** beside the price and
  hide their Add to Cart, the quote buttons show regardless of the button settings, and the
  cart, checkout and APIs refuse it like everything below. Set it on the parent of a
  configurable, bundle or grouped product; its options follow the parent. Created by
  `Setup/Patch/Data/AddQuoteOnlyProductAttribute.php` on `setup:upgrade`.
- **"Price on request"** (*Show "Price on request" instead of prices*, off by default): for
  the shoppers the store-wide mode applies to, product pages and category, search, related
  and widget tiles show the translatable text "Price on request" instead of the price, and
  the B2B display's account price and volume table are withheld. A per-product quote-only
  item keeps its price (as in WooCommerce). See *Price on request* below.

### It is refused by the server, not hidden by CSS

| where | what |
|---|---|
| `Plugin/Quote/QuoteOnlyCartGuard.php` → `beforeAddProduct()` | refuses `Magento\Quote\Model\Quote::addProduct()` |
| `Plugin/Checkout/QuoteOnlyExistingCartGuard.php` | refuses quantity increases, reconfigure and reorder on a pre-existing cart |
| `Plugin/Checkout/QuoteOnlyCheckoutGuard.php` → `aroundExecute()` | refuses `Magento\Checkout\Controller\Index\Index`, redirecting to the cart with a reason |
| `Plugin/Quote/QuoteOnlySubmitGuard.php` → `beforeSubmit()` | refuses `Magento\Quote\Model\QuoteManagement::submit()` — every order placement: Luma's REST payment step, GraphQL `placeOrder`, frontend payment flows |
| `Plugin/Quote/QuoteOnlyCartItemRepositoryGuard.php` → `beforeSave()` | REST/SOAP/GraphQL: refuses quantity increases on an existing line through `CartItemRepositoryInterface::save()` |
| `Observer/QuoteOnlyMultishippingGuard.php` | refuses the whole `multishipping` route (*Check Out with Multiple Addresses*) |

Every one of them refuses the store-wide mode **and** a product that is quote-only by its own
flag (a cart already holding such a product cannot be checked out until it is removed).

`curl -d 'product=42&qty=1' https://store/checkout/cart/add/` gets the same refusal as the
browser: a `before` plugin that throws aborts the intercepted call, so core's cart code never
runs.

**Why `Magento\Quote\Model\Quote::addProduct()` and not a controller plugin.** It is
`@api` (`vendor/magento/module-quote/Model/Quote.php:34`), which is Magento's stability
guarantee, and it is the single model every add path funnels through — the product page,
related products, wishlist "Add to Cart", `checkout/cart/addgroup`, and
`Magento\Checkout\Model\Cart::addProduct()`, which is itself only a caller
(`vendor/magento/module-checkout/Model/Cart.php:392` is literally
`$result = $this->getQuote()->addProduct($product, $request);`). A plugin on
`Magento\Checkout\Controller\Cart\Add` would guard one route and miss the rest;
`Magento\Checkout\Model\Cart` carries `@deprecated 100.1.0 Use \Magento\Quote\Model\Quote
instead` in its own docblock. `LocalizedException` is thrown rather than a generic one
because `Magento\Checkout\Controller\Cart\Add::execute()` catches that type at
`Add.php:170` and renders the message to the shopper instead of producing a 500.

### The REST API, GraphQL and multishipping are guarded too

Earlier versions left these open (they were in *Known limitations*). They are now closed,
by guarding the methods every path there funnels into rather than individual endpoints
(Magento 2.4.8 line numbers):

- **New cart lines.** `POST /V1/carts/mine/items` and `POST /V1/guest-carts/{id}/items` run
  `CartItemRepositoryInterface::save()` (the guest repository delegates to it,
  `GuestCartItemRepository.php:61-66`), whose persister calls `$quote->addProduct()` for a
  new line (`CartItemPersister.php:98`); GraphQL `addProductsToCart` calls
  `$cart->addProduct()` (`Quote/Model/Cart/AddProductsToCart.php:163`) and
  `addSimpleProductsToCart` does too (`QuoteGraphQl/Model/Cart/AddSimpleProductToCart.php:65`).
  So the existing `Quote::addProduct()` guard is now also declared in `etc/webapi_rest`,
  `etc/webapi_soap` and `etc/graphql` `di.xml`.
- **Quantity increases on an existing line** over REST/SOAP never reach `addProduct()` — the
  persister sets the quantity directly (`CartItemPersister.php:84`) — so
  `CartItemRepositoryInterface::save()` gets its own guard. The storefront does not use that
  repository (`checkout/cart/add` goes through `Checkout\Model\Cart` to
  `Quote::addProduct()`), which is why the storefront keeps its own guards.
- **Orders.** `QuoteManagement::submit()` is where Luma's REST payment step
  (`PaymentInformationManagement.php:156`), the guest variant, GraphQL `placeOrder`
  (`QuoteGraphQl/Model/Cart/PlaceOrder.php:61`) and `placeOrder()` itself
  (`QuoteManagement.php:476`) all end, so it is guarded in the frontend and API areas.
- **Multishipping** has its own controllers and places orders through the order service
  (`Multishipping.php:826` → `PlaceOrderDefault.php:39`), so its route is refused by an
  observer on `controller_action_predispatch_multishipping` that sets `FLAG_NO_DISPATCH`
  (`FrontController.php:240`, `:269-281`). An event, unlike a plugin, names no class of the
  optional `Magento_Multishipping` module, so it is inert where that module is removed.

**Who is refused in the API areas** is decided by the caller's type, read from
`Magento\Authorization\Model\UserContextInterface` (`Model/QuoteOnly/ApiVisitorContext.php`):
a customer token, the Luma checkout's customer session, or an anonymous caller is a
shopper and gets the same answers as the storefront (customer group and email come from the
customer record); **an admin or integration token is exempt**. That keeps TackQuote's own
quote conversion working: it calls the store's guest-cart REST endpoints signed with the
Integration's OAuth credentials (tack `magento.service.ts:1650`, `:1660`, `:1735`), and in
`webapi_rest` the OAuth user context (sort order 40) is consulted before the guest context
(100) and answers `USER_TYPE_INTEGRATION`. UNVERIFIED on a running store; the manual check is
in the pull request.

### Admin and integrations stay exempt — structurally

Every guard is declared in an **area** `di.xml` — `etc/frontend`, and for the cart and order
guards also `etc/webapi_rest`, `etc/webapi_soap` and `etc/graphql` — never in the global
`etc/di.xml`. Admin order creation (Sales > Orders > Create New Order) drives the very same
`Quote::addProduct()` and `QuoteManagement::submit()`; it keeps working because adminhtml
never loads any of those files. (The global `etc/di.xml` holds only the fallback visitor,
`Model/QuoteOnly/NoVisitorContext.php`, which is always exempt.) In the API areas, where the
same methods also carry the merchant's integrations, the exemption is the caller's type —
see the section above. Area-scoped `di.xml`
is Magento's own mechanism for this — core scopes
`Magento\Customer\Model\App\Action\ContextPlugin` the same way in
`vendor/magento/module-customer/etc/frontend/di.xml:23-26`. There is deliberately no runtime
area check: a check can be edited out, a file adminhtml never loads cannot be.
`Test/Unit/Layout/QuoteOnlyLayoutTest.php` fails if any guard moves into a global `etc/di.xml`.

### Who it applies to

**Everyone** (default), **Guests only** (signed-in customers keep a normal cart and
checkout — the usual B2B setup), **Selected customer groups**, or **Everyone except approved
wholesale accounts**. Magento group 0 is
`NOT LOGGED IN`, a real row in the customer-group grid, so it can be selected on its own and
is equivalent to "Guests only". (The OpenCart build of this feature deliberately *drops*
group 0, because OpenCart uses it as a "no group" sentinel; copying that rule here would
silently delete a merchant's selection.)

**Everyone except approved wholesale accounts** keeps the cart only for a signed-in
customer whose TackQuote wholesale application is **approved**. The module asks
`GET /v1/storefront/v1/price-access?buyerEmail=<the customer's email>` with the API key in
`X-Api-Key` (`Model/QuoteOnly/WholesaleApproval.php` through `Model/Api/StorefrontClient.php`;
server: `StorefrontV1Controller::priceAccess`, tack
`apps/api/src/modules/storefront-api/storefront-v1.controller.ts:211-220`). Only
`{"status": "linked", "wholesaleApproved": true}` is approval (`PriceAccessResult`,
`storefront-core/storefront-results.ts:96-99`); a buyer linked any other way, an unknown
buyer and a guest are not. The answer is cached per customer for **5 minutes**, so an
approval reaches the storefront within that. **It fails closed:** TackQuote unreachable, an
HTTP error (429 included), a body that is not JSON or an answer this module does not
recognise all mean "not approved", and that is remembered for 1 minute so an outage is not
retried on every page view. An approved buyer caught by an outage can still request a
quote. TackQuote matches the buyer by email address, which Magento does not confirm unless
*Require Emails Confirmation* is on — turn it on with this scope.

Full-page cache is safe without a custom vary dimension for the first three scopes: the decision reads only store config
plus signed-in state and customer group, and Magento already puts both into the HTTP context
that produces the vary hash (`Magento\Customer\Model\App\Action\ContextPlugin` lines 50
and 55). **Adding any other input to `Model/QuoteOnlyMode.php` — a cookie, a time of day, the
customer id — would serve one visitor's storefront to another** and needs its own
`HttpContext::setValue()` first.

The approved-wholesale scope is exactly such an input, so it ships with its own vary value:
`Plugin/PageCache/WholesaleApprovalContext.php` is a `beforeGetVaryString` plugin on
`Magento\Framework\App\Http\Context` that sets `tackquote_wholesale_approved` to 1 for an
approved signed-in customer and leaves it at its default 0 for everyone else — Adobe's
documented way to vary public content
(<https://developer.adobe.com/commerce/php/development/cache/page/public-content>,
*Configure page variations*). A value equal to its default is left out of the vary data, so
guests, unapproved customers and stores using another scope keep exactly the cache entries
they had; only approved customers get a second copy. The plugin reads the HTTP context's
signed-in flag before anything else, so an anonymous page view never starts a session or
calls TackQuote. A per-product flag needs no vary value: it is part of the product, whose
pages and listings Magento caches per product and cleans on product save.

### The store is never left unable to transact

The same feature nearly shipped a dead storefront twice on other platforms: in WooCommerce
the quote button hung off a hook that only fires *inside* the add-to-cart form, and in
PrestaShop the theme wraps the CTA hook in `{if !$configuration.is_catalog}`. Magento's
equivalent coupling was checked against 2.4.8 source **before** anything was removed:

- The CTA block `tackquote.request.quote` is a child of the container `product.info.main`.
  The blocks removed are children of *different* parents — `product.info.addtocart` of
  `product.info.form.content`
  (`vendor/magento/module-catalog/view/frontend/layout/catalog_product_view.xml:72`) and
  `product.info.addtocart.additional` of `product.info.options.wrapper.bottom` (line 86) —
  so `remove="true"` cannot reach it.
- `Test/Unit/Layout/QuoteOnlyLayoutTest.php` pins that parentage, so a later "put it next to
  Add to Cart" edit that recreates the coupling fails the suite instead of shipping.
- **Both** add-to-cart blocks are removed. They render the same template; removing only the
  first leaves a working-looking button on every product with custom options.
- Quote-only mode **overrides** the `show_button` and `show_on_listing` preferences
  (`Block/RequestQuote.php::isEnabled()`, `Block/ListingButton.php::isEnabled()`). Honouring
  them would leave a product page with no cart button *and* no quote button.
- Enforcement and the CTA are switched on by **one condition**: both require
  `Config::isConfigured()`. With no API key saved, no quote button renders — and the cart is
  therefore **not** blocked either.
- The cart page gets its own notice with a working CTA (`Block/QuoteOnlyNotice.php`), which
  is where a shopper refused at checkout lands.

An ordering bug was fixed along the way: this module's `after="product.info.addtocart"` was
inert, because Magento's `after` only orders *siblings* and returns without moving anything
when the parents differ (`Structure.php:122-130`). It is now `after="product.info"`, a real
sibling, so the quote controls finally render where the file always claimed — directly under
the add-to-cart form rather than below the social links.

Category and search tiles keep their cart button in the DOM and hidden by CSS
(`view/frontend/web/css/request-quote.css`), because that button is hard-coded in
`Magento_Catalog::product/list.phtml:108` rather than rendered as a removable block, and
overriding that template would clobber every theme. **That hiding is cosmetic only** — the
server refuses the POST regardless.

### Price on request

`Plugin/Pricing/QuoteOnlyPriceRender.php` is an `around` plugin on
`Magento\Framework\Pricing\Render::render()`, the one method every catalogue price box is
drawn through: the product page's price blocks (`Catalog/Pricing/Render.php:60`) and every
tile (`ListProduct::getProductPrice()`, `ListProduct.php:419`). No price template is
overridden. Where prices are hidden, the final-price box becomes "Price on request" and every
other price code (tier prices, MSRP) renders nothing; the replacement does not carry the
`price-box` class, so Magento's price-box JavaScript leaves it alone. Otherwise a
per-product quote-only item gets "Available on quote" after its price.

It is declared for the storefront only. The cart, mini-cart and checkout totals, the admin,
and the quote request sent to TackQuote (which still carries Magento's price) are unchanged.
It changes what is displayed, not what is published: see *Known limitations*.

### Existing carts and the checkout route

Turning the mode on **does not empty anybody's cart**. Magento persists carts server-side in
`quote`/`quote_item` for signed-in customers, so "silently deleted" could mean weeks later on
another device; it would also make the switch destructive and effectively one-way. The cart
page stays reachable, and **lowering a quantity, setting it to 0, and removing a line all
stay allowed** — refusing the whole of `updateItems()` would trap a shopper with a basket
they can see, cannot use, and cannot clear.

What a cart cannot do is **grow or convert**: adds are refused, quantity *increases* are
refused (without that, a one-line cart from before the switch could be edited to 10 000 and
checked out), and the checkout route is refused. A pre-existing cart is **inert rather than
destroyed**, and works normally again the moment the mode is switched off.

### Verification

**Parity change** (per-product, "Price on request", the approved-wholesale scope, the API and
multishipping guards): the full suite is 438 tests, 995 assertions, run with phpunit 10.5 on
PHP 8.2 against Magento framework packages from the Mage-OS mirror and a stand-in bootstrap.
Every new guard was mutation-tested: **30 deliberate breaks, 30 caught**, each restored and
byte-compared. Among them: the per-product refusal removed from the cart, submit, checkout and
existing-cart guards; the approved-wholesale scope ignoring the approval or failing open; a
loosened `wholesaleApproved` check; a lookup failure answering "approved" or not being
cached; a 1-hour TTL; the email in the cache id; `price-access` off the client allowlist; an
integration not exempt, or an unknown caller exempt; the exemption removed from the mode;
order placement unguarded; quantity increases allowed over the cart-item API; multishipping
dispatched; prices rendered while hidden; tier prices left beside "Price on request"; the vary
plugin asking for guests or with a default of 1; the per-product layout handle on every page;
the REST cart guard undeclared; a plugin in the global `etc/di.xml`; the B2B account price
shown under "Price on request"; the attribute defaulting to Yes.

The original build: 51 unit tests were added (85 → 136, 255 assertions), run against a real Magento 2.4.8-p5
bootstrap. The guard was mutation-tested with **12 deliberate breaks: 12 caught, 0 survived,
0 rejected as non-compiling.**

| break | caught by |
|---|---|
| the add-to-cart refusal never fires | *a crafted add-to-cart is REFUSED when the mode applies* |
| scope "guests" inverted | *guests-only refuses the public and spares signed-in customers* |
| dead-storefront guard removed (enforce with no API key) | *with no API key the mode stays INACTIVE* |
| CTA override dropped | *the CTA renders EVEN with show_button turned off* |
| only one of the two add-to-cart blocks removed | *both core Add to Cart blocks are removed* |
| CTA moved into a container holding a removed block | *the CTA does not live inside anything being removed* |
| the refusal declared globally | *the refusal is declared for the FRONTEND AREA ONLY* |
| existing-cart guard refuses every update | *lowering the quantity / removing a line is ALLOWED* |
| product-page removals applied on every page | *an ordinary page gets only the global handle* |
| group 0 dropped (the OpenCart rule) | *group 0 is a real group in Magento and can be selected* |
| checkout guard falls through | *checkout is refused and the shopper is sent back to their cart* |
| cart CTA reverts to the `$root`-scoped JS hook | *the cart notice uses its own JS hook* |

```
# from a Magento install with the module in app/code/TackQuote/Quotes
php vendor/bin/phpunit -c app/code/TackQuote/Quotes/Test/Unit/phpunit.xml
```

## B2B display (signed-in customers)

**Stores > Configuration > TackQuote > TackQuote Settings > B2B Display (signed-in
customers).** Gives a signed-in customer on a product page what the WooCommerce plugin's
"B2B features" give a WooCommerce customer. Each part has its own switch, and **every switch
is off by default** (`etc/config.xml`):

| switch | shows | TackQuote route |
|---|---|---|
| Show account price | "Your account price" under the price, with the store's price struck through when the account price is lower | `wholesale-price` |
| Show volume pricing table | Quantity → unit price, captioned "Volume pricing for your account" when the ladder is the customer's own | `quantity-breaks` |
| Show order limits notice | "Order between X and Y units of this product." (or the minimum or maximum alone) | `order-limits` |
| Show buyer group badge | The name of the customer's TackQuote buyer group | `buyer-group` |

**Display only.** Nothing here changes what the Magento cart or checkout charges: the cart
still uses Magento's own prices and does not enforce the order limit. Cart enforcement is a
separate, later change. Turn the price switches on only if you honour the account price
another way (for example by quote). Wherever an account price or volume table is shown, the
shopper also sees: "Your account prices are shown here; the cart and checkout use the
store's prices for now."

### How it stays out of the page cache

Product pages are full-page cached (built-in or Varnish), so nothing about the customer is
rendered into them. `Block/B2bDisplay.php` renders an empty, hidden placeholder and the
product id; after the page loads, `view/frontend/web/js/b2b-display.js` posts to this
module's own `POST /tackquote/b2b/display` (`Controller/B2b/Display.php`), which answers for
the signed-in customer in the session. Adobe's private-content guidance is to load
per-customer data on the client, and only GET and HEAD are cacheable
(<https://developer.adobe.com/commerce/php/development/cache/page/private-content>,
<https://developer.adobe.com/commerce/php/development/cache/page/public-content>); the
response is also sent `Cache-Control: no-store, private`. The POST carries the session's
`form_key` (Magento's built-in CSRF check for `HttpPostActionInterface` controllers). A
guest's page view sends no request at all: the script asks only when Magento's `customer`
section shows someone signed in.

### What is sent to TackQuote

For a signed-in customer only, server to server with the store's API key:

- the customer's **email address** (`buyerEmail`) — TackQuote finds the buyer by it;
- the product **SKU**;
- the **currency** the page shows, and for the price a **quantity** of 1.

Nothing is sent for guests. The browser sends only the product id; it never chooses whose
data is returned. Nothing is written in TackQuote: these routes are reads.

### Who sees what

- Only a customer TackQuote holds data for: an account price is shown only when TackQuote
  marks it as **this account's own** (`accountSpecific`), never the public catalogue price.
- **Only for these customer groups.** TackQuote finds the account by email address, and
  Magento does not confirm an address a customer types at sign-up or changes later unless
  account confirmation is on. Leaving the field empty shows B2B data to every signed-in
  customer; selecting the groups you put approved trade customers in keeps a new account
  that typed someone else's address from seeing that buyer's prices.
- **Configurable, bundle and grouped products** show the badge and the order-limit notice
  but not the account price or volume table: their page price follows the shopper's
  selection, and a parent-level price beside a selected variant would describe another
  product.
- **Currency.** TackQuote prices are in the TackQuote account's currency. When the page
  shows another currency the price and table are withheld, never converted.

### Failure and caching

Any failure — TackQuote unreachable, an error status, a body that is not JSON, an answer
this module does not recognise — shows nothing, so the page looks exactly as Magento renders
it. A complete answer is cached for **60 seconds** per store, customer, email, SKU, currency
and switch set (`Model/B2bDisplay/DataProvider.php`; the cache id is a hash, so no email
address appears in it), to absorb reloads: TackQuote throttles each `/storefront/v1` route
at 60 requests a minute. A failed lookup is not retried for 30 seconds, an HTTP 429 pauses
all lookups for the store for 60 seconds, and one customer can cause at most 30 uncached
lookups a minute.

## Known limitations

- **The cart-page button copies, it does not link.** "Request a quote for your cart" copies
  the cart into the quote list once; later changes to the cart do not reach the list. Bundle
  items and items with custom options are left out (and named). UNVERIFIED on a store: a
  configurable item that also has custom options; TackQuote then cannot resolve its cart SKU
  and that line is left out of the quote.
- **The attachment control waits for cron.** It appears only after
  `Cron/WarmServerCapabilities.php` has read TackQuote's `ping`, so on a new install, or after
  a cache flush, it shows within 5 minutes of cron running, and never if cron does not run.
- **Attachments and TackQuote's guest setting.** TackQuote applies its "who can request
  quotes: guest visitors" setting to file uploads; when a seller has switched guest requests
  off, a guest's files are refused even though the request itself is still accepted.
- **Duplicate suppression is belt-and-braces.** `Model/Api/Client.php` sends an
  `Idempotency-Key` header AND `Model/IdempotencyGuard.php` collapses double-submits
  store-side. The two catch different things: the guard stops a double-click before it
  leaves the store, the header catches retries the guard cannot see (a second Magento node,
  or a request after a cache flush). Earlier revisions of this list claimed the header was
  commented out because of an upstream RLS defect; that was fixed upstream and the header
  has been sent since — see the comment in `Model/Api/Client.php`.
- **Quote-only mode in the API areas — what is still not refused.** GraphQL
  `updateCartItems` sets a line's quantity on the item itself and does not go through
  `CartItemRepositoryInterface` (`QuoteGraphQl/Model/Cart/UpdateCartItem.php:105-114`), so a
  quantity increase there is not refused. That cart still cannot become an order:
  `QuoteManagement::submit()` refuses it. UNVERIFIED on a running store, like the rest of the
  API coverage.
- **"Price on request" is a display setting, not a secret.** It replaces rendered price boxes
  only. Prices still appear in machine-readable places: the configurable product's option
  JSON, custom-option price labels ("+ $5.00"), the Open Graph `product:price:amount` meta
  tag, product feeds, and the REST and GraphQL catalogue APIs. The WooCommerce plugin's
  setting has the same scope.
- **Per-product quote-only follows the product the shopper adds.** For a configurable, bundle
  or grouped product that is the parent, so set the flag on the parent. A flag set only on a
  child simple is honoured when that simple is added on its own, and at checkout (every cart
  line is checked), but not when it is added through its parent.
- **Approved-wholesale scope and email.** TackQuote matches the buyer by email address, and
  Magento confirms an address only with *Require Emails Confirmation* on. Without it, a new
  account typed with an approved buyer's address would get that buyer's cart. The approval
  is read for at most 5 minutes from cache; revoking an approval takes that long to reach
  the storefront.
- **The registration policy can be up to 5 minutes stale**, bounded by the cron schedule in
  `etc/crontab.xml` rather than by the 1-hour cache TTL. A policy change in TackQuote takes
  that long to reach the storefront. **If Magento cron is not running the policy is never
  warmed at all** and the form shows contact fields only, forever — the same degraded state
  as an outage, but permanent, so check `bin/magento cron:run` on a new install.
- **The form degrades rather than breaks with no policy** — a TackQuote outage, or a cache
  flush that cron has not yet caught up with, drops the company step and the seller's custom
  questions and leaves contact fields only. That still produces a usable quote request, and
  TackQuote re-checks its own policy server-side on submit, so a required company detail is
  refused there rather than silently accepted.
- **The product-page triggers sit directly after the add-to-cart form**, ordered
  `after="product.info"` within `product.info.main`. They are deliberately NOT inside
  `product.info.form.content`, which is what keeps quote-only mode able to remove
  `product.info.addtocart` without taking the quote CTA with it. See the comment in
  `view/frontend/layout/catalog_product_view.xml`.
- **Marketplace-grade CSP needs Magento to catch up first.** Every script and style this
  module emits goes through `SecureHtmlRenderer`, so it is nonce/hash eligible and works
  with `Magento_Csp` in restrict mode — verified with `'unsafe-inline'` removed from
  `script-src`. On 2.4.8-p5, however, removing `'unsafe-inline'` blocks 13 of Magento's OWN
  inline blocks on a Luma product page, including the RequireJS base-URL config, which takes
  the whole storefront's JavaScript down. That is a core limitation, not this module's, but
  it means a merchant cannot yet run the storefront with inline scripts fully disabled.
- **B2B display is display only.** The account price and volume table are not charged in
  the Magento cart, and the order-limit notice is not enforced there. Cart and checkout
  enforcement is a later change.
- **B2B display is product-page only.** Category and search listings show Magento's prices.
- **B2B display and the customer's email.** TackQuote matches the buyer by email address,
  which Magento does not verify on its own. Use *Only for these customer groups* (see
  *B2B display*). TackQuote's `buyerExternalId` parameter is not sent: the server reads it as
  a WordPress user id, and a Magento customer namespace needs a TackQuote change first.
- **B2B display placement on custom themes.** The placeholder sits after
  `product.info.price` in `product.info.main`. UNVERIFIED on themes that move or remove
  that container (Hyvä included); it then lands at the end of `product.info.main`.
- **Connect with TackQuote writes the default scope only.** A website-scope API key, where
  one is set, still overrides it on that website's storefront; Disconnect does not remove a
  website-scope key.
- **Connect and a custom Admin URL on another host.** TackQuote only returns the browser to
  the store's own host, so a store whose Admin runs on a separate custom admin URL
  (`admin/url/use_custom`) cannot use Connect yet; paste an API key. UNVERIFIED on a store.
- **The return needs the same Admin session.** With *Add Secret Key to URLs* on (the
  default), the return route carries that session's secret key. If the session ends during
  the 15 minutes, Magento shows its sign-in page and the connect is refused; press the
  button again.
- **Connect needs a TackQuote server with Magento Connect.** An older server answers 404,
  which the module reports as "does not support Connect with TackQuote for Magento".
- No Magento Marketplace listing or Packagist publication (see Installation).

## Requirements

- Magento Open Source / Adobe Commerce **2.4.5 – 2.4.8** (`composer.json` pins the module
  package series Magento itself uses: `magento/framework: 103.0.*`,
  `magento/module-catalog: 104.0.*`, and so on).
- PHP **8.1 – 8.4** — the union of what those Magento releases support (2.4.5 still allows
  8.1; 2.4.8 allows 8.4).
- Magento **cron** must be running (`etc/crontab.xml` warms the registration policy).

## Licence

GPL-2.0-or-later. The full text ships in the package as `LICENSE.txt`, matching the SPDX
identifier in `composer.json` — the identifier alone is not enough for Adobe Commerce
Marketplace technical review, and every Magento core module ships its licence text the same
way.

## Development tooling

`composer.json` declares the tools in `require-dev` so a reviewer can reproduce the checks
without guessing versions, and `autoload-dev` is what makes `Test/` loadable:

```bash
composer install                     # at the repository root
vendor/bin/phpcs                     # picks up ./phpcs.xml -> the Magento2 standard
vendor/bin/phpunit -c Test/Unit/phpunit.xml
node Test/Js/cart-seed.test.js       # the cart-page mapper; plain Node, no dependencies
```

`Test/` is deliberately absent from the PRODUCTION `autoload` block. That block used to map
the module root (`"TackQuote\\Quotes\\": ""`), which made
`TackQuote\Quotes\Test\Unit\Controller\Quote\SubmitTest` — and therefore PHPUnit, an
undeclared dependency — resolvable on a live store. The production map now names only the
six namespaces that ship code (`Block\`, `Controller\`, `Cron\`, `Model\`, `Observer\`,
`Plugin\`), and `Test\` lives in `autoload-dev`. **Adding a new top-level source directory
means adding it to `autoload.psr-4`**; that is the cost of not mapping the root.

That cost is not theoretical. `Plugin\` — which is the whole of quote-only enforcement — was
missing from the map at one point, and nothing catches it locally: an `app/code` install
resolves the classes anyway through the PSR-0 `""` -> `app/code/` fallback in Magento's own
root `composer.json`, so the suite and a dev store both stay green while a Marketplace
(`vendor/`) install silently loses all three guards. Check a change to this map by resolving
against the generated `vendor/composer/autoload_psr4.php`, not by running the tests.

`Test/Unit/phpunit.xml` bootstraps from Magento's own `dev/tests/unit/framework/bootstrap.php`,
so the suite has to be run from inside a Magento installation, not from this directory alone.

## Installation

Distribution authority: the public GitHub release asset is
[`tack-magento2.zip`](https://github.com/tackquote/magento-extension/releases/latest/download/tack-magento2.zip).
It is built by `scripts/package.sh` (see *Building the release zip* below); this repository
is build/source only. No Magento Marketplace or Packagist listing
is claimed.

The zip's top-level directory is `TackQuote/Quotes/`, so unzip it **into `app/code`**
(not into `app/code/TackQuote/Quotes`) — that produces
`app/code/TackQuote/Quotes/registration.php`:

```bash
unzip tack-magento2.zip -d <magento-root>/app/code
```

Then, from the Magento root:

```bash
bin/magento module:enable TackQuote_Quotes
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

In **production mode**, also deploy the module's storefront CSS/JS (it ships
`view/frontend/web/`) after `setup:di:compile` and before the final `cache:flush`:

```bash
bin/magento setup:static-content:deploy en_US   # list every locale your store views use
```

Adobe's deployment flow is compile first, then generate static view files
(<https://experienceleague.adobe.com/en/docs/commerce-operations/configuration-guide/deployment/examples/example-using-cli>).
Developer and default mode serve static files on demand, so they skip this step.

The path-repository and copy-from-checkout options below are for maintainers working
from a checkout of this repository, not for merchant distribution.

This module is **not published to Packagist or the Magento Marketplace**.

### Option A — path repository (maintainers / local staging)

In your Magento store's root `composer.json`:

```json
{
  "repositories": {
    "tackquote-quotes": {
      "type": "path",
      "url": "../path/to/magento-extension"
    }
  }
}
```

```bash
composer require tackquote/module-quotes:*
bin/magento module:enable TackQuote_Quotes
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Option B — copy into app/code

```bash
mkdir -p app/code/TackQuote/Quotes
cp -r /path/to/magento-extension/* app/code/TackQuote/Quotes/
bin/magento module:enable TackQuote_Quotes
bin/magento setup:upgrade
bin/magento setup:di:compile
bin/magento cache:flush
```

### Building the release zip

```bash
bash scripts/package.sh          # writes dist/tack-magento2.zip (pass a directory to override)
```

The zip nests the module as `TackQuote/Quotes/` and leaves out `Test/` and the repository
scaffolding (`scripts/`, `.github/`, the repo-level `LICENSE`); `LICENSE.txt` ships. Pushing a
`v*` tag runs the same script in `.github/workflows/release.yml` and attaches the zip to the
GitHub release.

### Configure

1. Go to **Stores → Configuration → TackQuote → TackQuote Settings**.
2. Set **Enable TackQuote** to Yes.
3. Set **TackQuote API Base URL** (default `https://api.tackquote.com/v1`).
4. Press **Connect with TackQuote** (above the API key field, or on TackQuote → Dashboard),
   sign in to TackQuote and approve: the key is saved for you. Or paste a TackQuote
   **API Key**, generated in TackQuote under Settings → Developer → API
   Keys (or the "Inbound API key" section of Settings → Integrations → Magento in the
   seller portal). The key needs the `quotes:write` scope — that is the only scope this
   module's endpoints enforce, so do not grant it more.
5. Click **Test connection** on that same screen (or on TackQuote → Dashboard) to confirm
   the key and base URL.
6. Under **Request a Quote button**, enable **Show quote button on product pages** and/or
   **Show "Add to Quote" button**, and optionally change the labels.
7. Save config, then visit a product page on the storefront to confirm the controls appear
   and submit successfully. TackQuote → Dashboard shows a checklist if anything is missing.

> **Connecting the other direction (TackQuote → this store).**
> The steps above cover store → TackQuote, which uses a TackQuote API key and is
> unaffected. If you also connect *this store* to TackQuote so it can read the catalog
> and create orders, create an Integration in Magento (**System → Extensions →
> Integrations**), activate it, and enter **all four** values it shows — Consumer Key,
> Consumer Secret, Access Token and Access Token Secret — under Settings → Integrations →
> Magento in TackQuote. TackQuote then **signs every request with OAuth 1.0a
> (HMAC-SHA256)** rather than sending the access token as a Bearer token
> (`authorizationHeader()` in tack
> `apps/api/src/modules/integrations/magento/magento.service.ts:546-560`, signing in
> `magento-oauth.ts`, `buildOauthHeader()` at line 84). That works on every Magento
> version with **no store setting changed**: Adobe's 2.4.4+ default that refuses an
> integration token as a standalone Bearer token
> (`oauth/consumer/enable_integration_as_bearer`, off by default) does not apply to signed
> requests, so you do **not** need to switch it on.
>
> TackQuote falls back to a Bearer token only when the three OAuth values are missing.
> Only in that case would a 2.4.4+ store need the standalone-Bearer setting, and Adobe
> turned it off because integration tokens never expire — entering all four values is the
> better fix.
> <https://developer.adobe.com/commerce/webapi/get-started/authentication/gs-authentication-token>

## File map

```
magento-extension/                              (repository root)
├── registration.php                            Module registration
├── composer.json                               Package metadata (tackquote/module-quotes)
├── LICENSE.txt                                 GPL-2.0 text (required in the package)
├── phpcs.xml                                   Magento2 coding standard for a bare `phpcs`
├── i18n/en_US.csv                              Translation source strings
│                                               (regenerate with i18n:collect-phrases)
├── etc/module.xml                              Module declaration + sequence
├── etc/config.xml                              Defaults: B2B display + price-on-request off
├── etc/acl.xml                                 Admin ACL: dashboard tree, connect, config resource
├── etc/crontab.xml                             Warms the registration policy every 5 min
├── etc/adminhtml/events.xml                    Flush the policy when settings are saved
├── etc/adminhtml/menu.xml                      TackQuote sidebar menu (Dashboard, Settings)
├── etc/adminhtml/routes.xml                    Admin route tackquote/*
├── etc/adminhtml/system.xml                    Stores > Configuration > TackQuote fields
├── etc/frontend/routes.xml                     Storefront route tackquote/*
├── etc/di.xml                                  Global: only the always-exempt fallback visitor
├── etc/frontend/di.xml                         QUOTE-ONLY GUARDS — frontend area only,
│                                               which is what keeps the admin exempt
├── etc/webapi_rest/di.xml                      Quote-only cart + order guards for REST
├── etc/webapi_soap/di.xml                      … for SOAP
├── etc/graphql/di.xml                          … for GraphQL (admin/integration tokens exempt)
├── etc/frontend/events.xml                     layout_load_before -> quote-only handles;
│                                               multishipping predispatch -> refusal
├── Model/Config.php                            Reads scoped config (enable/url/key/labels)
├── Model/Api/Client.php                        HTTP client for /v1/integrations/magento/*
├── Model/Api/StorefrontClient.php              Read client for /v1/storefront/v1/* (X-Api-Key only)
├── Model/B2bDisplay/ResponseMapper.php         TackQuote answers -> facts, fail closed
├── Model/B2bDisplay/DataProvider.php           Asks the switched-on routes, short per-customer cache
├── Model/B2bDisplay/Presenter.php              Formats money (tax basis, no conversion) + sentences
├── Model/Connect/ConnectService.php            Connect with TackQuote: start, return, disconnect
├── Model/Connect/ConnectApi.php                The Connect calls to TackQuote (fresh Curl per call)
├── Model/Connect/ConnectUrlPolicy.php          https rule + which consent URL may be opened
├── Model/Connect/PendingConnect.php            Verifier (encrypted) + state hash, flag table, single use
├── Model/Connect/KeyStore.php                  Saves the key encrypted at the default scope, clears it
├── Model/Connect/Pkce.php                      RFC 7636 verifier, state, S256 challenge
├── Model/Connect/StoreInfo.php                 What the start request says about this store
├── Model/RegistrationConfigProvider.php        Fetches + caches the seller's policy
├── Model/ProductQuoteResolver.php              Server-side SKU -> priced line item
├── Model/ProductOptionRequirement.php          "Does this product need a selection first?"
├── Model/SubmissionThrottle.php                Per-IP rate limit on the public endpoints
├── Model/QuoteRequestConfig.php                Quote page / cart button / target price / attachment settings
├── Model/ServerCapabilities.php                TackQuote ping `capabilities`, cached per store view
├── Model/QuotePayloadBuilder.php               Target prices and upload ids on the request
├── Model/AttachmentValidator.php               Count, size and real type of attached files
├── Model/IdempotencyGuard.php                  Collapses double-submits into one quote
├── Model/QuoteOnlyRules.php                    Quote-only scoping rule, pure functions
├── Model/QuoteOnlyMode.php                     "Is this visitor quote-only right now?"
├── Model/Config/Source/QuoteOnlyScope.php      "Applies to" dropdown options
├── Model/QuoteOnly/VisitorContextInterface.php "Who is asking", bound per area
├── Model/QuoteOnly/SessionVisitorContext.php   … storefront: the customer session
├── Model/QuoteOnly/ApiVisitorContext.php       … REST/SOAP/GraphQL: UserContextInterface
├── Model/QuoteOnly/NoVisitorContext.php        … everywhere else: always exempt
├── Model/QuoteOnly/ProductFlag.php             Per-product `tackquote_quote_only` flag
├── Model/QuoteOnly/WholesaleApproval.php       price-access read, 5-min cache, fails closed
├── Setup/Patch/Data/AddQuoteOnlyProductAttribute.php  Creates the per-product attribute
├── Plugin/Quote/QuoteOnlyCartGuard.php         THE REFUSAL: Quote::addProduct()
├── Plugin/Checkout/QuoteOnlyExistingCartGuard.php  Pre-existing carts: no growth
├── Plugin/Checkout/QuoteOnlyCheckoutGuard.php  Refuses the checkout route
├── Plugin/Quote/QuoteOnlySubmitGuard.php       Refuses order placement (QuoteManagement::submit)
├── Plugin/Quote/QuoteOnlyCartItemRepositoryGuard.php  API: no quantity increase on a line
├── Plugin/Pricing/QuoteOnlyPriceRender.php     "Price on request" / "Available on quote"
├── Plugin/PageCache/WholesaleApprovalContext.php  Page-cache vary value for approved buyers
├── Observer/QuoteOnlyMultishippingGuard.php    Refuses the multishipping route
├── Observer/QuoteOnlyLayoutHandle.php          Adds the quote-only layout handles
├── Block/QuoteOnlyNotice.php                   Cart-page "this store works by quote" panel
├── Block/RequestQuote.php                      Product-page trigger view model
├── Block/ListingButton.php                     Category/search tile "Add to Quote"
├── Block/QuoteList.php                         Quote-list widget + shared form view model
├── Block/QuotePage.php                         The full quote page (/tackquote/quote)
├── Block/CartQuoteButton.php                   Cart page: "Request a quote for your cart"
├── Block/B2bDisplay.php                        Product-page B2B placeholder (no customer data)
├── Block/Adminhtml/Dashboard.php               Dashboard status + setup checklist
├── Block/Adminhtml/System/Config/TestConnection.php   Inline config-screen test button
├── Block/Adminhtml/ConnectCard.php             Connect card: state, Connect, Disconnect
├── Block/Adminhtml/System/Config/ConnectButton.php    Compact Connect card in the config screen
├── Controller/Quote/Submit.php                 Storefront quote-request POST handler
├── Controller/Quote/Upload.php                 Attachment upload: validate, send to TackQuote
├── Controller/Quote/Index.php                  The full quote page (404 while switched off)
├── Controller/B2b/Display.php                  Signed-in customer's B2B data (POST, form key)
├── Controller/Adminhtml/Dashboard/Index.php    Admin dashboard page
├── Controller/Adminhtml/Connection/Test.php    Admin "Test connection" AJAX endpoint
├── Controller/Adminhtml/Connect/Start.php      Connect with TackQuote (POST, form key)
├── Controller/Adminhtml/Connect/Callback.php   Return from TackQuote (GET, secret key, state)
├── Controller/Adminhtml/Connect/Disconnect.php Disconnect (POST, form key)
├── Cron/WarmRegistrationConfig.php             Keeps the policy out of the render path
├── Cron/WarmServerCapabilities.php             Keeps the capability list out of the render path
├── Observer/FlushRegistrationConfig.php        Invalidates the policy on a settings save
├── view/frontend/
│   ├── layout/catalog_product_view.xml         Product-page triggers
│   ├── layout/catalog_category_view.xml        Listing-tile "Add to Quote"
│   ├── layout/default.xml                      Site-wide quote list + shared form + CSS
│   ├── layout/tackquote_quote_index.xml        The full quote page
│   ├── layout/checkout_cart_index.xml          Cart-page quote button (normal mode)
│   ├── layout/tackquote_quote_only.xml         Quote-only: body class (global)
│   ├── layout/tackquote_quote_only_product.xml Quote-only: removes BOTH addtocart blocks
│   ├── layout/tackquote_quote_only_cart.xml    Quote-only: cart-page notice
│   ├── templates/quote-only-notice.phtml       Cart-page notice + CTA
│   ├── templates/b2b-display.phtml             Product-page B2B placeholder + init
│   ├── templates/button.phtml                  Product-page triggers
│   ├── templates/listing-button.phtml          Listing-tile trigger
│   ├── templates/quote-list.phtml              Drawer + multi-step form markup
│   ├── templates/quote-page.phtml              The full quote page
│   ├── templates/cart-quote-button.phtml       Cart-page quote button
│   ├── web/js/quote-app.js                     RequireJS component: list, drawer, form,
│   │                                           quote page, cart copy, attachments
│   ├── web/js/cart-seed.js                     Cart items -> quote-list rows (pure; Test/Js)
│   ├── web/js/b2b-display.js                   Fills the B2B placeholder (text only)
│   └── web/css/request-quote.css               Storefront styles
└── view/adminhtml/
    ├── layout/tackquote_dashboard_index.xml    Dashboard layout
    ├── layout/adminhtml_system_config_edit.xml Loads the config-screen control stylesheet
    ├── templates/dashboard.phtml               Dashboard markup + test-connection JS
    ├── templates/system/config/test-connection.phtml   Config-screen button markup
    ├── templates/connect-card.phtml            Connect card (Dashboard + compact config)
    ├── web/css/dashboard.css                   Admin dashboard styles
    └── web/css/config-test-connection.css      "Verify" result pill (was an inline <style>)
```

## Changelog

### Unreleased

- **Quote page, cart-page button, target prices and attachments** (all off by default; new
  group *Quote page, cart button and attachments*). A full quote page at `/tackquote/quote`
  with a link from the quote list; "Request a quote for your cart" on the cart page, which
  copies the cart into the quote list without changing the cart; an optional target price
  per product, sent to TackQuote as the price the buyer asked for; and up to 3 attached
  files (PDF, JPEG, PNG, 5 MB each), checked by this store and sent to TackQuote, never
  saved in Magento. Target prices and files are sent only to a TackQuote server that lists
  them in its `ping` answer; target prices otherwise go into the note. New phrases are at the
  end of `i18n/en_US.csv`. New unit tests: `Test/Unit/Model/AttachmentValidatorTest.php`,
  `QuotePayloadBuilderTest.php`, `ServerCapabilitiesTest.php`,
  `Test/Unit/Controller/Quote/UploadTest.php`, and `Test/Js/cart-seed.test.js` (run with
  `node`).
- **Quote-only mode, WooCommerce 1.11.0 parity.**
  - **Per-product quote-only:** a new product attribute, *Quote only (TackQuote)* (Yes/No,
    default No, Product Details group), created by a data patch on `setup:upgrade`. A Yes
    product is quote-only for every storefront visitor even while the store sells normally:
    no Add to Cart on its page, "Available on quote" beside its price on tiles (whose Add to
    Cart is hidden), quote buttons shown, and cart, checkout and API refusals.
  - **"Price on request":** a new switch, off by default. For the shoppers the store-wide
    mode applies to, catalogue price boxes show "Price on request" (and no account price or
    volume table). Display only: the cart, admin and the quote request keep Magento's price.
  - **"Everyone except approved wholesale accounts":** a new *Applies to* option. A signed-in
    customer whose TackQuote wholesale application is approved keeps the cart; everyone else
    is quote-only. Read from `GET /storefront/v1/price-access`, cached 5 minutes per
    customer, failing closed; a page-cache vary value keeps an approved buyer's pages apart
    from everyone else's.
  - **REST, SOAP, GraphQL and multishipping are now guarded** (they were known limitations):
    new cart lines, quantity increases over the cart-item API, order placement and the
    multishipping route are refused for shoppers. The merchant's admin and integration
    tokens — TackQuote's quote conversion included — are exempt.
  - The admin Dashboard's *B2B features* card shows the quote-only settings. New phrases are
    in `i18n/en_US.csv`.
- **B2B display for signed-in customers** (off by default; four switches under
  *B2B Display*): the customer's TackQuote account price with the store price struck
  through, their volume pricing table, the order-limits notice and the buyer-group badge
  on the product page, read from TackQuote's `/storefront/v1` API. Loaded after the page
  through a POST, so nothing customer-specific enters the full-page cache. Display only:
  the cart and checkout are unchanged. An optional customer-group filter limits who sees
  it. The admin Dashboard shows the four switches as On/Off. New phrases are in
  `i18n/en_US.csv`.
- **Connect with TackQuote:** connect the store by signing in to TackQuote and approving,
  instead of copying an API key (Dashboard card, and beside the API key field). PKCE (S256)
  protected; the verifier stays on the server, encrypted, for 15 minutes; the key is saved
  encrypted at the default scope and tested. **Disconnect** revokes a Connect key in
  TackQuote and removes it from the store. The connection state (Connect, API key, not
  connected) is shown on the Dashboard. The key carries `quotes:write` and `buyers:write`.
  New ACL resource `TackQuote_Quotes::connect`. Needs
  https (development hosts excepted) and a TackQuote server with Magento Connect.
- **README:** the note on connecting TackQuote to this store said TackQuote sends the
  integration access token as a Bearer token and asked merchants to enable Magento's
  standalone-Bearer setting. TackQuote signs with OAuth 1.0a using all four Integration
  values, so that setting is not needed.

### 1.4.1

- **The "request received" confirmation no longer says more than TackQuote
  knows.** TackQuote now answers `awaitingApproval: true` for every quote
  request made on behalf of a company, so that a shopper typing a company name
  can no longer learn whether that company is already a customer. The
  confirmation used to say "Your company account is being reviewed", which is
  now false for a company that needs no approval. It reads "Request received —
  If your company account needs approval, we will email you when it is ready",
  and still shows the quote reference when there is one. New phrases are in
  `i18n/en_US.csv`.


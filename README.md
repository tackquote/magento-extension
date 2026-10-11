# TackQuote for Magento 2

> Part of the TackQuote integrations family. All platforms are listed on the TackQuote GitHub organization page: [github.com/tackquote](https://github.com/tackquote) (TackQuote integrations index).

A Magento 2 Composer module (`tackquote/module-quotes`, module name `TackQuote_Quotes`)
that adds B2B quoting to the storefront: a **"Request a Quote"** button on product pages, a
multi-product **quote list** with its own drawer, and an admin Overview with a connection
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
- **Signed-in customers find "Your details" filled in**: first and last name, the account
  email (read-only, as the submit controller uses the account email anyway) and the default
  billing address's telephone. They come from Magento's own customer record through a
  customer-data section, `tackquote-buyer` (`Model/CustomerData/BuyerDetails.php`), loaded
  with Magento's own sections, never from the full-page-cached form HTML (which Magento shares
  between customers of a group) and never from TackQuote. A shopper who was already signed in
  when the module was installed has never loaded that section, so the form asks for it once
  per page (`view/frontend/web/js/buyer-prefill.js`) instead of waiting for the next login.

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
  TackQuote's `ping` lists `attachments` (kept warm by cron every 5 minutes,
  `Cron/WarmServerCapabilities.php`, cached by `Model/ServerCapabilities.php`, and asked once
  by the storefront itself when the cache is cold). Any failure is
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
- **Cron keeps the policy warm; a cold cache is fetched once.** `Cron/WarmRegistrationConfig.php`
  refreshes it every 5 minutes (`etc/crontab.xml`), so a running store answers from cache.
  After a `cache:flush`, a settings save or before the first cron run, `Block/QuoteList.php`
  asks TackQuote once (`RegistrationConfigProvider::get()`, the API client's 3-second read
  timeout, a failure remembered for 60 seconds) instead of showing contact fields only until
  cron catches up. Pages served from the full-page cache do not run it at all.
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

### Admin overview

**TackQuote → Overview** (`Controller/Adminhtml/Dashboard/Index.php`,
`ViewModel/Adminhtml/Overview.php`, `ViewModel/Adminhtml/CronStatus.php`,
`view/adminhtml/templates/dashboard.phtml`, `view/adminhtml/templates/overview/actions.phtml`)
is built from the Magento admin's own components, following the Admin Design Pattern Library:

- **Page actions bar** (the standard `page-main-actions` bar, as on the core Dashboard):
  **Settings** is the page's one primary action and opens **Stores → Configuration →
  TackQuote**; **Test connection** is a secondary action and shows its answer as an admin
  message under the page title.
- **Status messages** (`message-success` / `message-warning`): whether the quote button is
  live, and a warning while Magento cron has not yet run TackQuote's jobs (the storefront
  asks TackQuote itself when nothing is cached, but cron is what keeps it fresh).
- **Last failed server check.** A capability check that TackQuote did not answer is logged at
  WARNING in `var/log/system.log` with the HTTP status and reason, and the Setup step "Magento
  cron has run" says *Last server check failed at <time>: <reason>* for a day, or until the
  next successful check of that store view (`Model/ServerCapabilities.php` `lastFailure()`).
  The cron job itself still reports success, so this line is where a failing ping shows.
- **Features waiting for the server.** Target prices, attachments, the Wholesale and Net terms
  tabs and Pay on net terms depend on TackQuote's capability list. While it is unknown for the
  default store view, each of those rows that is switched on says *Waiting for the first
  server check.*, or *Last server check failed: <reason>* after a failure, instead of the
  feature silently missing from the storefront (`CronStatus::getServerCheckNote()`).
- **Tabs**, one per settings area in the WooCommerce plugin's order, using the admin's
  horizontal tabs (`mage/backend/tabs` on `ul.tabs-horiz`, as on the core Dashboard). Only
  one area shows at a time; the widget provides the tab roles and keyboard handling.
- Inside each tab, `admin__page-section` blocks with the switches in an
  `admin__table-secondary` table: setting, state (an admin badge that never wraps) and a
  **Configure** link that opens the matching configuration group, already expanded (the URL
  fragment `#tackquote_quotes_<group>-link`, which Magento's configuration page expands on
  load). An admin without the configuration permission sees every setting but no links.

| Tab | Shows | Links to |
| --- | --- | --- |
| Connection | **Connect with TackQuote** (state, **Connect**, **Disconnect**); API base URL, whether a key is saved, the button label; the Setup checklist: TackQuote enabled, API key set, quote button on, Magento cron has run, each with the fix | Connection |
| Storefront | Quote buttons and quote page (7 switches); quote-only store; styling (accent color swatch, button style, launcher, custom CSS) | Quote buttons, Quote page and attachments, Quote-only store, Styling |
| B2B pricing | The four display switches and the two cart and checkout switches, "N of 6 on" | What customers see, Cart and checkout |
| Buyer groups | The five buyer-group rule switches | Buyer group rules |
| Forms | Wholesale account tab, Net terms tab, Pay on net terms | Application tabs, Pay on net terms |
| Orders | Accepted quote checkout on or off; where order, product and stock sync is set up (in TackQuote) | Accepted quote checkout |
| Help | Module version, setup guide, support | |

The switches are shown as the merchant set them at the default scope, even while the module
cannot act on them yet (the Setup checklist and the tab notes say why); a website can
override any of them, and the page says so.

"Magento cron has run" reads Magento_Cron's schedule collection (`cron_schedule`) for this
module's two job codes (`etc/crontab.xml`). Magento keeps finished rows for the cron
"success history" lifetime (60 minutes by default), so the step reads *To do* when cron has
not run the jobs within that time, which is also when their cached data has run out.

Styles live in `view/adminhtml/web/css/source/_module.less`, which the admin theme compiles
into its own stylesheet, so the page uses the admin's colours, type and spacing. Behaviour is
loaded through `data-mage-init` (`view/adminhtml/web/js/test-connection.js`,
`connect-card.js`, `swatch.js`); the templates carry no inline script and no inline style.

Screenshots for this section (captured during Q/A):

- [ ] Overview, Connection tab: page actions bar, status message, tabs, Connect, details, Setup
- [ ] Overview: Storefront tab with an accent color set
- [ ] Overview: B2B pricing, Buyer groups, Forms, Orders and Help tabs
- [ ] Stores → Configuration → TackQuote opened from a Configure link (group expanded)
- [ ] Storefront: styling group with a refused accent color message
- [ ] Storefront: a product page and the quote list launcher with an accent color, outlined
  buttons and the launcher bottom left

The rest of the admin:

- A top-level **TackQuote** entry in the admin sidebar (`etc/adminhtml/menu.xml`) with
  **Overview** and **Settings** items.
- **Test connection** exists in two places, both calling
  `Model\Api\Client::testConnection()` through the admin controller
  `Controller/Adminhtml/Connection/Test.php` (`POST tackquote/connection/test`):
  the Overview button, and an inline button in **Stores → Configuration → TackQuote**
  (`Block/Adminhtml/System/Config/TestConnection.php`,
  `view/adminhtml/templates/system/config/test-connection.phtml`). The configuration screen
  is where an admin actually pastes a key, so testing without leaving the page is what stops
  stores sitting misconfigured.
  The controller distinguishes *disabled*, *no key set* and *configured but rejected*,
  because those need completely different fixes.
- **Connect with TackQuote** (see the section below): a card on the Overview, and a compact
  one beside the API key field in **Stores → Configuration → TackQuote**, show how the store
  is connected (*Connected via Connect with TackQuote*, *Connected via API key* or *Not
  connected*) and offer **Connect with TackQuote** and **Disconnect**.
- Two separate ACL resources on purpose (`etc/acl.xml`): `TackQuote_Quotes::overview`
  (under a top-level `TackQuote_Quotes::tackquote`) backs the menu and connection test, so
  day-to-day TackQuote access can be granted **without** store-configuration rights;
  `TackQuote_Quotes::config` sits under `Magento_Config::config` because that is where
  Magento resolves a `system.xml` section's `<resource>`.

## Configuration

**Stores → Configuration → TackQuote → TackQuote Settings** (`etc/adminhtml/system.xml`,
read through `Model/Config.php`). The section is scoped to default/website — not per store
view.

The groups follow the WooCommerce plugin's tabs, in this order, each label starting with its
tab name: **Connection**; **Storefront:** quote buttons, quote page, cart button and
attachments, quote-only store, styling; **B2B pricing:** what signed-in customers see, cart and
checkout; **Buyer groups:** rules for signed-in customers; **Forms:** wholesale and net terms
applications, pay on net terms; **Orders:** accepted quote checkout. Each group opens with a one-line description, and a field
that only matters when a switch is on is hidden while that switch is off (`<depends>`).
**No stored path changed** in this regrouping: every value is still saved under
`tackquote_quotes/<group>/<field>` with the same group and field ids, which
`Test/Unit/Config/ConfigPathInvarianceTest.php` holds byte for byte against the path set
before it.

| Group | Field | Notes |
| --- | --- | --- |
| Connection | **Enable TackQuote** | Master switch. |
| Connection | **TackQuote API Base URL** | Default `https://api.tackquote.com/v1`. Include `/v1`, no trailing slash. |
| Connection | **Connect with TackQuote** | Button (no stored value): gets the API key below without copying it. See *Connect with TackQuote*. |
| Connection | **TackQuote API Key** | Stored encrypted (`Magento\Config\Model\Config\Backend\Encrypted`) and decrypted on read. Needs the `quotes:write` scope. |
| Connection | **Verify** | Inline "Test connection" button. |
| Storefront: quote buttons | **Show quote button on product pages** | Single-product request button. |
| Storefront: quote buttons | **Button label** | |
| Storefront: quote buttons | **Show "Add to Quote" button** | Turns on the multi-product quote list. |
| Storefront: quote buttons | **"Add to Quote" label** | |
| Storefront: quote buttons | **Show on category and search results** | Adds "Add to Quote" to listing tiles. |
| Storefront: quote buttons | **Quote list submit label** | Label on the drawer's submit button. |
| Storefront: quote page, cart button and attachments | **Enable the quote page** | Off by default. `/tackquote/quote` plus a link from the quote list. Needs "Add to Quote". |
| Storefront: quote page, cart button and attachments | **Quote page link label** | |
| Storefront: quote page, cart button and attachments | **Ask for a target price per product** | Off by default. Optional target price per row in the form and on the quote page. |
| Storefront: quote page, cart button and attachments | **Show a quote button on the cart page** | Off by default. Copies the cart into the quote list. Needs "Add to Quote". |
| Storefront: quote page, cart button and attachments | **Cart button label** | |
| Storefront: quote page, cart button and attachments | **Allow attachments on quote requests** | Off by default. Up to 3 files, PDF/JPEG/PNG, 5 MB each, sent to TackQuote. |
| Storefront: quote-only store | **Enable quote-only mode** | Off by default. See *Quote-only mode*. |
| Storefront: quote-only store | **Applies to** | Everyone / Guests only / Selected customer groups / Everyone except approved wholesale accounts. |
| Storefront: quote-only store | **Show "Price on request" instead of prices** | Off by default. For the shoppers the mode applies to. |
| Storefront: quote-only store | **Customer groups** | For *Selected customer groups*. |
| Storefront: styling | **Accent color** | Empty by default (the theme's colors). A hex color such as `#1e73be`; anything else is refused on save. See *Storefront styling*. |
| Storefront: styling | **Button style** | Filled (default) or Outlined. |
| Storefront: styling | **Quote list launcher position** / **Launcher distance from the edges (px)** | Bottom right, 20 px by default. Bottom left or right; 0 to 200 px. |
| Storefront: styling | **Launcher text** / **Launcher size** | Empty means "Quote list" (translated); at most 40 characters. Regular or Compact. |
| Storefront: styling | **Custom CSS** | Empty by default. At most 10,000 characters, no `<`. |
| Product (Catalog > Products) | **Quote only (TackQuote)** | Yes/No product attribute, default No. One product quote-only for everyone. |
| B2B pricing: what signed-in customers see | **Show account price** | Off by default. "Your account price" for a signed-in customer. Display only. |
| B2B pricing: what signed-in customers see | **Show volume pricing table** | Off by default. The customer's quantity breaks. Display only. |
| B2B pricing: what signed-in customers see | **Show order limits notice** | Off by default. "Order between X and Y units". The limits are also enforced on add to cart, cart save and order submit when **Enforce order limits** (B2B checkout) is on. |
| B2B pricing: what signed-in customers see | **Show buyer group badge** | Off by default. The customer's TackQuote buyer group. |
| B2B pricing: what signed-in customers see | **Only for these customer groups** | Empty = every signed-in customer. See *B2B display* below. |
| Buyer groups: rules for signed-in customers | **Only for these customer groups** | Empty = ask about every signed-in customer. Others count as `@GUESTS`. See *Buyer group rules*. |
| Buyer groups: rules for signed-in customers | **Hide categories by buyer group** / **Categories hidden from groups** / **Hide when the buyer group is unknown** | Off by default. `category ID: CODE, @GUESTS` lines. |
| Buyer groups: rules for signed-in customers | **Restrict payment and shipping methods by buyer group** / **Payment methods by group** / **Shipping methods by group** / **Restrict when the buyer group is unknown** | Off by default. `method: CODE, CODE` lines. |
| Buyer groups: rules for signed-in customers | **Shipping discounts by buyer group** / **Shipping discount per group** | Off by default. `CODE: percent=15 \| carriers`, `free`, `free_only`. |
| Buyer groups: rules for signed-in customers | **Apply TackQuote tax exemptions** / **Customer tax class for tax-exempt buyers** | Off by default. |
| Buyer groups: rules for signed-in customers | **Set the customer group from the buyer group** / **Customer group per buyer group** | Off by default. `CODE: customer group ID` lines. |
| Orders: accepted quote checkout | **Open accepted quotes in the checkout** | On by default. The checkout link from TackQuote's buyer portal fills the cart with the accepted quote. See *Accepted quote checkout link*. |

Every storefront control is additionally gated on the module being enabled *and* an API key
being present — offering a control that cannot submit would only produce a dead end.

## Storefront styling

Parity with the WooCommerce plugin's *Styling* and *Quote list & launcher* settings
(`includes/class-tack-settings.php`, `includes/class-tack-widget.php`), plus a button style
and a custom CSS field. Everything is optional; the defaults (`etc/config.xml`) reproduce the
look the storefront always had, and with every setting at its default the module prints no
CSS at all.

- **Blends with the theme first.** The storefront controls carry Magento's own markup
  contract, so the active theme styles them: "Request a Quote" is `action primary tocart`
  (the classes of Add to Cart) and sits first, under Add to Cart, at the same height and font;
  "Add to Quote" is `action secondary` beside it (primary when it is the only quote control);
  listing tiles use `action tocart secondary` so the theme's tile button rule sizes them like
  the tile's Add to Cart (primary look where that button is hidden by quote-only); the cart
  button is sized like "Proceed to Checkout"; the launcher is an `action primary` button; the
  drawer has the minicart look (panel border and shadow, items between rules,
  `input-text qty` boxes, the theme's close and trash icons, a large full-width
  `action primary`); the quote page is a `table-wrapper > data table` with `col name|qty|
  target|actions` cells (each with `data-th`, for the theme's phone layout), a
  `fieldset > field` message and an `actions-toolbar` with `action primary` and
  `action secondary`; the quote form keeps `fieldset > field > label/control`; the B2B price
  row is a `price-box` with `old-price` and `price` spans and the breaks a `data table`.
- **LESS, compiled with the theme's variables.** `view/frontend/web/css/source/_module.less`
  (there is no hand-written `.css` any more) is imported by Magento's LESS pipeline
  (`//@magento_import 'source/_module.less'`) into the theme's `styles-m.css`/`styles-l.css`
  for Blank and every Blank-derived theme, Luma included. It uses the theme's variables
  (`@button-primary__*`, `@button__border-radius`, `@border-color__base`, `@text__color__muted`,
  `@error__color`, `@page__background-color`, `@primary__color`, `@font-weight__*`,
  `@indent__*`, `@screen__s`/`@screen__m`, `@icon-remove`, `@icon-trash`) and mixins
  (`.lib-button-l()`, `.lib-button-s()`, `.lib-button-primary()`, `.lib-button()`,
  `.lib-button-as-link()`, `.lib-button-reset()`, `.lib-icon-font()`, `.lib-css()`,
  `.lib-font-size()`, `.lib-visually-hidden()`, `.media-width()`), so a theme that recolours
  or resizes its buttons recolours and resizes these too. Common rules sit in
  `& when (@media-common = true)`. The module's own tokens (`@tackquote__gap`,
  `@tackquote__rule-color`, `@tackquote__muted-color`, `@tackquote__error-color`,
  `@tackquote__panel-*`, `@tackquote__launcher-offset`, `@tackquote__drawer-width`) default to
  theme variables and can be overridden in a theme's `_extend.less`. After an upgrade, run
  `bin/magento setup:static-content:deploy` (production mode) or clear `pub/static/frontend`
  and `var/view_preprocessed` (developer mode) so the theme CSS is rebuilt.
- **Hyva and other non-LESS themes: theme classes only, UNVERIFIED.** A theme that does not
  run Magento's LESS pipeline does not compile `_module.less`, so only the classes above
  apply. The quote list and form also need RequireJS and `Magento_Ui/js/modal/modal`, which
  Hyva does not load; a Hyva compatibility module would be needed and none exists yet.
- **How it reaches the page.** `ViewModel/Styling.php` reads the group and hands it to two
  templates through `view/frontend/layout/default.xml`: `quote-list.phtml` (launcher text,
  side and size, as the modifier classes `tackquote-app--launcher-left` and
  `tackquote-app--launcher-compact`) and `styling.phtml`, which prints one `<style
  id="tackquote-styling">` in the page head through `SecureHtmlRenderer`, so `Magento_Csp`
  can hash it for `style-src`.
- **CSS custom properties.** `--tackquote-accent`, `--tackquote-accent-text` (black or white,
  whichever has the higher WCAG 2 contrast on the accent, the WooCommerce formula) and
  `--tackquote-launcher-offset` (default `20px` in `_module.less`, `@tackquote__launcher-offset`). The accent repaints
  this module's own buttons only (product page, listing tiles, quote list, launcher, quote
  form, quote page, cart button, quote-only notice) and the B2B account price and group
  badge; selectors are scoped with `html:root body .action…`, never `!important`, so a
  theme's or the merchant's own CSS can still override them.
- **Validated twice.** On save, `Model/Config/Backend/StylingValue.php` refuses an accent
  that is not `#rgb`/`#rrggbb`, a launcher distance outside 0 to 200, and custom CSS that
  contains `<` or is longer than 10,000 characters, naming the problem. On every read,
  `Model/Styling/StyleRules.php` checks the values again, so a value written around the
  admin form (`config:set`, a database import) is dropped rather than printed.
- **Custom CSS.** Printed after the module's own rules inside the same `<style>` element,
  with every `<` and NUL byte removed and the text cut to 10,000 characters. Inside
  `<style>` the browser only leaves the element at `</style`, so without a `<` the content
  cannot end it early or open another tag.

## Languages

`i18n/` ships English (`en_US.csv`, the source) and the seven languages the WooCommerce
plugin ships (`languages/`): German (`de_DE`), Spanish (`es_ES`), French (`fr_FR`), Italian
(`it_IT`), Japanese (`ja_JP`, the plugin's `ja`), Dutch (`nl_NL`) and Portuguese, Brazil
(`pt_BR`). Magento picks the file matching the store view's locale (**Stores → Configuration
→ General → Locale Options**), for the storefront, and the admin user's interface locale for
the admin.

- **What is translated:** every storefront phrase (buttons, the quote form and quote page,
  the quote list, B2B prices and notices, wholesale and net terms, checkout messages), the
  default button labels when the merchant leaves a label empty, the admin Overview, and the
  configuration screen's group and field labels and group descriptions. A label the merchant
  types is shown as typed.
- **Where the text comes from:** where the WooCommerce plugin's `.po` file has the same
  English phrase, its vetted translation is reused (82 phrases per language); the rest were
  translated for this module in plain merchant and shopper language.
- **Kept honest by a test:** `Test/Unit/I18n/CatalogueTest.php` fails when a locale is
  missing a phrase, has one en_US does not, repeats a phrase, leaves one empty, or changes a
  placeholder (`%1`) or HTML tag; and when a `__()`, `$t()` or Knockout `i18n:` literal, a
  `system.xml` label or group description, or a menu or ACL title is missing from
  `en_US.csv`.

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
| `GET /v1/storefront/v1/wholesale-price` | none | B2B display: the signed-in customer's account price for one SKU. B2B checkout: confirms the account's currency is the cart's (one read, cached 10 minutes). |
| `GET /v1/storefront/v1/quantity-breaks` | none | B2B display: the customer's quantity-break ladder for one SKU. |
| `GET /v1/storefront/v1/order-limits` | none | B2B display: quantity limits for one SKU. B2B checkout: the limits it enforces. |
| `GET /v1/storefront/v1/buyer-group` | none | B2B display: the customer's buyer group. |
| `GET /v1/storefront/v1/price-access` | none | Quote-only *Everyone except approved wholesale accounts*: is this signed-in customer's wholesale application approved? |
| `GET /v1/storefront/v1/wholesale-signup[/<slug>]` | none | Wholesale account tab / CMS block: the seller's application form (`Model/Forms/AccountApiClient.php`). |
| `POST /v1/storefront/v1/wholesale-upload` | `buyers:write` | One application file's raw bytes. |
| `POST /v1/storefront/v1/wholesale-signup[/<slug>]` | `buyers:write` | The wholesale application. |
| `GET /v1/storefront/v1/wholesale-application` | none | The customer's own application state (none, pending, approved, declined). |
| `POST /v1/storefront/v1/credit-application` | `buyers:write` | The net terms application. |
| `GET /v1/storefront/v1/net-terms` | none | The customer's net terms standing, for the Net terms tab and "Pay on net terms". |
| `GET /v1/integrations/magento/quote-checkout/<token>` | `quotes:write` | Accepted quote checkout link: exchange the single-use token for the quote's lines, once, server to server, `X-Api-Key` only (`Model/QuoteCheckout/CheckoutApiClient.php`). |
| `POST /v1/integrations/magento/quote-checkout/complete` | `quotes:write` | Accepted quote checkout link: the order the quoted cart became (idempotent). |
| `POST /v1/storefront-pricing/resolve` | none | B2B checkout: the account price of every cart line at its quantity, one request for up to 50 lines, `X-Api-Key` only (`apps/api/src/modules/pricing-rules/storefront-pricing.controller.ts`). |

The five `/storefront/v1` reads are TackQuote's platform-neutral storefront API
(`apps/api/src/modules/storefront-api/storefront-v1.controller.ts`), called by
`Model/Api/StorefrontClient.php` with the key in `X-Api-Key` **only** — that API refuses a
request carrying a second credential in `Authorization`. They need no scope.

That controller is separate from `MagentoController`, which is JWT-guarded and drives the
opposite direction (Tack → the store: connect, sync, checkout). Quotes are tagged with the
`source` this module sends (`magento`) plus `plugin-request`, and the store's currency is
forwarded rather than assumed.

`ping` also answers `connectedVia` (`connect` or `api_key`), how the presented key was
issued; the module records the same fact itself, so it does not depend on it. Every `ping`
carries `X-TackQuote-Quote-Checkout-Url`: the store view's https landing route for accepted
quotes, or `off` (`Model/QuoteCheckout/LandingUrl.php`, see *Accepted quote checkout link*).

Every request this module makes to TackQuote, through `Model/Api/Client.php`,
`Model/Api/StorefrontClient.php` and `Model/Forms/AccountApiClient.php`, also carries two
informational headers, like the WooCommerce plugin's (`class-tack-api-client.php`):

| Header | Value |
| --- | --- |
| `X-TackQuote-Plugin-Version` | This module's version, `etc/module.xml` `setup_version` (read through `ModuleListInterface`). Sent only when it has the `1.2.3` or `1.2.3-pre.1` shape. |
| `X-TackQuote-Site-Url` | The store's web base URL (`Store::getBaseUrl(URL_TYPE_WEB)`) for the store the request is made for, without a trailing slash. Sent only for a plain `http`/`https` URL. |

Both are built in `Model/Api/PluginHeaders.php`. They are additive and are not credentials:
`X-Api-Key` stays the only one (and `Authorization` keeps its existing role on the Magento
plugin routes only). TackQuote records them for the WooCommerce routes today; on the
`/storefront/v1` reads it ignores the site URL when `X-TackQuote-Platform: magento` is sent,
and otherwise uses it only to narrow the tenant's own WooCommerce connections, falling back to
the earlier behaviour when it matches none.

## Connect with TackQuote

Connect this store by signing in to TackQuote and approving, instead of copying an API key.
The WooCommerce plugin has the same flow; the TackQuote side is shared
(`apps/api/src/modules/integrations/storefront-connect/` and `magento/connect/` in tack).
Pasting a key keeps working exactly as before.

1. **Start.** An admin presses **Connect with TackQuote** (Overview or configuration). The
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
   The Overview says whether the test passed.
4. **Disconnect** (`Controller/Adminhtml/Connect/Disconnect.php`, POST) revokes the key in
   TackQuote when Connect issued it, then removes it from this store. A pasted key is only
   removed from this store; revoke it in TackQuote yourself.

**The return route keeps its secret key.** With *Add Secret Key to URLs* on (Magento's
default), the return route must carry `key/<secret>/` or Magento sends the browser to the
Dashboard and the one-time code is lost. Magento's backend URL model is shared and keeps its
"no secret key" switch from one `getUrl()` call to the next unless the call passes route
parameters, so `Model/Connect/StoreInfo.php` builds the return route FIRST with the key
switched on (`turnOnSecretKey()`), then the key-less Admin address, then switches the key back
on. Passing `['_nosecret' => false]` does not help: Magento tests that parameter with
`isset()`, so it turns the key off. `Test/Unit/Model/Connect/StoreInfoTest.php` pins it.

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
never loads any of those files. (The global `etc/di.xml` holds no quote-only guard: only the fallback visitor,
`Model/QuoteOnly/NoVisitorContext.php`, which is always exempt, and the "Pay on net terms"
payment wiring.) In the API areas, where the
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
(`view/frontend/web/css/source/_module.less`), because that button is hard-coded in
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

**The cart keeps showing prices, deliberately.** With "Price on request" on, a line that is
already in a cart (added before the switch, or by a shopper the quote-only scope does not
cover) still shows its price and the cart its totals. That is a decision, not a gap: whatever
can be checked out is charged what the cart says, so the cart must say it, and the WooCommerce
plugin makes the same choice (it hides product prices only). The account price is not charged
while prices are hidden (see *B2B checkout*). The field's own comment in Stores > Configuration
says the same.

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

**Display only.** Nothing in this group changes what the Magento cart or checkout charges.
The cart charges account prices and enforces order limits only when the separate *B2B
Checkout* switches are on (see *B2B checkout* below). While *Charge account prices in the
cart* is off, wherever an account price or volume table is shown the shopper also sees:
"Your account prices are shown here; the cart and checkout use the store's prices for now."
That line disappears once the cart charges account prices.

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

## Wholesale and net terms (signed-in customers)

Off by default. Three switches under *Wholesale and Net Terms Applications* and the payment
method under *Pay on Net Terms (checkout)*:

- **Wholesale account tab** (My Account). The application form you built in TackQuote,
  every field type including file uploads (PDF, JPEG or PNG, 5 MB, narrowed by the field's
  own settings; checked by this store, streamed to TackQuote, never saved in Magento).
  Afterwards the tab shows whether the application is being reviewed, approved or declined.
  The same form can sit on a CMS page:
  `{{block class="TackQuote\Quotes\Block\Account\WholesaleForm" template="TackQuote_Quotes::account/wholesale.phtml"}}`
  (optionally `slug="..."`). On a CMS page the block renders only the form, which is the same
  for every visitor, so the page cache never holds anyone's state; submitting needs a signed-in
  customer.
- **Net terms tab** (My Account). The customer's approved terms, credit limit and available
  credit, a "being reviewed" or "not approved" notice, or the application form (legal name,
  phone, tax ID, billing address, requested limit and terms, three trade references, notes).
- **Pay on net terms** (checkout). An offline payment method offered only to a signed-in
  customer whose TackQuote net terms line is active, in the order's currency, with available
  credit (or, when TackQuote does not report it, the limit) covering the order total. It is
  checked again, live, when the order is placed. The order is placed as *Pending*, with the
  terms ("Net 30") and the optional PO number on the payment, shown on the order page and
  read by TackQuote when it pulls the order. Never offered in admin order creation.

### How TackQuote knows which customer it is

The customer's account email, and their Magento customer ID with this install's own ID
(`X-TackQuote-Platform: magento`, `X-TackQuote-Store-Id`), on TackQuote's `/storefront/v1`
API with the key in `X-Api-Key` only. The install ID is a UUID created once by
`Setup/Patch/Data/CreateInstallId.php` at `bin/magento setup:upgrade`; it must not change, so
keep `tackquote_quotes/identity/install_id` when copying a database. The customer ID is sent
only to a TackQuote server whose `ping` lists `customerIdentity` (an older server would read it
as another platform's id). Credit is only ever shown for a customer you linked in TackQuote,
which approving their application from this store does; an email alone never spends a credit
line. Every value comes from the customer record Magento holds, never from the form.

A wholesale or net terms application is sent only with the Magento identity. Without it
(a server that does not list `customerIdentity`, or an install without its ID) the store
refuses the submission with "Your account is still being linked to our quoting system. Please
try again in a few minutes." rather than filing it under the email alone, where the account
tab could never find it again.

### Failure and caching

The form definition is cached 5 minutes (60 seconds after a failure). The net terms standing
is cached 60 seconds per customer, read once per request for both the tab and checkout, and
read fresh when an order is placed. Anything TackQuote cannot confirm hides the payment
method and shows the application form, never a status. Applications are limited to 5 per
10 minutes (wholesale) and 3 per 15 minutes (net terms) per visitor, below TackQuote's own
limits. UNVERIFIED on a live store: the navigation links (`customer_account.xml`), the
checkout renderer, and the order payment carrying `po_number` from the quote.

## B2B checkout (signed-in customers)

**Stores > Configuration > TackQuote > TackQuote Settings > B2B Checkout (signed-in
customers).** Makes the cart and checkout follow TackQuote, as the WooCommerce plugin does
(`class-tack-wholesale-pricing.php` `apply_cart_prices`, `class-tack-b2b-notices.php`). Two
switches, **both off by default**:

| switch | does | TackQuote route |
|---|---|---|
| Charge account prices in the cart | Each cart line is charged the customer's account price at that line's quantity | `POST /storefront-pricing/resolve`, plus one `wholesale-price` read for the currency |
| Enforce order limits | Refuses adding, changing a quantity or placing an order that breaks a product's minimum or maximum quantity; order total limits at order placement | `order-limits` |

**Who.** A signed-in customer who owns the cart, inside the *Only for these customer groups*
filter of *B2B Display*. Guests, the admin order editor and API integration tokens (TackQuote's
own quote conversion included) are never affected (`Model/Enforce/Eligibility.php`; the plugins
are declared in `etc/frontend/di.xml` and the three Web API areas only).

### Account prices

- **What is charged.** Only a price from the customer's price book, for a buyer TackQuote
  matched by email (`source: price_book` and `buyerMatched: true`), in the cart's currency.
  A catalogue list price, a pricing-rule price, an unpriced SKU or a price in another currency
  keeps Magento's price. The resolve answer names no currency, so the account's currency is
  confirmed once with the `wholesale-price` read the product page uses, asked in the cart's
  currency. Nothing is ever converted.
- **How.** The quote item's custom price (`custom_price` and `original_custom_price`), set
  before every totals collection (a `before` plugin on `Quote::collectTotals()`), so it holds
  on the cart page, the mini cart, REST and GraphQL cart totals, the checkout's
  `payment-information` call and a reorder. Not `setIsSuperMode(true)`: on the quote that
  switches stock validation off. A line this module priced carries
  `{"tackquote_account_price": <price>}` in the quote item's `additional_data`, which is
  copied to the order line; only such a line is ever re-priced or reset.
- **At order placement** TackQuote is asked again, bypassing every cache
  (`Plugin/Quote/AccountPriceSubmitPlugin.php`, a `before` plugin on
  `QuoteManagement::submit()`), and the totals are recollected if any price changed, so a
  cached price is never charged. If TackQuote cannot be reached or its answer cannot be used,
  every line goes back to the store's price, the order is placed, and the order carries the
  note "TackQuote account price could not be confirmed; store price charged."
- **Which lines.** Simple, virtual and downloadable products (a grouped product's lines are
  its simple products). Not configurable or bundle lines, whose product page shows no account
  price either, not a fractional quantity, and not a product that is quote-only by its own
  flag. Where *Price on request* hides prices from the customer, nothing is charged.
- **Tax.** TackQuote prices are net. With *Catalog Prices: Including Tax*, the cart keeps
  Magento's prices and the product page keeps its "for now" line.
- **Caching.** One request per cart change: the answer is cached for 60 seconds per store,
  customer, email, currency and every line's SKU and quantity (a hash, so no email address in
  a cache id). The currency verdict is cached for 10 minutes per store view and currency and
  cleared when the settings are saved. A failure is retried after 30 seconds; an HTTP 429
  pauses the store for 60 seconds.
- **Carts over 50 lines.** The first 50 lines are priced; the rest keep the store's price and
  the order carries the note.

### Order limits

- **Which.** The quantity bound the product page's notice states (the first `product_qty` or
  `per_product_qty` rule with a minimum or maximum) and, when the order is placed, every
  `order_total` rule in the cart's currency or with no currency, compared with the order's
  grand total. TackQuote sends no quantity step, so none is enforced.
- **When.** Adding to the cart (`Quote::addProduct()`), the cart page's update and the mini
  cart's quantity change (`Cart::updateItems()`), the Web API's quantity change
  (`CartItemRepositoryInterface::save()`), and order placement (`QuoteManagement::submit()`,
  which catches every other path). Quantities are summed per SKU across the cart's lines.
- **Wording.** "<Product>: <the notice's own sentence>" and then only what is true of the
  cart. A refused add or quantity change leaves the cart as it was, so it says what was
  attempted: "Widget: Order between 2 and 10 units of this product. You tried to add 1." (or
  "You have 4 in your cart and tried to add 20.", or "You asked for 60." for a quantity
  change). Only the order submit, where the cart really holds it, says "You have 60 in your
  cart."
- **A new cart is checked too.** The first product added to a brand-new cart is checked like
  every other add, in the currency Magento is about to give the cart (the store's current
  currency).
- **Failure refuses nothing**: if TackQuote cannot be reached, the cart is not limited
  (the WooCommerce plugin's rule), and each unchecked SKU is logged at WARNING in
  `var/log/system.log` with the SKU and the reason (for example "TackQuote answered HTTP
  503"). An empty cache is not a failure: it
  is filled by asking TackQuote before the cart change is decided. Answers are cached for 60
  seconds per customer and SKU.

## Buyer group rules (signed-in customers)

**Stores > Configuration > TackQuote > TackQuote Settings > Buyer group rules (signed-in
customers).** The WooCommerce plugin's buyer-group features (1.10.0 and 1.11.0), keyed on the
customer's TackQuote buyer group **code**. Each has its own switch and **every switch is off
by default** (`etc/config.xml`). Code: `Model/Groups/*`; settings: `Model/Groups/GroupRulesConfig.php`.

### Where the group comes from

`GET /storefront/v1/buyer-group?buyerEmail=<account email>` (the route the buyer-group badge
reads), server to server with the store's API key, read when a rule needs it and kept for
**60 seconds** per store, customer and email (`Model/Groups/BuyerGroupResolver.php`; the cache
id is a hash). A failure is remembered for 30 seconds, and an HTTP 429 pauses every lookup for
the store for 60 seconds. TackQuote throttles the route at 60 requests a minute per store.

| TackQuote answers | the rules see |
|---|---|
| `grouped` with a code | that group (codes compared without regard to case) |
| `none`, `unlinked`, `grouped` without a code, `anonymous`, a guest, a customer outside **Only for these customer groups** | `@GUESTS`: outside every group |
| anything else, an error, no answer | **unknown** |

**Never filtered:** the Magento admin, cron and CLI, and REST/SOAP/GraphQL calls made with an
admin or integration token, which includes TackQuote converting an accepted quote into an
order (`Model/Groups/Audience.php`, through the same per-area visitor quote-only mode uses).

### Rules

Rule fields take one rule per line, in the WooCommerce format; blank lines and lines starting
with `#` are ignored, and **a line that cannot be read is refused when you save**
(`Model/Groups/Config/RuleMapBackend.php`).

- **Hidden categories.** `category ID: CODE, @GUESTS` hides that category, its children and
  the products in them from those groups: removed from the top menu, from category listings
  and quick-search results, the category and product pages answer "page not found", and the
  products cannot be added to a cart. When the group is unknown, categories are shown unless
  **Hide when the buyer group is unknown** is on (then every category hidden from anyone is
  hidden). Guests never need a lookup.
- **Payment methods.** `checkmo: TRADE, GOLD` makes Check / Money order available only to
  those groups (`@GUESTS` may be listed too). A method with no line stays available to
  everyone. A customer outside every group never gets a restricted method; when the group is
  unknown, restricted methods stay available unless **Restrict when the buyer group is
  unknown** is on. A method that is withheld is also refused if a request tries to set it
  directly.
- **Shipping methods.** The same rule for carriers (`freeshipping: GOLD`) or single rates
  (`tablerate_bestway: TRADE`); a rate's own line wins over its carrier's.
- **Never empty.** If the payment or shipping lines would leave a cart with no method at all,
  every method stays available for that cart and the store log says so (WooCommerce's rule).
- **Shipping discounts.** `GOLD: percent=15` (or `percent=15 | flatrate, tablerate`),
  `TIER2: free`, `TIER3: free_only` (keep only the rates that already cost nothing, when
  there is one). Only a customer TackQuote places in a group gets a discount. Applied after
  the restrictions; shipping tax follows the discounted price.
- **Tax-exempt buyers.** When TackQuote answers `taxExempt: true`, the cart and checkout are
  taxed as the customer tax class you choose (create one that no tax rule uses). The
  customer's group is not changed, and catalogue prices shown including tax are not changed.
  Anything but an explicit `true` charges tax. **TackQuote sends `taxExempt` only for a
  customer it has linked to this store's customer account**, which happens when it approves a
  wholesale or credit application the customer made from this store; an email match alone is
  never exempted. The module names the customer the way the account tabs do (the account
  email, plus the Magento customer id with this install's id when TackQuote lists the
  `customerIdentity` capability).
- **Customer group from the buyer group.** `GOLD: 4` moves a customer TackQuote places in GOLD
  into customer group 4, on sign-in and at most every 5 minutes after. Only customers in the
  website's default group (or in a group this setting put them in) are moved; when TackQuote
  stops placing them in a mapped group they go back to the group they came from, unless
  someone changed their group by hand in between. What it did is recorded per customer in
  Magento's `flag` table; every change is written to the store log with the customer id and
  the group ids.

### How it stays out of the wrong cache

Hidden categories change public pages, so a page-cache vary value (`tackquote_buyer_group`,
`Plugin/PageCache/BuyerGroupContext.php`) keeps each **set of hidden categories** apart: a hash
of the hidden ids, empty (Magento's own cache key) when nothing is hidden. The top menu, which
Magento caches for an hour per store, gets the same value in its block-cache key. The hidden set
is decided before the page is built (`Plugin/PageCache/BuyerGroupWarmup.php`), because Magento
clears the customer session from a cacheable page once its layout is generated. Payment,
shipping and tax rules act on the cart only, which is never page-cached.

### Where each rule hooks in

| rule | interception point |
|---|---|
| menu | before-plugin on `Magento\Theme\Block\Html\Topmenu::getHtml()` after Magento_Catalog's own, plus `getCacheKeyInfo()` |
| category page | around-plugin on `Magento\Catalog\Controller\Category\View::execute()`: `noroute` |
| product page | after-plugin on `Magento\Catalog\Helper\Product::initProduct()`: `false`, so `noroute` |
| listings, search | `etc/search_request.xml` adds a `not` clause on `category_ids` to `catalog_view_container` and `quick_search_container`; bound by an after-plugin on `Magento\Catalog\Model\Layer::prepareProductCollection()` |
| Add to Cart | `catalog_product_is_salable_after` observer: not salable, so `Quote::addProduct()` refuses it |
| payment | `payment_method_is_active` observer (listing and setting a method), plus an around-plugin on `Magento\Payment\Model\MethodList::getAvailableMethods()` for "never empty" |
| shipping, discounts | after-plugin on `Magento\Shipping\Model\Shipping::collectRates()` |
| tax exemption | after-plugin on `Magento\Quote\Model\Quote::getCustomerTaxClassId()` |
| customer group | `customer_login` and `controller_action_predispatch` observers |

Storefront plugins are in `etc/frontend/di.xml`; payment, shipping, tax and Add to Cart are
also in `etc/webapi_rest`, `etc/webapi_soap` and `etc/graphql`. Nothing is declared in the
global files the admin loads.

### What is sent to TackQuote

For a signed-in customer only, when a switched-on rule needs the group: the customer's
**email address**, and, when TackQuote lists `customerIdentity`, the **Magento customer id**
with this install's id (headers `X-TackQuote-Platform` and `X-TackQuote-Store-Id`). Nothing for
guests. Nothing is written in TackQuote.

## Accepted quote checkout link

**Stores > Configuration > TackQuote > TackQuote Settings > Accepted quote checkout**, one
switch, **on by default** (nothing changes until a buyer opens a link TackQuote made for an
accepted quote). The WooCommerce plugin's quote checkout (`class-tack-quote-checkout.php`,
plugin 1.11.0), for Magento.

**The flow.**

1. On every `ping` the module tells TackQuote where it takes checkout links:
   `X-TackQuote-Quote-Checkout-Url: https://<store view base URL>tackquote/checkout/start`, or
   `off` when the switch is off, the module is not configured, or the store is not https.
   TackQuote accepts the URL only on the host the seller entered for the store connection.
2. A buyer accepts a quote in the TackQuote buyer portal and presses "Checkout in the store".
   TackQuote sends them to `/tackquote/checkout/start?token=<single-use token>`
   (`Controller/Checkout/Start.php`).
3. The module exchanges the token with TackQuote, server to server, once (TackQuote spends it
   on the first exchange). A per-visitor limit of 10 links per 10 minutes applies first.
4. The cart is emptied and filled with every quoted line, by SKU, at the quoted quantity, with
   the quoted unit price as the line's custom price (`custom_price` and
   `original_custom_price`, never super mode). All or nothing: an unknown SKU, a disabled or
   out-of-stock product, a configurable or bundle parent, or a quantity Magento would change
   leaves the cart empty and names the products (`Model/QuoteCheckout/CartBuilder.php`). A
   guest's checkout starts with the quote's email filled in.
5. The buyer lands on Magento's own checkout. Shipping, tax and payment are the store's.
6. When the order is placed the module tells TackQuote which order it became
   (`Observer/QuoteCheckoutOrderReport.php`); TackQuote links the order to the quote and, for a
   paid order that is not on net terms, marks the quote paid. If that report is lost,
   TackQuote's order sync reads the same reference from the order lines.

**What the cart may not do while it holds the quote.** Other products cannot be added
(`Plugin/Quote/QuoteCheckoutCartGuard.php`); the quoted quantities cannot be changed on the
cart page or over the Web API (`QuoteCheckoutUpdateItemsLock.php`,
`QuoteCheckoutCartItemRepositoryLock.php`); removing one quoted line removes the whole quote
(`Observer/QuoteCheckoutLineRemoved.php`). A cart that holds a quoted line but is no longer
exactly the quote (a line, quantity, price or currency changed) is never ordered
(`QuoteCheckoutSubmitGuard.php`), and the checkout page takes such a quote out of the cart and
says why (`QuoteCheckoutPageGuard.php`).

**Quote-only mode and B2B checkout.** An intact accepted quote is the one checkout a
quote-only store allows: the quote-only cart, checkout and submit guards let it through, and so
do the order-limit guards (the seller accepted those quantities). Its lines carry the quote in
the cart line's `additional_data` (`tackquote_quote_ref`, `tackquote_quoted_price`, ...,
`Model/QuoteCheckout/QuotedLine.php`), and *Charge account prices in the cart* never reprices
such a line.

**Refused, with a message.**

| Case | Why |
|---|---|
| The quote's currency is not the store's base and display currency | The custom price is read in the cart's currency; nothing is converted. |
| Catalog prices are entered including tax | Quoted prices are net (the *Charge account prices* rule). |
| An expired, used or unknown link | A page saying so, with a link back to the buyer portal when TackQuote names one. |

**Not cached.** The landing page's notice block is `cacheable="false"`; a successful link is a
redirect. The token is never put in a redirect target or a log line.

## Known limitations

- **The cart-page button copies, it does not link.** "Request a quote for your cart" copies
  the cart into the quote list once; later changes to the cart do not reach the list. Bundle
  items and items with custom options are left out (and named). UNVERIFIED on a store: a
  configurable item that also has custom options; TackQuote then cannot resolve its cart SKU
  and that line is left out of the quote.
- **A cold capability cache costs one request.** After a `cache:flush` or a change of API key
  or URL, the first storefront render that needs the list asks TackQuote's `ping` (up to 3
  seconds); a failure is remembered for 60 seconds, so an outage costs at most one timeout a
  minute per store view. Saving other TackQuote settings no longer drops the list: its cache
  entry carries a hash of the API URL and key instead of the settings cache tag.
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
  that long to reach the storefront. **If Magento cron is not running** the storefront still
  fetches the policy when its cache is cold, but nothing refreshes it within the hour, so
  check `bin/magento cron:run` on a new install.
- **The form degrades rather than breaks with no policy** — a TackQuote outage drops the
  company step and the seller's custom
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
- **B2B checkout charges price-book prices only.** TackQuote's batched resolve route does not
  say whether a pricing-rule price is the account's own, so such a price is shown on the
  product page but the cart keeps Magento's price. Needs `accountSpecific` and `currency` on
  that route (a TackQuote change).
- **B2B checkout and multishipping.** "Check Out with Multiple Addresses" places its orders
  without `QuoteManagement::submit()`, so they are charged the cart's prices (at most 60
  seconds old) without the fresh re-check, and order limits are not checked there.
- **B2B checkout: a changed price at placement is charged without a second review.** If
  TackQuote's answer changes between the cart and placement, the new price is charged. For
  payment flows that approve an amount before placement (PayPal Express review) the placed
  total can then differ from the approved one. UNVERIFIED on a store.
- **B2B checkout: GraphQL `updateCartItems`** changes a quantity without the cart-item
  repository, so an order-limit breach there is refused only when the order is placed.
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
- **Buyer group rules and the customer's email.** TackQuote matches the buyer by email
  address, which Magento confirms only with account confirmation on. Use *Only for these
  customer groups*, especially with *Set the customer group from the buyer group*: a customer
  group can carry its own prices and tax class.
- **Buyer group rules: what hidden categories do not cover yet.** Related, up-sell and
  cross-sell lists, widgets and product feeds can still name a hidden product (its page and
  Add to Cart are refused). GraphQL and REST catalogue reads are not filtered. A hidden
  product already in a cart before the rule applied is not removed (UNVERIFIED whether
  Magento then refuses it at checkout). Layered navigation counts and pager totals follow the
  search engine's answer, UNVERIFIED on a running store with OpenSearch.
- **Buyer group rules and TackQuote's throttle.** The buyer-group route allows 60 requests a
  minute per store; past that, rules see "unknown" for up to a minute (categories shown and
  restricted methods available, unless the strict settings are on).
- **Tax exemption needs a linked customer.** TackQuote sends `taxExempt` only for a customer it
  has linked to this store's account (an approved wholesale or credit application from this
  store) and only to a server that lists `customerIdentity`. UNVERIFIED on a store.
- **The customer-group mirror takes effect on the next cart totals.** A cart priced before the
  group changed keeps its prices until Magento collects its totals again.
- **Accepted quote checkout: one landing URL per TackQuote account.** The ping from the
  default store view (the capability cron) sets it; a multi-store-view store receives every
  link on that view's URL. UNVERIFIED on a store.
- **Accepted quote checkout: GraphQL `updateCartItems`** changes a quoted quantity without the
  cart-item repository, so it is not refused there; the cart is then no longer the quote and
  the order is refused. UNVERIFIED on a store.
- **Accepted quote checkout and multishipping.** "Check Out with Multiple Addresses" places its
  orders without `QuoteManagement::submit()`, so the integrity check and the order report do
  not run there; TackQuote's order sync still links those orders from their lines.
- **Accepted quote checkout: a quote's PO number is not filled in** at checkout. It stays on
  the quote in TackQuote; only the *Pay on net terms* method has a PO field here.
- **Field help text in Stores → Configuration is English.** Group and field labels, group
  descriptions, the Overview and every storefront phrase are translated; the longer help text
  under each field is not, because Magento looks a comment up by its exact text, line breaks
  and indentation included.
- **Custom CSS is the merchant's own.** It is printed on every storefront page after the
  module's rules. The module removes every `<` (so it cannot end the `<style>` element) and
  caps it at 10,000 characters, but it does not check what the CSS does: a rule can still
  hide or restyle anything on the page. Only admins with the TackQuote configuration
  permission can set it.
- **Styling and the page cache.** Styling is part of the cached page. After changing it,
  refresh the Page Cache when Magento asks (System → Cache Management).
- **The Overview shows the default scope.** A website-level override is applied on that
  website's storefront but is not shown on the Overview; the configuration page's scope
  switcher shows it.
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
scripts/check-standards.sh           # the same, failing on ANY error or warning (severity 1)
vendor/bin/phpunit -c Test/Unit/phpunit.xml
node Test/Js/cart-seed.test.js       # the cart-page mapper; plain Node, no dependencies
node Test/Js/buyer-prefill.test.js   # the signed-in prefill's one-time section reload
```

The module is held to the Magento coding standard with **zero errors and zero warnings**;
`docs/CODING_STANDARDS.md` has the policy, the commands and every suppression with its
reason.

`Test/` is deliberately absent from the PRODUCTION `autoload` block. That block used to map
the module root (`"TackQuote\\Quotes\\": ""`), which made
`TackQuote\Quotes\Test\Unit\Controller\Quote\SubmitTest` — and therefore PHPUnit, an
undeclared dependency — resolvable on a live store. The production map now names only the
namespaces that ship code (`Block\`, `Controller\`, `Cron\`, `Model\`, `Observer\`,
`Plugin\`, `Setup\`, `ViewModel\`), and `Test\` lives in `autoload-dev`. **Adding a new top-level source directory
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
4. Press **Connect with TackQuote** (above the API key field, or on TackQuote → Overview),
   sign in to TackQuote and approve: the key is saved for you. Or paste a TackQuote
   **API Key**, generated in TackQuote under Settings → Developer → API
   Keys (or the "Inbound API key" section of Settings → Integrations → Magento in the
   seller portal). The key needs the `quotes:write` scope for quote requests and the
   `buyers:write` scope for the Wholesale account and Net terms applications; Connect with
   TackQuote mints a key with exactly those two. Grant nothing more.
5. Click **Test connection** on that same screen (or on TackQuote → Overview) to confirm
   the key and base URL.
6. Under **Storefront: quote buttons**, enable **Show quote button on product pages** and/or
   **Show "Add to Quote" button**, and optionally change the labels.
7. Save config, then visit a product page on the storefront to confirm the controls appear
   and submit successfully. TackQuote → Overview shows a checklist if anything is missing.

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
├── i18n/{de_DE,es_ES,fr_FR,it_IT,ja_JP,nl_NL,pt_BR}.csv   The seven translations
├── etc/module.xml                              Module declaration + sequence
├── etc/config.xml                              Defaults: switches off, styling as it always looked
├── etc/acl.xml                                 Admin ACL: dashboard tree, connect, config resource
├── etc/crontab.xml                             Warms the registration policy every 5 min
├── etc/adminhtml/events.xml                    Flush the policy when settings are saved
├── etc/adminhtml/menu.xml                      TackQuote sidebar menu (Overview, Settings)
├── etc/adminhtml/routes.xml                    Admin route tackquote/*
├── etc/adminhtml/system.xml                    Stores > Configuration > TackQuote fields
├── etc/frontend/routes.xml                     Storefront route tackquote/*
├── etc/di.xml                                  Global: the always-exempt fallback visitor, and
│                                               the "Pay on net terms" facade + checkout config
├── etc/events.xml                              Global: the B2B checkout order note
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
├── Model/Api/PluginHeaders.php                 X-TackQuote-Plugin-Version + X-TackQuote-Site-Url
├── Model/Styling/StyleRules.php                Styling validators and the CSS they produce
├── Model/Config/Backend/StylingValue.php       Refuses a styling value it cannot use, on save
├── Model/Config/Source/{ButtonStyle,LauncherPosition,LauncherSize}.php   Styling dropdowns
├── ViewModel/Styling.php                       Styling settings for the storefront templates
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
├── Model/Enforce/Eligibility.php               B2B checkout: whose cart, as which buyer
├── Model/Enforce/PriceEnforcementSwitch.php    "Does the cart charge account prices here?"
├── Model/Enforce/AnswerMapper.php              Resolve / currency / order-total answers, fail closed
├── Model/Enforce/PriceResolver.php             One batched resolve per cart, cache, fresh at submit
├── Model/Enforce/CustomPriceApplier.php        Sets and resets the lines' custom prices
├── Model/Enforce/OrderLimitProvider.php        order-limits per SKU, cached, fails open
├── Model/Enforce/OrderLimitChecker.php         Min/max per SKU, order total at submit
├── Model/RegistrationConfigProvider.php        Fetches + caches the seller's policy
├── Model/ProductQuoteResolver.php              Server-side SKU -> priced line item
├── Model/ProductOptionRequirement.php          "Does this product need a selection first?"
├── Model/SubmissionThrottle.php                Per-IP rate limit on the public endpoints
├── Model/QuoteRequestConfig.php                Quote page / cart button / target price / attachment settings
├── Model/ServerCapabilities.php                TackQuote ping `capabilities`, cached per store view
├── Model/Forms/AccountIdentity.php            Email + Magento customer id + install id, as sent
├── Model/Forms/IdentityResolver.php           Builds it from the session/quote customer
├── Model/Forms/AccountApiClient.php           /storefront/v1 account routes (X-Api-Key only)
├── Model/Forms/FormDefinition.php             Wholesale form: normalise, answers, showIf, files
├── Model/Forms/FieldRenderer.php              One form field as escaped HTML (every type)
├── Model/Forms/WholesaleFormSource.php        Cached form definition (5 min / 60 s)
├── Model/Forms/ApplicationStatus.php          none/pending/approved/declined, fail closed
├── Model/Forms/CreditApplicationBuilder.php   Net terms application body, server bounds
├── Model/Forms/ApplicationSubmitter.php       Uploads + submits, shopper-facing outcome
├── Model/Payment/NetTermsMethod.php           "Pay on net terms" (Adapter subclass, fail closed)
├── Model/Payment/NetTermsEligibility.php      Standing -> eligible?, tab view (pure)
├── Model/Payment/NetTermsStanding.php         Cached net-terms read shared by tab + checkout
├── Model/Payment/NetTermsConfigProvider.php   checkoutConfig: PO switch + description
├── Block/Account/WholesaleForm.php            Wholesale tab / CMS block
├── Block/Account/NetTermsForm.php             Net terms tab
├── Block/Payment/NetTermsInfo.php             Order page: terms + PO number
├── Controller/Account/*.php                   Wholesale / NetTerms pages and their POSTs
├── Setup/Patch/Data/CreateInstallId.php       This install's UUID, once
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
├── Plugin/Quote/AccountPriceTotalsPlugin.php   Account prices before Quote::collectTotals()
├── Plugin/Quote/AccountPriceSubmitPlugin.php   Fresh account prices at QuoteManagement::submit()
├── Plugin/Quote/OrderLimitCartGuard.php        Order limits on Quote::addProduct()
├── Plugin/Quote/OrderLimitCartItemRepositoryGuard.php  … on an API quantity change
├── Plugin/Checkout/OrderLimitUpdateItemsGuard.php  … on the cart page's update
├── Plugin/Quote/OrderLimitSubmitGuard.php      … and at QuoteManagement::submit()
├── Observer/AccountPriceOrderComment.php       Order note when account prices were not confirmed
├── Plugin/PageCache/WholesaleApprovalContext.php  Page-cache vary value for approved buyers
├── Plugin/PageCache/BuyerGroupContext.php      Page-cache vary value: the visitor's hidden categories
├── Plugin/PageCache/BuyerGroupWarmup.php       Decides the hidden set before the page is built
├── Plugin/Catalog/TopmenuVisibility.php        Hidden categories leave the menu (+ its cache key)
├── Plugin/Catalog/CategoryViewVisibility.php   Hidden category page -> noroute
├── Plugin/Catalog/ProductPageVisibility.php    Product in a hidden category -> noroute
├── Plugin/Catalog/LayerVisibility.php          Binds the search exclusion for listings
├── Plugin/Payment/MethodListRestriction.php    Payment rules never leave a cart with no method
├── Plugin/Shipping/GroupShippingRates.php      Shipping methods and discounts by group
├── Plugin/Tax/TaxExemptCustomerClass.php       Exempt buyers taxed as the chosen class
├── Observer/PaymentMethodGroupRestriction.php  payment_method_is_active: methods by group
├── Observer/HiddenProductNotSalable.php        Hidden products cannot be added to a cart
├── Observer/GroupMirrorSync.php                Customer group from the buyer group
├── Model/Groups/                               Buyer group rules: resolver, audience, rules,
│                                               settings, catalog visibility, restrictions, mirror
├── etc/search_request.xml                      `not` clause excluding hidden categories
├── etc/{webapi_rest,webapi_soap,graphql}/events.xml  Buyer group rules: payment and Add to Cart in the APIs
├── Observer/QuoteOnlyMultishippingGuard.php    Refuses the multishipping route
├── Observer/QuoteOnlyLayoutHandle.php          Adds the quote-only layout handles
├── Block/QuoteOnlyNotice.php                   Cart-page "this store works by quote" panel
├── Block/RequestQuote.php                      Product-page trigger view model
├── Block/ListingButton.php                     Category/search tile "Add to Quote"
├── Block/QuoteList.php                         Quote-list widget + shared form view model
├── Block/QuotePage.php                         The full quote page (/tackquote/quote)
├── Block/CartQuoteButton.php                   Cart page: "Request a quote for your cart"
├── Block/B2bDisplay.php                        Product-page B2B placeholder (no customer data)
├── ViewModel/Adminhtml/Overview.php            Overview tabs, rows, deep links, setup checklist
├── ViewModel/Adminhtml/CronStatus.php          Whether Magento cron has run the module's jobs
├── Block/Adminhtml/System/Config/TestConnection.php   Inline config-screen test button
├── Block/Adminhtml/ConnectCard.php             Connect card: state, Connect, Disconnect
├── Block/Adminhtml/System/Config/ConnectButton.php    Compact Connect card in the config screen
├── Controller/Quote/Submit.php                 Storefront quote-request POST handler
├── Controller/Quote/Upload.php                 Attachment upload: validate, send to TackQuote
├── Controller/Quote/Index.php                  The full quote page (404 while switched off)
├── Controller/Checkout/Start.php               Accepted quote checkout link: token in, cart built
├── Model/QuoteCheckout/QuotedLine.php          The quote marker on a cart line (additional_data)
├── Model/QuoteCheckout/QuotedCart.php          "Is this cart exactly the accepted quote?"
├── Model/QuoteCheckout/PayloadReader.php       The exchange answer, read exactly or not at all
├── Model/QuoteCheckout/CheckoutApiClient.php   Token exchange + completion, X-Api-Key only
├── Model/QuoteCheckout/CartBuilder.php         All lines at quoted price and quantity, or none
├── Model/QuoteCheckout/CompletionReporter.php  Tells TackQuote which order the quote became
├── Model/QuoteCheckout/LandingUrl.php          The landing URL the ping advertises
├── Plugin/Quote/QuoteCheckout*.php             Mixing, quantity locks, integrity at submit and checkout
├── Observer/QuoteCheckoutOrderReport.php       sales_model_service_quote_submit_success -> report
├── Observer/QuoteCheckoutLineRemoved.php       One quoted line removed -> the quote leaves whole
├── view/frontend/layout/tackquote_checkout_start.xml  The link's notice page (not cacheable)
├── Controller/B2b/Display.php                  Signed-in customer's B2B data (POST, form key)
├── Controller/Adminhtml/Dashboard/Index.php    Admin Overview page
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
│   ├── templates/styling.phtml                 The merchant's styling, one <style> in the head
│   ├── web/js/quote-app.js                     RequireJS component: list, drawer, form,
│   │                                           quote page, cart copy, attachments
│   ├── web/js/cart-seed.js                     Cart items -> quote-list rows (pure; Test/Js)
│   ├── web/js/buyer-prefill.js                 When to reload the prefill section (Test/Js)
│   ├── web/js/b2b-display.js                   Fills the B2B placeholder (text only)
│   └── web/css/source/_module.less             Storefront styles (LESS, theme variables)
└── view/adminhtml/
    ├── layout/tackquote_dashboard_index.xml    Overview layout (page actions + content)
    ├── templates/dashboard.phtml               Overview: messages, tabs, sections, tables
    ├── templates/overview/actions.phtml        Overview page actions: Settings, Test connection
    ├── templates/system/config/test-connection.phtml   Config-screen button markup
    ├── templates/connect-card.phtml            Connect card (Overview + compact config)
    ├── web/js/test-connection.js               Overview Test connection (data-mage-init)
    ├── web/js/connect-card.js                  Connect / Disconnect posts and confirmation
    ├── web/js/swatch.js                        Paints the accent color swatch
    └── web/css/source/_module.less             Admin styles (compiled into the admin theme)
```

## Changelog

### 1.5.2 (2026-10-11)

- **PHP 8.5 added to the Composer constraint.** `composer.json` now requires
  `~8.1.0||~8.2.0||~8.3.0||~8.4.0||~8.5.0`: the Adobe Commerce Marketplace validator refuses a
  package whose PHP constraint leaves out the newest PHP the 2.4 line supports. PHPCompatibility
  reports nothing for 8.5; the unit and standards gates run on 8.1 to 8.4. No code change.

### 1.5.1 (2026-10-11)

- **Order limits on a brand-new cart (devstore Q/A finding #39).** The first product added to a
  signed-in customer's brand-new cart skipped the order-limit check: Magento gives a quote its
  currency only when it first saves it (`Quote::beforeSave()`), and the add runs before that
  save, so the cart had no currency and was treated as not enforceable. The cart's currency is
  now resolved the way `beforeSave()` resolves it (a forced currency, else the store's current
  currency), so qty 12 against a limit of 2 to 10 is refused on the first add as on every later
  one (`OrderLimitNewCartTest`). The same applies to account prices, which read the same buyer.
- **Order limits that are not checked are logged.** When TackQuote cannot answer (unreachable,
  an HTTP error, a body that is not an answer, the store's 429 pause, or a lookup that failed in
  the last 30 seconds) the cart is still not limited, as documented, and the SKU and the reason
  are now logged at WARNING. A cold cache is never treated as a failure.

### 1.5.0 (2026-10-11)

- **Fixes from the Magento devstore Q/A pass (PR #13).**
  - "Pay on net terms" at checkout now shows its description, the buyer's terms ("Net 30 terms.
    $5,000.00 available.") and the optional purchase-order field: the checkout config provider is
    registered in `etc/frontend/di.xml`, because Magento replaces a type's argument array per area
    and the global registration never reached the checkout (`NetTermsConfigProviderWiringTest`).
  - "Connect with TackQuote" finishes on stores with "Add Secret Key to URLs" on (Magento's
    default): the return route is built before the key-less dashboard URL and the secret key is
    switched back on, because `Backend\Model\Url` keeps the `_nosecret` flag between calls
    (`StoreInfoTest`).
  - Server capabilities (target prices, attachments, customer identity, store checkout) are
    fetched on demand when the cache is cold, so a `cache:flush`, a settings save or a fresh install
    no longer hides them until the next cron run; a failed check is logged at WARNING with the HTTP
    status and shown on the Overview's cron row; an unrelated settings save keeps the cache.
  - The quote form prefills a signed-in customer's name, email (read-only) and billing telephone
    from a private customer-data section (`tackquote-buyer`), loaded once for sessions that
    predate the section.
  - A wholesale application is refused with a clear message until the Magento identity is
    available; after a submission the customer returns to the Wholesale account tab with a
    success message.
  - Order-limit refusals say what was attempted instead of a cart count; the Overview's positive
    status lines are green; the Storefront rows say when a feature is still waiting for the first
    server check; README documents that the cart keeps showing prices in "Price on request" mode.

- **Admin Overview rebuilt on the Magento Admin Design Pattern Library.**
  - Real tabs (`mage/backend/tabs`, the core Dashboard's horizontal tabs) replace the anchor
    row and the seven cards; only one area shows at a time, so no half-empty cards.
  - The standard page actions bar with **Settings** as the one primary action and **Test
    connection** as a secondary one; status and test results are admin `message` components.
  - Switches are shown in `admin__table-secondary` tables (setting, state, Configure link) in
    `admin__page-section` blocks. State badges are the admin's own and never wrap; long values
    wrap between words only.
  - Styles moved to `view/adminhtml/web/css/source/_module.less` with the admin LESS
    variables: Magento's orange primary and grey secondary buttons, no custom palette.
    `dashboard.css`, `config-test-connection.css` and the layout that loaded it are removed.
  - Inline scripts replaced by RequireJS modules loaded with `data-mage-init`.
  - Data moved from `Block/Adminhtml/Dashboard.php` (removed) to
    `ViewModel/Adminhtml/Overview.php`.
  - New Setup step and warning: **Magento cron has run**. Until cron has run TackQuote's
    jobs, the Overview says that the quote form's company and custom fields and its
    attachment control appear after the first cron run (`ViewModel/Adminhtml/CronStatus.php`).
  - No configuration path changed.
- **Storefront blends with the theme.** The quote buttons, quote list drawer and launcher,
  quote page, quote form, cart button and B2B display now use the theme's own classes and are
  styled from `view/frontend/web/css/source/_module.less` with the theme's LESS variables and
  mixins; `view/frontend/web/css/request-quote.css` and its `<css>` entry in `default.xml` are
  removed. "Request a Quote" is the theme's primary button under Add to Cart, "Add to Quote" a
  secondary one beside it; the accent color's "Add to Quote" rule follows that split
  (`Model/Styling/StyleRules.php`, no config path or variable name changed). The drawer takes
  the minicart look, and the quote page cells get `col` names and `data-th` labels. Rebuild
  static content after upgrading. See *Storefront styling*.
- **B2B display waits for the customer section.** When Magento's `customer` section is empty
  and the `section_data_ids` cookie has no `customer` entry (it was never loaded in this
  session, for example after signing in through a plain form POST), `b2b-display.js` reloads
  it once before deciding the visitor is a guest.
- **Line length.** Lines over 120 characters in the account net terms and wholesale templates,
  `quote-app.js` and the Pay on net terms checkout template are wrapped; the rendered output
  and every phrase are unchanged.
- **Admin Overview, styling and seven languages** (WooCommerce 1.11.0 parity).
  - **Overview** (TackQuote → Overview, was Dashboard): a tab row and one card per settings
    area in the WooCommerce plugin's order (Connection, Storefront, B2B pricing, Buyer groups,
    Forms, Orders, Help), each showing what is on and linking straight to its configuration
    group, opened. The *B2B features* card is split into B2B pricing and Buyer groups; the
    quote-only and styling settings join the Storefront card.
    The *Accepted quote checkout* group is now labelled *Orders: accepted quote checkout*
    and linked from the Orders card.
  - **Configuration regrouped, no path changed:** groups reordered into the plugin's tab
    order, each label prefixed with its tab and given a one-line description; "Button label"
    hides while the product-page button is off and "Wholesale form slug" while the Wholesale
    tab is off. Every stored path, id and default is unchanged
    (`Test/Unit/Config/ConfigPathInvarianceTest.php`).
  - **Storefront styling** (new group, all optional, defaults unchanged): accent color, filled
    or outlined buttons, launcher position, distance from the edges, text and size, and custom
    CSS. Validated on save and again on read; see *Storefront styling*.
  - **Languages:** `de_DE`, `es_ES`, `fr_FR`, `it_IT`, `ja_JP`, `nl_NL` and `pt_BR`, every
    phrase of `en_US.csv`; the default button labels are now translated too. Stale phrases no
    source uses any more were removed from `en_US.csv`. See *Languages*.
  - **Request headers:** every request to TackQuote carries `X-TackQuote-Plugin-Version` and
    `X-TackQuote-Site-Url`, like the WooCommerce plugin. Additive; `X-Api-Key` stays the only
    credential.
  - New unit tests: `Test/Unit/Config/ConfigPathInvarianceTest.php`,
    `Test/Unit/I18n/CatalogueTest.php`, `Test/Unit/Model/Styling/StyleRulesTest.php`,
    `Test/Unit/Model/Config/Backend/StylingValueTest.php`, `Test/Unit/ViewModel/StylingTest.php`,
    `Test/Unit/Model/Api/PluginHeadersTest.php` and
    `Test/Unit/Model/Api/PluginHeadersOnRequestsTest.php`. `composer.json` maps the new
    `ViewModel\` namespace.
- **Accepted quote checkout link** (on by default; new group *Accepted quote checkout*). A buyer
  who accepts a quote in TackQuote's buyer portal can check it out in this store:
  `/tackquote/checkout/start` exchanges TackQuote's single-use link, fills the cart with the
  quoted products at the quoted prices with the quantities locked, and sends the buyer to the
  checkout; the order is reported back to TackQuote and linked to the quote. Works in
  quote-only mode (the accepted quote is the one checkout allowed) and beside B2B checkout
  (quoted lines are never repriced). The ping now carries `X-TackQuote-Quote-Checkout-Url`.
  Needs a TackQuote server that lists `quoteCheckout` in `ping`. New phrases are at the end of
  `i18n/en_US.csv`. New unit tests under `Test/Unit/Model/QuoteCheckout/`,
  `Test/Unit/Controller/Checkout/`, `Test/Unit/Plugin/Quote/QuoteCheckoutGuardsTest.php`,
  `Test/Unit/Observer/QuoteCheckoutObserversTest.php` and
  `Test/Unit/Layout/QuoteCheckoutDeclarationsTest.php`.
- **Wholesale and net terms for signed-in customers** (all off by default; new groups
  *Wholesale and Net Terms Applications* and *Pay on Net Terms (checkout)*): a Wholesale
  account tab and CMS block with the seller's TackQuote form (file uploads included) and the
  application's status; a Net terms tab with the customer's terms and credit or the
  application form; and the "Pay on net terms" checkout method with an optional PO number.
  Requires `bin/magento setup:upgrade` (a data patch creates this install's ID). Needs a
  TackQuote server that lists `customerIdentity` in `ping`. New phrases are at the end of
  `i18n/en_US.csv`. New unit tests under `Test/Unit/Model/Forms/`, `Test/Unit/Model/Payment/`
  and `Test/Unit/Setup/`.
- **B2B checkout for signed-in customers** (off by default; new group *B2B Checkout*, two
  switches). *Charge account prices in the cart*: each cart line is charged the customer's
  TackQuote price-book price at its quantity, resolved for the whole cart in one request,
  only in the cart's currency, and resolved again when the order is placed; if TackQuote
  cannot confirm it, the store's price is charged and the order notes it. Not applied when
  catalog prices include tax. *Enforce order limits*: adding to the cart, changing a quantity
  and placing an order are refused when they break the minimum or maximum quantity the product
  page states, and orders below or above an order total limit are refused. Both work on the
  storefront, REST, SOAP and GraphQL; guests, the admin and integrations are unaffected. The
  product page's "for now" line disappears once the cart charges account prices, and the
  Dashboard's *B2B features* card shows the two switches. New phrases are at the end of
  `i18n/en_US.csv`. New unit tests under `Test/Unit/Model/Enforce/`, `Test/Unit/Plugin/`,
  `Test/Unit/Observer/` and `Test/Unit/Layout/CheckoutEnforcementDeclarationsTest.php`.

- **Buyer group rules** (WooCommerce 1.10.0/1.11.0 parity; new group *Buyer group rules*, every
  switch off by default): categories hidden by TackQuote buyer group (menu, listings, search,
  category and product pages, Add to Cart), payment and shipping methods by group, shipping
  discounts per group, tax-exempt buyers (a customer tax class you choose; only for customers TackQuote has
  linked to this store), and an optional customer group set from
  the buyer group. Read from `GET /storefront/v1/buyer-group`, cached 60 seconds per customer.
  A new page-cache vary value keeps each set of hidden categories apart. The admin refuses a
  rule line it cannot read. The Dashboard's *B2B features* card shows the five switches. New
  phrases are at the end of `i18n/en_US.csv`. `composer.json` and `etc/module.xml` add
  Magento_Payment, Magento_Shipping, Magento_Tax, Magento_Theme and Magento_CatalogSearch.
  New unit tests under `Test/Unit/Model/Groups/`, `Test/Unit/Plugin/{Catalog,Payment,Shipping,Tax}/`,
  `Test/Unit/Plugin/PageCache/BuyerGroupContextTest.php` and
  `Test/Unit/Layout/BuyerGroupWiringTest.php`.
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
- **Magento coding standard: zero errors, zero warnings.** No behaviour change. `phpcs`
  with the `Magento2` standard now passes at every severity (`--severity=1`), and
  `scripts/check-standards.sh` fails on any finding so it stays that way
  (`docs/CODING_STANDARDS.md`). The one error is gone: `QuoteOnlyRules` is no longer
  `final` (a test now keeps it unextended). Every method has a one-line summary,
  long lines are wrapped, the product page reads the current product through
  `Magento\Catalog\Helper\Data` instead of the deprecated `Magento\Framework\Registry`,
  `registration.php` declares strict types, and static helpers that had an instance at
  hand became instance methods (the stateless rule classes keep documented suppressions).
  The admin Overview's class names are now dash-separated (`tq-overview-*`, `tq-connect-*`) as
  the LESS standard asks; the storefront's `tackquote-*__*` class names are unchanged, since
  merchants' custom CSS targets them.

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


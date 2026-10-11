# Coding standards

TackQuote_Quotes follows the Magento coding standard the way an Adobe Commerce Marketplace
technical review applies it, and is stricter in one respect: **zero errors and zero warnings,
at every severity.** Marketplace blocks publication only on errors; this module also refuses
warnings, so the standard can only ratchet tighter.

## The standard

- **PHP_CodeSniffer** with the `Magento2` ruleset from `magento/magento-coding-standard`
  (`require-dev`, `^40`), configured by [`phpcs.xml`](../phpcs.xml). The whole ruleset is
  included. Its file set is the ruleset's own: `php`, `phtml`, `xml`, `js`, `html`, `less`,
  `graphqls`. Test code under `Test/` is scanned too (the ruleset itself exempts tests from the
  annotation, discouraged-function and static-method sniffs).
- **Adobe's technical guidelines** for the rest (developer.adobe.com/commerce/php/
  coding-standards/technical-guidelines): constructor injection only, no ObjectManager outside
  factories, `declare(strict_types=1)` in every PHP class file and in `registration.php`,
  `$escaper` in templates, no inline scripts (`text/x-magento-init` / `data-mage-init`),
  RequireJS AMD modules with `'use strict'`, data patches instead of install scripts, an ACL
  resource for every admin route.

## Check commands

From a Magento installation with the module at `app/code/TackQuote/Quotes`, or from this
repository after `composer install`:

```bash
scripts/check-standards.sh [path/to/phpcs]     # the ratchet: fails on ANY finding
vendor/bin/phpcs --severity=1                  # the same scan, report only
php -l <file>                                  # syntax, on the lowest supported PHP (8.1/8.2)
vendor/bin/phpunit -c Test/Unit/phpunit.xml    # unit suite (needs Magento's test bootstrap)
node Test/Js/cart-seed.test.js                 # the one browser-side unit test
```

`scripts/check-standards.sh` runs phpcs through `phpcs.xml` with `--severity=1` (warnings of
every severity count), reads phpcs's JSON report, and exits 0 only when phpcs read at least
150 files and reported no error and no warning. A phpcs that cannot run, cannot find the
`Magento2` standard, or scans the wrong directory fails it too, so a broken check cannot pass
as a clean one. The script is not shipped in the release zip (`scripts/package.sh` drops
`scripts/`).

Not checkable from this repository alone, and therefore UNVERIFIED until run on a store:
`bin/magento setup:di:compile` and `setup:static-content:deploy`. Run both on a Magento 2.4
store after any change to constructor signatures, `di.xml` or the `view/` tree.

## Suppressions

Every suppression names the sniff and gives its reason on the same line. The complete list:

| Where | Sniff | Why |
|---|---|---|
| `phpcs.xml`: `etc/adminhtml/system.xml` | `Generic.Files.LineLength` | Each group `<comment>` is one translatable phrase, translated by its exact text. Wrapping it would put a newline into the `i18n/*.csv` key (pinned by `Test/Unit/I18n/CatalogueTest.php`) and every locale would fall back to English. |
| `phpcs.xml`: `view/frontend/layout/checkout_index_index.xml` | `Generic.Files.LineLength` | The payment renderer must sit at Magento_Checkout's fixed jsLayout path, 17 elements deep; the innermost lines cannot fit in 120 columns. |
| `phpcs.xml`: `view/frontend/web/css/source/_module.less` | `Magento2.Less.ClassNaming` | The storefront's `tackquote-block__element` class names are a public styling contract: merchants target them from the Custom CSS setting and their themes, the README names them, and the storefront scripts select by them. Renaming them would break every store that styles them. The admin stylesheet has no such contract and follows the sniff (`tq-overview-*`, `tq-connect-*`). |
| `Model/QuoteOnlyRules.php` (whole class) | `Magento2.Functions.StaticFunction` | Stateless pure functions over primitives, called from config, observers, blocks and plugins alike. There is nothing for an interceptor to wrap. |
| `Model/Groups/GroupRules.php` (whole class) | `Magento2.Functions.StaticFunction` | Same: the buyer-group rule parsers and predicates, pure functions. |
| `Model/Connect/ConnectUrlPolicy.php` (whole class) | `Magento2.Functions.StaticFunction` | Same: pure URL checks. |
| `Model/Enforce/AnswerMapper::lineKey()` | `Magento2.Functions.StaticFunction` | A pure key function, also used by `CustomPriceApplier`, which has no mapper instance. |
| `Model/Payment/NetTermsMethod::cleanPoNumber()` | `Magento2.Functions.StaticFunction` | A pure sanitiser, also used by `Block\Payment\NetTermsInfo`. |
| `Plugin/Quote/QuoteCheckoutUpdateItemsLock::message()` | `Magento2.Functions.StaticFunction` | One translated message shared with the Web API lock. |
| `Model/AttachmentValidator.php` (4 lines) | `Magento2.Functions.DiscouragedFunction` | `is_uploaded_file`, `filesize`, `file_get_contents` on PHP's own upload temp file: the upload check itself. |
| `Model/Connect/ConnectUrlPolicy.php` (3 lines) | `Magento2.Functions.DiscouragedFunction` | `parse_url` and two-argument `parse_str`: pure parsing, no I/O, no variables created. |
| `Model/Connect/Pkce.php` (1 line) | `Magento2.Functions.DiscouragedFunction` | `base64_encode` for RFC 7636 base64url, not obfuscation. |

Anything else is a finding to fix, not to suppress.

## Decisions worth knowing

- **`QuoteOnlyRules` is not `final`.** `Magento2.PHP.FinalImplementation` forbids `final` (it
  is an error: a final class cannot be proxied or intercepted). The intent, one copy of the
  quote-only rule, is kept by `Test/Unit/Model/QuoteOnlyRulesClosedTest.php`, which fails if
  any class in the module extends it.
- **Around plugins are used only where the original call must be replaced or repeated**, as
  Adobe's guidance asks (technical guidelines 4.1). There are five, each justified in its class
  comment: `Plugin\Checkout\QuoteOnlyCheckoutGuard` and `Plugin\Quote\QuoteCheckoutPageGuard`
  replace the checkout page's result with a redirect; `Plugin\Catalog\CategoryViewVisibility`
  answers 404 before the hidden category is loaded; `Plugin\Pricing\QuoteOnlyPriceRender` renders
  "Price on request" instead of the price; `Plugin\Payment\MethodListRestriction` asks for the
  method list a second time with the payment rules suspended. Everything else is `before` or
  `after`.
- **Templates do not declare strict types.** They are included into the block's render scope,
  not loaded as classes; Magento's own templates do not declare it either.
- **LESS follows the Magento LESS sniffs** (two spaces after `//` on own-line comments, single
  quotes, no colons or named colours in comments that the sniffs read as declarations,
  semicolon-separated mixin arguments, custom properties sorted). The storefront stylesheet was
  compiled against Magento's UI library before and after: the CSS is byte-identical apart from
  the order of two custom properties.
- **The current product comes from `Magento\Catalog\Helper\Data::getProduct()`**, not from the
  deprecated `Magento\Framework\Registry`.

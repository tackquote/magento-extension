/**
 * Turns the Magento cart, as the browser already holds it, into quote-list rows.
 *
 * Input: the `cart` customer-data section's `items` (Magento_Checkout/js/... reads the same
 * section for the minicart). Each item is built server-side by
 * Magento\Checkout\CustomerData\AbstractItem::getItemData(): `product_type` merged with
 * DefaultItem::doGetItemData() (`item_id`, `product_sku`, `product_name`, `qty`, `options`, ...).
 * Field names read from Magento 2.4-develop source, 2026-10-11.
 *
 * Output: `{lines: [{sku, name, qty, superAttribute}], skipped: [name, ...]}`. Only a SKU and a
 * quantity matter: the submit controller re-resolves name and price server-side, exactly as for
 * a row added on a product page. Nothing here touches the Magento cart; the list and the cart
 * stay separate.
 *
 * WHAT IS SKIPPED, and named back to the shopper rather than dropped quietly:
 *   - bundle and gift-card items: their cart SKU is assembled from the chosen options
 *     ("SKU-opt1-opt2", Magento\Catalog\Model\Product\Type\AbstractType::getSku()), which no
 *     product carries, so it cannot be re-resolved;
 *   - a simple, virtual or downloadable item WITH custom options: same reason (the options are
 *     appended to its SKU), and the quote could not say which options were chosen.
 * A configurable item is kept: its cart SKU is the chosen child's SKU, which the server resolves
 * directly. UNVERIFIED on a store with a configurable that ALSO has custom options; the server
 * then fails to resolve the SKU and leaves that line out.
 *
 * No dependencies, and written so it runs under RequireJS on the storefront and under plain
 * Node for its unit test (Test/Js/cart-seed.test.js).
 *
 * @package TackQuote_Quotes
 */
(function (factory) {
    'use strict';

    if (typeof define === 'function' && define.amd) {
        define([], factory);
    } else if (typeof module === 'object' && module.exports) {
        module.exports = factory();
    }
}(function () {
    'use strict';

    var KEEP_TYPES = ['simple', 'virtual', 'downloadable', 'configurable'],
        MAX_QTY = 1000000;

    /**
     * @param {*} value
     * @return {Boolean}
     */
    function hasOptions(value) {
        return Array.isArray(value) && value.length > 0;
    }

    /**
     * @param {Array} items The `cart` section's `items`.
     * @param {Number} maxLines Rows the quote list can still take.
     * @return {{lines: Array, skipped: Array}}
     */
    return function cartSeed(items, maxLines) {
        var lines = [],
            skipped = [],
            limit = typeof maxLines === 'number' && maxLines >= 0 ? maxLines : 50;

        (Array.isArray(items) ? items : []).forEach(function (item) {
            var type, sku, name, qty;

            if (!item || typeof item !== 'object') {
                return;
            }

            type = String(item.product_type || '');
            sku = typeof item.product_sku === 'string' ? item.product_sku : '';
            name = typeof item.product_name === 'string' && item.product_name !== '' ? item.product_name : sku;
            qty = Math.floor(Number(item.qty));

            if (sku === '' || KEEP_TYPES.indexOf(type) === -1 ||
                (type !== 'configurable' && hasOptions(item.options))) {
                skipped.push(name || type);

                return;
            }

            if (!isFinite(qty) || qty < 1) {
                qty = 1;
            }

            if (lines.length >= limit) {
                skipped.push(name);

                return;
            }

            lines.push({
                sku: sku,
                name: name,
                qty: Math.min(qty, MAX_QTY),
                superAttribute: {}
            });
        });

        return {lines: lines, skipped: skipped};
    };
}));

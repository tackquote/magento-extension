/**
 * Product-page B2B display: fills the placeholder from Controller/B2b/Display.php.
 *
 * PRIVATE CONTENT — the page this runs on is full-page cached, so the signed-in
 * customer's data is fetched here, after load, the way Adobe's private-content guidance
 * describes (developer.adobe.com/commerce/php/development/cache/page/private-content).
 * Magento's `customer` customer-data section tells us whether anyone is signed in, so a
 * guest's page view costs no request at all. The section can lag behind the session, so
 * it only decides whether to ASK; the controller decides what is true. When the section
 * is empty AND `section_data_ids` has no `customer` entry (the section was never loaded in
 * this session), it is reloaded once before giving up.
 *
 * Native fetch() rather than jQuery.ajax on purpose: Magento_Customer/js/customer-data
 * listens to jQuery's ajaxComplete and reloads customer sections after POSTs it sees.
 * This read-only POST changes nothing, so it should not trigger that reload.
 * UNVERIFIED on a store: which sections a jQuery POST to this route would have
 * invalidated; fetch() avoids the question.
 *
 * TEXT ONLY — every string arrives finished and translated from the server and is
 * inserted with textContent. Nothing from the response is ever parsed as HTML.
 *
 * FAIL CLOSED — any failure leaves the placeholder hidden, so the shopper sees the
 * product page exactly as Magento renders it.
 *
 * @package TackQuote_Quotes
 */
define([
    'Magento_Customer/js/customer-data'
], function (customerData) {
    'use strict';

    /**
     * The form_key cookie (Magento_PageCache keeps it per session). The page HTML is
     * cached, so a key baked into it would belong to another visitor.
     *
     * @return {String}
     */
    function formKey() {
        var match = document.cookie.match(/(?:^|;\s*)form_key=([^;]*)/);

        return match ? decodeURIComponent(match[1]) : '';
    }

    /**
     * @param {String} tag
     * @param {String} className
     * @param {String} [text]
     * @return {HTMLElement}
     */
    function el(tag, className, text) {
        var node = document.createElement(tag);

        if (className) {
            node.className = className;
        }

        if (typeof text === 'string') {
            node.textContent = text;
        }

        return node;
    }

    /**
     * @param {*} value
     * @return {Boolean}
     */
    function isText(value) {
        return typeof value === 'string' && value !== '';
    }

    /**
     * @param {Object} group
     * @return {HTMLElement|null}
     */
    function renderGroup(group) {
        var badge;

        if (!group || !isText(group.name)) {
            return null;
        }

        badge = el('p', 'tackquote-b2b__group');
        badge.appendChild(el('span', 'tackquote-b2b__sr', (group.label || '') + ': '));
        badge.appendChild(el('span', 'tackquote-b2b__group-name', group.name));

        return badge;
    }

    /**
     * @param {Object} price
     * @return {HTMLElement|null}
     */
    function renderPrice(price) {
        var row, del, ins;

        if (!price || !isText(price.price)) {
            return null;
        }

        // `.price-box` with `.old-price` and `.price` spans, so the theme's price typography
        // applies (the same classes the product page's own price uses).
        row = el('p', 'price-box tackquote-b2b__price');

        if (isText(price.storePrice)) {
            row.appendChild(el('span', 'tackquote-b2b__sr', (price.storePriceLabel || '') + ': '));
            del = el('del', 'old-price tackquote-b2b__store-price');
            del.appendChild(el('span', 'price', price.storePrice));
            row.appendChild(del);
            row.appendChild(document.createTextNode(' '));
        }

        row.appendChild(el('span', 'tackquote-b2b__sr', (price.label || '') + ': '));
        ins = el('ins', 'tackquote-b2b__account-price');
        ins.appendChild(el('span', 'price', price.price));
        row.appendChild(ins);
        row.appendChild(document.createTextNode(' '));
        row.appendChild(el('span', 'tackquote-b2b__label', price.label || ''));
        row.lastChild.setAttribute('aria-hidden', 'true');

        return row;
    }

    /**
     * @param {Object} breaks
     * @return {HTMLElement|null}
     */
    function renderBreaks(breaks) {
        var table, head, headRow, body;

        if (!breaks || !Array.isArray(breaks.rows) || breaks.rows.length < 2) {
            return null;
        }

        table = el('table', 'data table tackquote-b2b__breaks');
        table.appendChild(el('caption', 'tackquote-b2b__breaks-caption', breaks.caption || ''));
        head = el('thead');
        headRow = el('tr');
        headRow.appendChild(el('th', '', breaks.quantityHeading || ''));
        headRow.appendChild(el('th', '', breaks.priceHeading || ''));
        headRow.children[0].setAttribute('scope', 'col');
        headRow.children[1].setAttribute('scope', 'col');
        head.appendChild(headRow);
        table.appendChild(head);

        body = el('tbody');
        breaks.rows.forEach(function (row) {
            var tr;

            if (!row || !isText(row.quantity) || !isText(row.price)) {
                return;
            }

            tr = el('tr');
            tr.appendChild(el('th', '', row.quantity));
            tr.children[0].setAttribute('scope', 'row');
            tr.appendChild(el('td', '', row.price));
            body.appendChild(tr);
        });
        table.appendChild(body);

        return body.children.length >= 2 ? table : null;
    }

    /**
     * @param {Object} limit
     * @return {HTMLElement|null}
     */
    function renderLimit(limit) {
        return limit && isText(limit.text) ? el('p', 'tackquote-b2b__limit', limit.text) : null;
    }

    /**
     * @param {HTMLElement} root
     * @param {Object} data
     */
    function render(root, data) {
        var parts;

        if (!data || typeof data !== 'object') {
            return;
        }

        parts = [
            renderGroup(data.buyerGroup),
            renderPrice(data.accountPrice),
            renderLimit(data.orderLimit),
            renderBreaks(data.quantityBreaks)
        ].filter(Boolean);

        // The honest line about which price checkout uses, only beside a price or table
        // that actually rendered.
        if (data.cartNote && isText(data.cartNote.text) && parts.some(function (part) {
            return part.className.indexOf('tackquote-b2b__price') !== -1 ||
                part.className.indexOf('tackquote-b2b__breaks') !== -1;
        })) {
            parts.push(el('p', 'tackquote-b2b__note', data.cartNote.text));
        }

        if (!parts.length) {
            return;
        }

        while (root.firstChild) {
            root.removeChild(root.firstChild);
        }

        parts.forEach(function (part) {
            root.appendChild(part);
        });
        root.hidden = false;
    }

    /**
     * Whether Magento's `section_data_ids` cookie already records a `customer` section.
     * When it does not, the browser never loaded that section in this session (for example
     * after an account was created or signed in through a plain form POST that
     * customer-data.js did not see), so an empty section proves nothing about the visitor.
     *
     * @return {Boolean}
     */
    function customerSectionKnown() {
        var match = document.cookie.match(/(?:^|;\s*)section_data_ids=([^;]*)/),
            ids;

        if (!match) {
            return false;
        }

        try {
            ids = JSON.parse(decodeURIComponent(match[1]));
        } catch (e) {
            return false;
        }

        return !!ids && typeof ids === 'object' && Object.prototype.hasOwnProperty.call(ids, 'customer');
    }

    /**
     * @param {HTMLElement} root
     * @param {Object} config
     */
    function load(root, config) {
        var key = formKey(),
            body;

        if (!key || !window.fetch || !window.URLSearchParams) {
            return;
        }

        body = new window.URLSearchParams();
        body.append('product_id', String(config.productId));
        body.append('form_key', key);

        window.fetch(config.endpoint, {
            method: 'POST',
            credentials: 'same-origin',
            headers: {
                'Accept': 'application/json',
                'X-Requested-With': 'XMLHttpRequest'
            },
            body: body
        }).then(function (response) {
            return response.ok ? response.json() : null;
        }).then(function (data) {
            render(root, data);
        }).catch(function () {
            // Fail closed: the page stays as Magento rendered it.
        });
    }

    return function (config, element) {
        var root = element,
            customer = customerData.get('customer'),
            asked = false,
            subscription;

        if (!root || !config || !config.endpoint || !(config.productId > 0)) {
            return;
        }

        /**
         * Ask once, as soon as the customer section says someone is signed in.
         *
         * @param {Object} data
         */
        function maybeLoad(data) {
            if (asked || !data || !data.firstname) {
                return;
            }

            asked = true;

            if (subscription) {
                subscription.dispose();
            }

            load(root, config);
        }

        subscription = customer.subscribe(maybeLoad);
        maybeLoad(customer());

        // The section is empty and was never loaded in this session: reload it once (one
        // GET to /customer/section/load), then decide. A guest whose section is already
        // recorded costs no request; the subscription above picks up the reloaded data.
        if (!asked && !customerSectionKnown()) {
            customerData.reload(['customer'], false);
        }
    };
});

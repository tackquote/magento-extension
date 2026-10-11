/**
 * When to (re)read the `tackquote-buyer` customer-data section for the quote form's prefill.
 *
 * The section (Model\CustomerData\BuyerDetails) is normally loaded with Magento's own sections
 * when they are invalidated, which happens on login. A shopper who was ALREADY signed in when
 * the module (or this section) was installed never triggers that: Magento_Customer's
 * customer-data.js requests a section only when it is invalidated or its cookie timestamp
 * disagrees with storage, and a section that has never been stored has neither. So the section
 * reads `{}` (no `data_id`) and only the first name, from Magento's own `customer` section, is
 * filled in, until that shopper signs in again.
 *
 * The prefill therefore asks for the section itself, ONCE per page, when the `customer` section
 * says someone is signed in (a first name) and the buyer section has never been loaded (no
 * `data_id`; a loaded section always carries one, even an empty guest answer), and applies the
 * details again when the reload answers. `customerData.reload(names, false)` is Magento's own
 * call for this (Magento_Customer/js/customer-data, read in 2.4.8-p5); it returns a jQuery
 * promise.
 *
 * No dependencies, and written so it runs under RequireJS on the storefront and under plain
 * Node for its unit test (Test/Js/buyer-prefill.test.js).
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

    var SECTION = 'tackquote-buyer';

    /**
     * @param {Object} customerData Magento_Customer/js/customer-data (get(name)() and reload()).
     * @param {Function} apply Called with (buyer, customer) every time the details are applied.
     * @return {Function} Run the prefill: apply now, and reload the section once if needed.
     */
    return function (customerData, apply) {
        var reloaded = false;

        /**
         * @param {String} name
         * @return {Object}
         */
        function read(name) {
            var value = customerData.get(name)();

            return value && typeof value === 'object' ? value : {};
        }

        return function () {
            var buyer = read(SECTION),
                customer = read('customer'),
                pending;

            apply(buyer, customer);

            if (reloaded || buyer.data_id || !customer.firstname) {
                return;
            }

            reloaded = true;
            pending = customerData.reload([SECTION], false);

            if (pending && typeof pending.done === 'function') {
                pending.done(function () {
                    apply(read(SECTION), read('customer'));
                });
            }
        };
    };
}));

/**
 * "Pay on net terms" at checkout: the method, its description and the optional PO number.
 *
 * The PO number goes out as the payment's own `po_number` (PaymentInterface), which
 * Model\Payment\NetTermsMethod::assignData() stores. Nothing here decides whether the method
 * is offered; the server does, per quote.
 */
define([
    'ko',
    'Magento_Checkout/js/view/payment/default'
], function (ko, Component) {
    'use strict';

    var settings = function () {
        var payment = window.checkoutConfig && window.checkoutConfig.payment;

        return payment && payment.tackquote_net_terms ? payment.tackquote_net_terms : {};
    };

    return Component.extend({
        defaults: {
            template: 'TackQuote_Quotes/payment/net-terms',
            poNumber: ''
        },

        initObservable: function () {
            this._super().observe(['poNumber']);

            return this;
        },

        /** @returns {Boolean} */
        isPoNumberEnabled: function () {
            return settings().poNumber === true;
        },

        /** @returns {Number} */
        getPoMaxLength: function () {
            return settings().poMaxLength || 64;
        },

        /** @returns {String} */
        getDescription: function () {
            return settings().description || '';
        },

        /** @returns {Object} */
        getData: function () {
            var po = this.isPoNumberEnabled() ? String(this.poNumber() || '').trim() : '';

            return {
                method: this.item.method,
                'po_number': po.substring(0, this.getPoMaxLength()) || null,
                'additional_data': null
            };
        }
    });
});

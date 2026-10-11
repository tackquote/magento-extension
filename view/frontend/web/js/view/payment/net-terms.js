/**
 * Registers the "Pay on net terms" (tackquote_net_terms) renderer with the checkout.
 */
define([
    'uiComponent',
    'Magento_Checkout/js/model/payment/renderer-list'
], function (Component, rendererList) {
    'use strict';

    rendererList.push({
        type: 'tackquote_net_terms',
        component: 'TackQuote_Quotes/js/view/payment/method-renderer/net-terms'
    });

    return Component.extend({});
});

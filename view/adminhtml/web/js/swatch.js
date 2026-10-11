/**
 * Paints the accent colour swatch on the TackQuote Overview (Storefront tab, Styling).
 *
 * The colour was validated as a hex value by Model\Styling\StyleRules and again by
 * ViewModel\Adminhtml\Overview::isSwatch(); it is checked once more here before use. Setting
 * it through the CSSOM keeps the template free of inline style attributes.
 */
define([], function () {
    'use strict';

    return function (config, element) {
        if (typeof config.color === 'string' && /^#[0-9a-f]{3}(?:[0-9a-f]{3})?$/.test(config.color)) {
            element.style.backgroundColor = config.color;
        }
    };
});

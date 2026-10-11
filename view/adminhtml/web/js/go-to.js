/**
 * A page-action <button> that opens an admin URL, as the admin's own buttons do with
 * setLocation(), loaded through data-mage-init instead of an inline onclick handler.
 */
define([], function () {
    'use strict';

    return function (config, button) {
        button.addEventListener('click', function () {
            if (typeof config.url === 'string' && config.url !== '') {
                window.location.href = config.url;
            }
        });
    };
});

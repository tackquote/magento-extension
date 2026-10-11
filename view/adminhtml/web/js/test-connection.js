/**
 * "Test connection" on the TackQuote Overview (view/adminhtml/templates/overview/actions.phtml).
 *
 * Posts to the admin AJAX endpoint (Controller/Adminhtml/Connection/Test.php) and shows the
 * answer as a standard admin message (message-success / message-error) in the target element.
 * Loaded through data-mage-init, so the page carries no inline script for Magento_Csp to
 * allow.
 */
define(['jquery', 'mage/translate'], function ($, $t) {
    'use strict';

    /**
     * @param {Object} config
     * @param {HTMLElement} button
     */
    return function (config, button) {
        var out = document.querySelector(config.target),
            text = out ? out.querySelector('[data-role="text"]') : null;

        if (!out || !text || !config.url) {
            return;
        }

        /**
         * @param {String} type notice | success | error
         * @param {String} message
         */
        function show(type, message) {
            out.className = 'message message-' + type + ' ' + type;
            text.textContent = message;
            out.hidden = false;
        }

        button.addEventListener('click', function () {
            button.disabled = true;
            show('notice', $t('Testing…'));

            $.ajax({
                url: config.url,
                type: 'POST',
                dataType: 'json',
                data: {
                    'form_key': window.FORM_KEY
                }
            }).done(function (data) {
                show(data && data.success ? 'success' : 'error', (data && data.message) || $t('Unexpected response.'));
            }).fail(function () {
                show('error', $t('Could not reach the server.'));
            }).always(function () {
                button.disabled = false;
            });
        });
    };
});

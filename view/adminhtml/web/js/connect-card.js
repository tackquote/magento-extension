/**
 * "Connect with TackQuote" card (view/adminhtml/templates/connect-card.phtml).
 *
 * Full mode (Overview): the card holds two real POST forms; Disconnect asks for confirmation.
 * Compact mode (inside the configuration <form>, where a nested form is not allowed): buttons
 * that build and submit the same POST on click, with the admin form key.
 *
 * Loaded through x-magento-init on the card, so the page carries no inline script for
 * Magento_Csp to allow.
 */
define([], function () {
    'use strict';

    /**
     * @param {String} url
     */
    function post(url) {
        var form = document.createElement('form'),
            key = document.createElement('input');

        form.method = 'post';
        form.action = url;
        key.type = 'hidden';
        key.name = 'form_key';
        key.value = window.FORM_KEY || '';
        form.appendChild(key);
        document.body.appendChild(form);
        form.submit();
    }

    /**
     * @param {Object} config
     * @param {HTMLElement} card
     */
    return function (config, card) {
        card.querySelectorAll('.tq-connect__post').forEach(function (btn) {
            btn.addEventListener('click', function () {
                var message = btn.getAttribute('data-confirm');

                if (message && !window.confirm(message)) {
                    return;
                }
                btn.disabled = true;
                post(btn.getAttribute('data-url'));
            });
        });

        card.querySelectorAll('form.tq-connect__disconnect').forEach(function (form) {
            form.addEventListener('submit', function (event) {
                var message = form.getAttribute('data-confirm');

                if (message && !window.confirm(message)) {
                    event.preventDefault();
                }
            });
        });
    };
});

/**
 * TackQuote storefront app: quote list, drawer, and the shared multi-step quote form.
 *
 * One component owns all of it because there is one form per page (rendered by
 * Block\QuoteList in before.body.end) serving two entry points — "Request a Quote" on a
 * product page, which quotes just that product, and the quote list, which quotes
 * everything collected across the store.
 *
 * The list is held in localStorage and NEVER in the Magento cart: quoting must not touch
 * stock, cart totals or checkout, and Magento's own cart is internally a "quote"
 * (Magento_Quote). The WooCommerce plugin made the same choice for the same reasons.
 *
 * SECURITY — the browser stores only a SKU, a display name and a quantity. Price is never
 * stored or sent; the controller re-resolves every product server-side. localStorage is
 * fully editable by the shopper, so a posted price would let anyone quote themselves any
 * amount. The optional TARGET price is the one number the shopper types: it is context for
 * the seller ("Buyer asked for …"), never the line price, and the server validates it.
 *
 * Also wired here, because they feed the same list and the same form: the full quote page
 * (Block\QuotePage), the cart page's "Request a quote for your cart" (cart-seed.js) and
 * attachments (Controller\Quote\Upload). Each is off unless the merchant switched it on.
 *
 * @package TackQuote_Quotes
 */
define([
    'jquery',
    'underscore',
    'Magento_Ui/js/modal/modal',
    'Magento_Customer/js/customer-data',
    'mage/translate',
    'TackQuote_Quotes/js/cart-seed'
], function ($, _, modal, customerData, $t, cartSeed) {
    'use strict';

    var STORAGE_KEY = 'tack_quote_list',
        MAX_ITEMS = 50,
        TARGET_PATTERN = /^\d{0,13}([.,]\d{0,4})?$/;

    /**
     * A target price as typed, kept only when it can be a price (digits, one decimal
     * separator, up to four decimals). The server re-validates; this keeps a stray paste out
     * of localStorage.
     *
     * @param {*} value
     * @return {String}
     */
    function cleanTarget(value) {
        var text = typeof value === 'string' || typeof value === 'number' ? $.trim(String(value)) : '';

        return TARGET_PATTERN.test(text) ? text : '';
    }

    /**
     * Substitute %1 in a translated phrase.
     *
     * Phrases carry a placeholder instead of being built by concatenation. Concatenation
     * handed translators dangling fragments ("Quantity for ") that mean nothing on their
     * own, and it hard-codes English word order, so any language that puts the subject
     * first could not be rendered correctly at all. It also kept the fragments out of
     * i18n/en_US.csv in a form anybody could translate.
     *
     * The substitution goes through a replacer FUNCTION deliberately: passing a string to
     * String.replace() makes `$&`, `$'` and `$1` inside it act as backreferences, so a
     * product genuinely named "Bolt $& Nut" would come out mangled. A function's return
     * value is taken verbatim.
     *
     * @param {String} phrase Translated phrase containing %1.
     * @param {String} value
     * @return {String}
     */
    function fill(phrase, value) {
        return phrase.replace('%1', function () {
            return value;
        });
    }

    /**
     * The form key must be read at submit time, never from server-rendered HTML: pages are
     * full-page-cached, so a baked-in key is stale for every visitor.
     * Magento_PageCache's form-key-provider.js fills the input from the form_key cookie.
     *
     * @param {String} name
     * @return {String}
     */
    function getCookie(name) {
        var parts = document.cookie.split(';'),
            prefix = name + '=',
            i,
            part;

        for (i = 0; i < parts.length; i++) {
            part = parts[i].replace(/^\s+/, '');

            if (part.indexOf(prefix) === 0) {
                return decodeURIComponent(part.substring(prefix.length));
            }
        }

        return '';
    }

    /**
     * localStorage can throw (Safari private browsing, quota) and can contain anything the
     * shopper typed into devtools. Every read is defensive; a corrupt list degrades to an
     * empty one rather than breaking the page.
     *
     * @return {Array}
     */
    function readList() {
        var raw, parsed;

        try {
            raw = window.localStorage.getItem(STORAGE_KEY);
        } catch (e) {
            return [];
        }

        if (!raw) {
            return [];
        }

        try {
            parsed = JSON.parse(raw);
        } catch (e) {
            return [];
        }

        if (!_.isArray(parsed)) {
            return [];
        }

        return parsed
            .filter(function (row) {
                return row && typeof row.sku === 'string' && row.sku !== '';
            })
            .map(function (row) {
                return {
                    sku: String(row.sku),
                    name: typeof row.name === 'string' ? row.name : row.sku,
                    qty: Math.max(1, parseInt(row.qty, 10) || 1),
                    // Chosen variant, e.g. {"144":"166"}. Without carrying this the
                    // server resolves the PARENT sku at the cheapest variant's price.
                    superAttribute: _.isObject(row.superAttribute) ? row.superAttribute : {},
                    targetPrice: cleanTarget(row.targetPrice)
                };
            })
            .slice(0, MAX_ITEMS);
    }

    /**
     * @param {Array} list
     */
    function writeList(list) {
        try {
            window.localStorage.setItem(STORAGE_KEY, JSON.stringify(list));
        } catch (e) {
            // Storage unavailable or full: the in-memory list still works for this page.
        }
    }

    return function (config, element) {
        var $root = $(element),
            $modal = $root.find('[data-role="tackquote-modal"]'),
            $form = $root.find('.tackquote-form'),
            $error = $root.find('.tackquote-modal-error'),
            $done = $root.find('.tackquote-done'),
            $steps = $root.find('.tackquote-step'),
            $stepList = $root.find('.tackquote-steps'),
            $markers = $root.find('[data-step-marker]'),
            $next = $root.find('.tackquote-next'),
            $back = $root.find('.tackquote-back'),
            $submit = $root.find('.tackquote-submit-btn'),
            $toolbar = $root.find('.tackquote-actions'),
            $review = $root.find('[data-role="tackquote-review"]'),
            $widget = $root.find('[data-role="tackquote-widget"]'),
            $drawer = $root.find('[data-role="tackquote-drawer"]'),
            $items = $root.find('[data-role="tackquote-items"]'),
            $empty = $root.find('[data-role="tackquote-empty"]'),
            $count = $root.find('[data-role="tackquote-count"]'),
            companyMode = $root.data('company-mode'),
            submitUrl = $root.data('submit-url'),
            stepOrder = [],
            current = 0,
            // 'single' quotes the product whose page we are on; 'list' quotes everything
            // collected. Set when the form is opened.
            mode = 'single',
            singleItem = null,
            extras = config.extras || {},
            targetsOn = extras.targetPrice === true,
            attachCfg = extras.attachments || null,
            // Files uploaded for the request being built: [{uploadId, name}], plus the guest
            // token TackQuote issued for them. Never stored: a reload starts a new request.
            attachments = [],
            uploadToken = '',
            uploading = false,
            $files = $root.find('[data-role="tackquote-files"]'),
            $fileList = $root.find('[data-role="tackquote-file-list"]'),
            $fileStatus = $root.find('[data-role="tackquote-file-status"]'),
            $page = $('[data-role="tackquote-quote-page"]'),
            modalInstance;

        if (!$form.length || !$modal.length) {
            return;
        }

        $steps.each(function () {
            stepOrder.push($(this).data('step'));
        });

        modalInstance = modal({
            type: 'popup',
            modalClass: 'tackquote-modal-popup',
            title: config.modalTitle || $t('Request a Quote'),
            buttons: []
        }, $modal);

        // The container carries `hidden` purely to stop the form flashing inline before
        // this runs. Once the widget owns visibility that attribute fights it, collapsing
        // the dialog body to nothing.
        $modal.removeAttr('hidden');

        /* ---------------------------------------------------------------- list + drawer */

        function renderList() {
            var list = readList();

            $count.text(list.length);
            $widget.prop('hidden', list.length === 0);
            $empty.prop('hidden', list.length > 0);

            $items.empty();
            _.each(list, function (item) {
                var $li = $('<li class="tackquote-drawer__item"></li>'),
                    $name = $('<span class="tackquote-drawer__name"></span>').text(item.name),
                    $qty = $('<input type="number" min="1" class="input-text qty tackquote-drawer__qty">')
                        .val(item.qty)
                        .attr('aria-label', fill($t('Quantity for %1'), item.name)),
                    // The theme's icon font draws the button; the text stays for screen
                    // readers and for themes without the icon font.
                    $remove = $('<button type="button" class="action tackquote-drawer__remove"></button>')
                        .append($('<span></span>').text($t('Remove')))
                        .attr('aria-label', fill($t('Remove %1'), item.name));

                $qty.on('change', function () {
                    updateQty(item.sku, parseInt($(this).val(), 10));
                });
                $remove.on('click', function () {
                    removeItem(item.sku);
                });

                $li.append($name).append($qty).append($remove);
                $items.append($li);
            });

            renderPage(list);
        }

        /**
         * The full quote page, when this page is it (Block\QuotePage). The same list, as a
         * table: quantity and, when on, a target price per row.
         *
         * @param {Array} list
         */
        function renderPage(list) {
            var $body, pageTargets, headings;

            if (!$page.length) {
                return;
            }

            $body = $page.find('[data-role="tackquote-page-items"]');
            pageTargets = String($page.data('target-price')) === '1';

            $page.find('[data-role="tackquote-page-empty"]').prop('hidden', list.length > 0);
            $page.find('[data-role="tackquote-page-table"]').prop('hidden', list.length === 0);
            $page.find('[data-role="tackquote-page-message-field"]').prop('hidden', list.length === 0);
            $page.find('[data-role="tackquote-page-submit"]').prop('disabled', list.length === 0);

            // Header labels, repeated on each cell as data-th: the theme's phone layout for
            // `.table-wrapper .data.table` shows them beside each value.
            headings = $page.find('thead th').map(function () {
                return $.trim($(this).text());
            }).get();

            $body.empty();
            _.each(list, function (item, index) {
                var $tr = $('<tr></tr>'),
                    $qty = $('<input type="number" min="1" class="input-text qty">')
                        .val(item.qty)
                        .attr('aria-label', fill($t('Quantity for %1'), item.name)),
                    $remove = $('<button type="button" class="action delete"></button>')
                        .text($t('Remove'))
                        .attr('aria-label', fill($t('Remove %1'), item.name)),
                    $target;

                $tr.append($('<td class="col name"></td>').attr('data-th', headings[0] || '').text(item.name));
                $tr.append($('<td class="col qty"></td>').attr('data-th', headings[1] || '').append($qty));

                $qty.on('change', function () {
                    updateRow(index, {qty: Math.max(1, parseInt($(this).val(), 10) || 1)});
                });

                if (pageTargets) {
                    $target = targetInput(item.targetPrice, item.name, function (value) {
                        updateRow(index, {targetPrice: value}, true);
                    });
                    $tr.append($('<td class="col target"></td>').attr('data-th', headings[2] || '').append($target));
                }

                $remove.on('click', function () {
                    removeRow(index);
                });
                $tr.append($('<td class="col actions"></td>').append($remove));
                $body.append($tr);
            });
        }

        /**
         * A "target price" input. Typing never re-renders the row (that would steal focus);
         * the value is saved as typed and flagged when it is not a price.
         *
         * @param {String} value
         * @param {String} name
         * @param {Function} onValue Called with the cleaned value.
         * @return {jQuery}
         */
        function targetInput(value, name, onValue) {
            var $input = $('<input type="text" inputmode="decimal" class="input-text tackquote-target">')
                .val(value || '')
                .attr('placeholder', $t('Optional'))
                .attr('aria-label', fill($t('Target price for %1'), name));

            $input.on('input change', function () {
                var raw = $(this).val(),
                    clean = cleanTarget(raw),
                    bad = $.trim(raw) !== '' && clean === '';

                $(this).attr('aria-invalid', bad ? 'true' : null).toggleClass('mage-error', bad);
                onValue(clean);
            });

            return $input;
        }

        /**
         * Change one row of the list by its position.
         *
         * @param {Number} index
         * @param {Object} changes
         * @param {Boolean} quiet Skip re-rendering (while the shopper is typing).
         */
        function updateRow(index, changes, quiet) {
            var list = readList();

            if (!list[index]) {
                return;
            }
            _.extend(list[index], changes);
            writeList(list);

            if (!quiet) {
                renderList();
            }
        }

        /**
         * @param {Number} index
         */
        function removeRow(index) {
            var list = readList();

            list.splice(index, 1);
            writeList(list);
            renderList();
        }

        /**
         * Merge rows into the list, adding quantities to an identical row (same SKU, same
         * selection), as adding on a product page does. Rows that do not fit are returned.
         *
         * @param {Array} rows
         * @return {Array} Names of rows left out because the list is full.
         */
        function mergeRows(rows) {
            var list = readList(),
                left = [];

            _.each(rows, function (item) {
                var existing = _.find(list, function (row) {
                    return row.sku === item.sku &&
                        _.isEqual(row.superAttribute || {}, item.superAttribute || {});
                });

                if (existing) {
                    existing.qty += item.qty;
                } else if (list.length >= MAX_ITEMS) {
                    left.push(item.name);
                } else {
                    list.push(item);
                }
            });

            writeList(list);
            renderList();

            return left;
        }

        /**
         * @param {Object} item {sku, name, qty}
         */
        function addItem(item) {
            var list = readList(),
                existing = _.find(list, function (row) {
                    // Match on the SELECTION too: merging on sku alone collapsed
                    // Size XS/Black and Size XL/Gray into one line of the parent sku.
                    return row.sku === item.sku &&
                        _.isEqual(row.superAttribute || {}, item.superAttribute || {});
                });

            if (existing) {
                // Adding the same product again increases the quantity rather than
                // creating a duplicate line the seller would have to reconcile.
                existing.qty += item.qty;
            } else {
                if (list.length >= MAX_ITEMS) {
                    showError($t('Your quote list is full. Remove an item before adding another.'));

                    return;
                }
                list.push(item);
            }

            writeList(list);
            renderList();
            openDrawer();
        }

        function updateQty(sku, qty) {
            var list = readList();

            _.each(list, function (row) {
                if (row.sku === sku) {
                    row.qty = Math.max(1, qty || 1);
                }
            });
            writeList(list);
            renderList();
        }

        function removeItem(sku) {
            writeList(readList().filter(function (row) {
                return row.sku !== sku;
            }));
            renderList();
        }

        function openDrawer() {
            $drawer.prop('hidden', false);
            $widget.find('.tackquote-widget__toggle').attr('aria-expanded', 'true');
        }

        function closeDrawer() {
            $drawer.prop('hidden', true);
            $widget.find('.tackquote-widget__toggle').attr('aria-expanded', 'false');
        }

        /* ------------------------------------------------------------------------- form */

        function showError(text) {
            $error.text(text).prop('hidden', false);
        }

        /**
         * Inline message beside the control the shopper just used.
         *
         * showError() writes into the modal, which is CLOSED when adding to the list — so
         * using it there produced a button that appeared to do nothing at all, with no
         * message and no console error.
         *
         * @param {Object} $context Element wrapping the trigger.
         * @param {String} text
         */
        function notify($context, text) {
            var $msg = $context.find('.tackquote-inline-note');

            if (!$msg.length) {
                $msg = $('<p class="tackquote-inline-note" role="alert"></p>');
                $context.append($msg);
            }

            $msg.text(text);
        }

        function clearMessages() {
            $error.prop('hidden', true).text('');
        }

        function markInvalid($field, message) {
            $field.attr('aria-invalid', 'true').addClass('mage-error');
            showError(message);
            $field.trigger('focus');
        }

        function clearInvalid() {
            $form.find('[aria-invalid]').removeAttr('aria-invalid').removeClass('mage-error');
        }

        function goToStep(index) {
            current = Math.max(0, Math.min(index, stepOrder.length - 1));

            $steps.each(function (i) {
                $(this).prop('hidden', i !== current);
            });
            $markers.each(function (i) {
                $(this).toggleClass('_active', i === current);
                $(this).toggleClass('_complete', i < current);
            });

            $back.prop('hidden', current === 0);
            $next.prop('hidden', current === stepOrder.length - 1);
            $submit.prop('hidden', current !== stepOrder.length - 1);

            $steps.eq(current).find('input, select, textarea').filter(':visible').first().trigger('focus');
        }

        function validateCurrentStep() {
            var valid = true;

            clearInvalid();

            $steps.eq(current).find('input, select, textarea').each(function () {
                var $field = $(this),
                    isRequired = $field.prop('required') || $field.data('tq-required') === 1,
                    value = $.trim($field.val() || '');

                if (!valid) {
                    return;
                }

                if (isRequired && $field.attr('type') === 'checkbox' && !$field.prop('checked')) {
                    markInvalid($field, $t('Please tick this box to continue.'));
                    valid = false;

                    return;
                }

                if (isRequired && $field.attr('type') !== 'checkbox' && value === '') {
                    markInvalid($field, $t('This field is required.'));
                    valid = false;

                    return;
                }

                if ($field.attr('type') === 'email' && value !== '' &&
                    !/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(value)) {
                    markInvalid($field, $t('Please enter a valid email address.'));
                    valid = false;
                }
            });

            if (valid) {
                clearMessages();
            }

            return valid;
        }

        function prefillFromCustomer() {
            var customer = customerData.get('customer')();

            if (!customer || !customer.firstname) {
                return;
            }

            if (!$form.find('[name="firstName"]').val()) {
                $form.find('[name="firstName"]').val(customer.firstname);
            }

            if (customer.lastname && !$form.find('[name="lastName"]').val()) {
                $form.find('[name="lastName"]').val(customer.lastname);
            }
        }

        /**
         * Render the review step from whatever is being quoted, so the shopper confirms the
         * actual contents rather than trusting an invisible payload.
         *
         * @param {Array} rows
         */
        function renderReview(rows) {
            $review.empty();
            _.each(rows, function (row, index) {
                var $li = $('<li class="tackquote-summary-list__item"></li>'),
                    $name = $('<span></span>').text(row.name),
                    $qty = $('<span class="tackquote-summary-list__qty"></span>')
                        .text('x' + row.qty);

                $li.append($name).append($qty);

                if (targetsOn) {
                    $li.append(
                        $('<label class="tackquote-summary-list__target"></label>')
                            .append($('<span></span>').text($t('Target price (optional)')))
                            .append(targetInput(row.targetPrice, row.name, function (value) {
                                if (mode === 'list') {
                                    updateRow(index, {targetPrice: value}, true);
                                } else if (singleItem) {
                                    singleItem.targetPrice = value;
                                }
                            }))
                    );
                }

                $review.append($li);
            });
        }

        /* ------------------------------------------------------------------ attachments */

        function renderFiles() {
            $fileList.empty();
            _.each(attachments, function (file, index) {
                var $li = $('<li class="tackquote-attachments__item"></li>'),
                    $remove = $('<button type="button" class="action tackquote-attachments__remove">&times;</button>')
                        .attr('aria-label', fill($t('Remove %1'), file.name));

                // Removing only forgets the file here: TackQuote deletes an unclaimed upload
                // on its own, and an unnamed upload is never attached to anything.
                $remove.on('click', function () {
                    attachments.splice(index, 1);
                    renderFiles();
                });
                $fileList.append($li.append($('<span></span>').text(file.name)).append($remove));
            });

            if (attachCfg) {
                $files.prop('disabled', uploading || attachments.length >= attachCfg.maxFiles);
            }
        }

        /**
         * @param {String} text Empty to hide.
         * @param {Boolean} isError
         */
        function fileStatus(text, isError) {
            $fileStatus.text(text).prop('hidden', text === '').toggleClass('message error', !!isError);
        }

        function resetAttachments() {
            attachments = [];
            uploadToken = '';
            uploading = false;
            $files.val('');
            fileStatus('', false);
            renderFiles();
        }

        /**
         * Send the chosen files to this store's upload controller, which checks them and
         * passes them to TackQuote. All of a batch is attached, or none of it.
         *
         * @param {FileList} chosen
         */
        function uploadFiles(chosen) {
            var data, formKey;

            if (!attachCfg || !chosen || !chosen.length) {
                return;
            }
            if (attachments.length + chosen.length > attachCfg.maxFiles) {
                fileStatus(fill($t('Attach at most %1 files.'), String(attachCfg.maxFiles)), true);
                $files.val('');

                return;
            }

            formKey = ($form.find('[name="form_key"]').val() || '') || getCookie('form_key');
            data = new window.FormData();
            _.each(chosen, function (file) {
                data.append('files[]', file);
            });
            data.append('form_key', formKey);
            data.append('attached', String(attachments.length));
            if (uploadToken) {
                data.append('upload_token', uploadToken);
            }

            uploading = true;
            $submit.prop('disabled', true);
            fileStatus($t('Attaching...'), false);
            renderFiles();

            $.ajax({
                url: attachCfg.uploadUrl,
                type: 'POST',
                data: data,
                processData: false,
                contentType: false,
                dataType: 'json',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).done(function (answer) {
                if (!answer || !answer.success || !_.isArray(answer.files)) {
                    fileStatus(
                        (answer && answer.message) || $t('That file could not be attached. Please try again.'),
                        true
                    );

                    return;
                }
                _.each(answer.files, function (file) {
                    attachments.push({uploadId: String(file.uploadId), name: String(file.name || '')});
                });
                if (answer.uploadToken) {
                    uploadToken = String(answer.uploadToken);
                }
                fileStatus('', false);
            }).fail(function (xhr) {
                var response = xhr && xhr.responseJSON;

                fileStatus((response && response.message) ||
                    $t('That file could not be attached. Please try again.'), true);
            }).always(function () {
                uploading = false;
                $submit.prop('disabled', false);
                $files.val('');
                renderFiles();
            });
        }

        /**
         * Configurable/bundle products cannot be quoted from a bare parent SKU — the seller
         * would receive a variant they cannot fulfil. Magento renders the chosen options as
         * super_attribute inputs on the add-to-cart form.
         *
         * @return {Object}
         */
        /**
         * Whether the product page shows ANY option control the shopper must engage with.
         *
         * Bundles emit bundle_option[...], grouped emit super_group[...], custom options
         * emit options[...]. Checking only super_attribute made the gate below fire
         * unconditionally on those types, so "Add to Quote" was permanently dead on every
         * bundle, grouped and custom-option product in the catalogue.
         *
         * @return {Boolean}
         */
        function hasSelectableOptions() {
            return $('#product_addtocart_form')
                .find('[name^="super_attribute"], [name^="bundle_option"], [name^="super_group"], [name^="options["]')
                .length > 0;
        }

        function collectSuperAttributes() {
            var selections = {};

            $('#product_addtocart_form').find('[name^="super_attribute"]').each(function () {
                var name = $(this).attr('name'),
                    match = name && name.match(/super_attribute\[(\d+)]/),
                    value = $(this).val();

                if (match && value) {
                    selections[match[1]] = value;
                }
            });

            return selections;
        }

        /**
         * @param {String} nextMode 'single' | 'list'
         * @param {Object|null} item
         */
        function openForm(nextMode, item) {
            mode = nextMode;
            singleItem = item || null;

            clearMessages();
            clearInvalid();

            if (!$done.prop('hidden')) {
                $done.prop('hidden', true);
                $stepList.prop('hidden', false);
                $toolbar.prop('hidden', false);
                $form[0].reset();
                resetAttachments();
            }

            prefillFromCustomer();
            renderReview(mode === 'list' ? readList() : [singleItem]);
            goToStep(0);
            closeDrawer();
            modalInstance.openModal();
        }

        function showDone(data) {
            var title, body;

            if (data.awaitingApproval) {
                // TackQuote answers awaitingApproval for EVERY company request (1.4.1):
                // it no longer says whether the company name matched an existing
                // account, so this copy must not claim the account IS under review.
                title = $t('Request received');
                body = $t(
                    'If your company account needs approval, we will email you when it is ready.'
                );
                if (data.quoteNumber) {
                    body = fill($t('Your reference is %1.'), data.quoteNumber) + ' ' + body;
                }
            } else {
                title = $t('Quote request sent');
                body = data.quoteNumber ?
                    fill(
                        $t('Your reference is %1. A sales rep will be in touch shortly.'),
                        data.quoteNumber
                    ) :
                    $t('A sales rep will be in touch shortly.');
            }

            $done.find('.tackquote-done__title').text(title);
            $done.find('.tackquote-done__body').text(body);

            $steps.prop('hidden', true);
            $stepList.prop('hidden', true);
            $toolbar.prop('hidden', true);
            $done.prop('hidden', false);
            $done.trigger('focus');
        }

        function submitForm() {
            var formKeyInput = $form.find('[name="form_key"]'),
                formKey = (formKeyInput.val() || '') || getCookie('form_key'),
                payload;

            if (!formKey) {
                showError($t('Could not verify the form. Please reload the page and try again.'));

                return;
            }

            if (uploading) {
                showError($t('Please wait until your files are attached.'));

                return;
            }

            payload = $form.serializeArray();
            payload.push({ name: 'form_key', value: formKey });

            if (mode === 'list') {
                // Only sku + qty travel; the controller re-resolves name and price.
                payload.push({
                    name: 'items',
                    value: JSON.stringify(readList().map(function (row) {
                        var out = {
                            sku: row.sku,
                            qty: row.qty,
                            superAttribute: row.superAttribute || {}
                        };

                        if (targetsOn && row.targetPrice) {
                            out.targetPrice = row.targetPrice;
                        }

                        return out;
                    }))
                });
            } else {
                payload.push({ name: 'sku', value: singleItem.sku });
                payload.push({ name: 'qty', value: singleItem.qty });
                _.each(singleItem.superAttribute || {}, function (value, id) {
                    payload.push({ name: 'super_attribute[' + id + ']', value: value });
                });
                if (targetsOn && singleItem.targetPrice) {
                    payload.push({ name: 'targetPrice', value: singleItem.targetPrice });
                }
            }

            if (attachments.length) {
                payload.push({
                    name: 'uploadIds',
                    value: _.pluck(attachments, 'uploadId').join(',')
                });
                if (uploadToken) {
                    payload.push({ name: 'uploadToken', value: uploadToken });
                }
            }

            $submit.prop('disabled', true);
            clearMessages();

            $.ajax({
                url: submitUrl,
                type: 'POST',
                dataType: 'json',
                data: $.param(payload),
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).done(function (data) {
                if (!data || !data.success) {
                    showError((data && data.message) ||
                        $t('Could not create the quote. Please try again.'));

                    if (data && data.field) {
                        $form.find('[name="' + data.field + '"]')
                            .attr('aria-invalid', 'true')
                            .addClass('mage-error');
                    }

                    return;
                }

                // The list has been quoted; leaving it populated invites a duplicate
                // request for the same products.
                if (mode === 'list') {
                    writeList([]);
                    renderList();
                }

                // The files belong to the quote just created; the next request starts empty.
                resetAttachments();
                showDone(data);
            }).fail(function (xhr) {
                var response = xhr && xhr.responseJSON;

                showError((response && response.message) ||
                    $t('Could not reach TackQuote. Please try again.'));
            }).always(function () {
                $submit.prop('disabled', false);
            });
        }

        /* --------------------------------------------------------------------- wiring */

        // Product-page and listing triggers are bound on document, because listing pages
        // re-render product tiles (layered navigation, infinite scroll) after this runs.
        $(document).on('click', '.tackquote-add-btn', function () {
            var $ctx = $(this).closest('[data-role="tackquote-product"]'),
                requiresOptions = String($ctx.data('requires-options')) === '1',
                qtyField = $('#product_addtocart_form').find('[name="qty"]'),
                qty = Math.max(1, parseInt(qtyField.val(), 10) || 1);

            // On a listing there is no option UI at all, so such products link to their
            // product page instead of silently quoting an unfulfillable parent SKU.
            if (requiresOptions && $ctx.data('product-url')) {
                window.location.href = $ctx.data('product-url');

                return;
            }

            var superAttribute = collectSuperAttributes();

            /*
             * Only configurable selections can be resolved server-side today, so anything
             * else that needs a choice (bundle, grouped, custom options) is sent to its
             * product page rather than quietly quoting an unfulfillable parent SKU.
             */
            if (requiresOptions && hasSelectableOptions() && _.isEmpty(superAttribute)) {
                notify($ctx, $t('Please choose the options you need, then add to your quote.'));

                return;
            }

            if (requiresOptions && !hasSelectableOptions()) {
                notify($ctx, $t('This product has to be configured on its own page before it can be quoted.'));

                return;
            }

            addItem({
                sku: String($ctx.data('sku')),
                name: String($ctx.data('name')),
                qty: qty,
                superAttribute: superAttribute
            });
        });

        $(document).on('click', '.tackquote-request-btn', function () {
            var $ctx = $(this).closest('[data-role="tackquote-product"]'),
                requiresOptions = String($ctx.data('requires-options')) === '1',
                superAttribute = collectSuperAttributes(),
                qtyField = $('#product_addtocart_form').find('[name="qty"]'),
                qty = Math.max(1, parseInt(qtyField.val(), 10) || 1);

            if (requiresOptions && _.isEmpty(superAttribute)) {
                // Tell them beside the button, not inside a modal they have not opened.
                notify($ctx, $t('Please choose the options you need, then request a quote.'));

                return;
            }

            openForm('single', {
                sku: String($ctx.data('sku')),
                name: String($ctx.data('name')),
                qty: qty,
                superAttribute: superAttribute
            });
        });

        $root.on('click', '.tackquote-widget__toggle', function () {
            if ($drawer.prop('hidden')) {
                openDrawer();
            } else {
                closeDrawer();
            }
        });

        $root.on('click', '.tackquote-drawer__close', closeDrawer);

        $root.on('click', '[data-role="tackquote-request-list"]', function () {
            if (readList().length === 0) {
                return;
            }
            openForm('list', null);
        });

        /*
         * Quote-only mode: the CTA on the cart page (Block\QuoteOnlyNotice).
         *
         * Bound on `document`, NOT on $root, and this is the whole point of it existing
         * separately. $root is the quote-list widget's own element (line 116), so every
         * delegated handler above only ever sees clicks INSIDE that widget. The cart-page
         * notice renders into the `content` container, outside $root — reusing
         * `tackquote-request-list` for it would have produced a button that binds to nothing
         * and silently does nothing when clicked. On a quote-only storefront that is the
         * last button the shopper has.
         *
         * It also deliberately does NOT bail out on an empty list the way the handler above
         * does. Someone redirected off checkout may have items in their CART and nothing in
         * their quote list; openDrawer() shows the drawer's own empty state, which tells
         * them how to start one. A silent no-op would not.
         */
        $(document).on('click', '[data-role="tackquote-quote-only-cta"]', function (event) {
            event.preventDefault();
            openDrawer();
        });

        /*
         * The full quote page (Block\QuotePage) renders in `content`, outside $root, so it is
         * bound on document like the quote-only CTA below. Its message becomes the form's note.
         */
        $(document).on('click', '[data-role="tackquote-page-submit"]', function () {
            var message = $.trim($page.find('[data-role="tackquote-page-message"]').val() || '');

            if (readList().length === 0) {
                return;
            }
            openForm('list', null);
            if (message !== '' && !$form.find('[name="note"]').val()) {
                $form.find('[name="note"]').val(message);
            }
        });

        /*
         * Cart page, normal mode (Block\CartQuoteButton): copy the cart's SKUs and quantities
         * into the quote list, from the `cart` customer-data section the minicart already
         * holds. The Magento cart itself is not touched.
         */
        $(document).on('click', '[data-role="tackquote-cart-seed"]', function () {
            var $ctx = $(this).closest('[data-role="tackquote-cart-quote"]'),
                cart = customerData.get('cart')(),
                seeded = cartSeed(cart && cart.items, MAX_ITEMS),
                left = seeded.lines.length ? mergeRows(seeded.lines) : [],
                skipped = seeded.skipped.concat(left);

            if (!seeded.lines.length) {
                notify($ctx, $t('None of the products in your cart can be added to a quote list.'));

                return;
            }
            if (skipped.length) {
                notify($ctx, fill(
                    $t('Not added, request these from their product pages: %1'),
                    _.uniq(skipped).join(', ')
                ));
            }
            openDrawer();
        });

        $files.on('change', function () {
            uploadFiles(this.files);
        });

        $next.on('click', function () {
            if (validateCurrentStep()) {
                goToStep(current + 1);
            }
        });

        $back.on('click', function () {
            clearMessages();
            goToStep(current - 1);
        });

        $form.on('submit', function (event) {
            event.preventDefault();

            /*
             * Implicit submission: pressing Enter in any field submits the form, and the
             * `hidden` attribute does NOT exempt the submit button from being the default.
             * Without this guard, Enter in a step-1 field posted the whole quote — skipping
             * the company step entirely, so a company_only tenant received an individual
             * registration with no company name.
             */
            if (current !== stepOrder.length - 1) {
                if (validateCurrentStep()) {
                    goToStep(current + 1);
                }

                return;
            }

            if (validateCurrentStep()) {
                submitForm();
            }
        });

        if (companyMode === 'required') {
            $form.find('[name="companyName"]').prop('required', true);
        }

        renderList();
        goToStep(0);
    };
});

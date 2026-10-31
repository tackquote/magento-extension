/**
 * The seller's calendar on a TackQuote "No dates in the past" date field.
 *
 * The TackQuote server refuses a date before the SELLER's today (in the seller's time
 * zone), so the field carries data-tq-seller-zone / data-tq-seller-today and its
 * earliest selectable day follows the seller's zone live (a page left open past the
 * seller's midnight moves with it). The hint under the field (rendered hidden by
 * Model\Forms\FieldRenderer) shows only when the shopper's own date differs.
 *
 * Initialised by data-mage-init on the form: the initializer calls the returned
 * function with (config, element).
 * https://developer.adobe.com/commerce/frontend-core/javascript/init
 */
define([], function () {
    'use strict';

    var DAY = /^\d{4}-\d{2}-\d{2}$/;

    /** `YYYY-MM-DD` of `now` in `zone`, or null for a zone this browser does not know. */
    function dayIn(zone, now) {
        var parts, get, day;

        try {
            parts = new Intl.DateTimeFormat('en-US', {
                timeZone: zone,
                year: 'numeric',
                month: '2-digit',
                day: '2-digit'
            }).formatToParts(now);
            get = function (type) {
                var i;

                for (i = 0; i < parts.length; i++) {
                    if (parts[i].type === type) {
                        return parts[i].value;
                    }
                }

                return '';
            };
            day = get('year') + '-' + get('month') + '-' + get('day');

            return DAY.test(day) ? day : null;
        } catch (e) {
            return null;
        }
    }

    /** The shopper's own local day. */
    function localDay(now) {
        var pad = function (n) {
            return (n < 10 ? '0' : '') + n;
        };

        return now.getFullYear() + '-' + pad(now.getMonth() + 1) + '-' + pad(now.getDate());
    }

    /** "Oct 17, 2026" in the page's language; the ISO day when the browser cannot format. */
    function dayLabel(day, lang) {
        var p = day.split('-');

        try {
            return new Date(Date.UTC(+p[0], +p[1] - 1, +p[2])).toLocaleDateString(
                lang || undefined,
                {month: 'short', day: 'numeric', year: 'numeric', timeZone: 'UTC'}
            );
        } catch (e) {
            return day;
        }
    }

    /** The ISO day after `day` (`YYYY-MM-DD`). */
    function nextDay(day) {
        var p = day.split('-'),
            d = new Date(Date.UTC(+p[0], +p[1] - 1, +p[2] + 1)),
            pad = function (n) {
                return (n < 10 ? '0' : '') + n;
            };

        return d.getUTCFullYear() + '-' + pad(d.getUTCMonth() + 1) + '-' + pad(d.getUTCDate());
    }

    /**
     * The seller's today by this device's clock, kept between the day the server sent and
     * the day after it. A device clock set wrong must not move the earliest day: earlier
     * would let the shopper pick a day TackQuote refuses, later would refuse a valid one.
     * The day after covers a page left open past the seller's midnight.
     */
    function sellerToday(zone, sent, now) {
        var valid = DAY.test(sent) ? sent : null,
            live = dayIn(zone, now),
            next;

        if (!valid) {
            return live;
        }
        // Same-length ISO days compare correctly as strings.
        if (!live || live < valid) {
            return valid;
        }
        next = nextDay(valid);

        return live > next ? next : live;
    }

    /** Apply the seller's calendar to every such field inside `root`, at `now`. */
    function apply(root, now) {
        var doc = root.ownerDocument || document,
            inputs = root.querySelectorAll('input[data-tq-seller-today]');

        Array.prototype.forEach.call(inputs, function (input) {
            var today = sellerToday(
                    input.getAttribute('data-tq-seller-zone'),
                    input.getAttribute('data-tq-seller-today'),
                    now
                ),
                hint, differs, label, described;

            if (!today) {
                return;
            }
            input.min = today;
            hint = doc.getElementById(input.id + '-calendar');

            if (!hint) {
                return;
            }
            differs = today !== localDay(now);
            hint.hidden = !differs;
            // Lib `.note` gets a display value (.lib-icon-font) that outranks the UA `[hidden]`
            // rule; _module.less enforces it too, and the inline style does not lose.
            hint.style.display = differs ? '' : 'none';

            if (differs) {
                label = hint.querySelector('.tq-seller-day');

                if (label) {
                    label.textContent = dayLabel(today, doc.documentElement && doc.documentElement.lang);
                }
            }
            // A hidden node named by aria-describedby is still announced: link it only while shown.
            described = (input.getAttribute('aria-describedby') || '').split(' ').filter(function (id) {
                return id && id !== hint.id;
            });

            if (differs) {
                described.push(hint.id);
            }

            if (described.length) {
                input.setAttribute('aria-describedby', described.join(' '));
            } else {
                input.removeAttribute('aria-describedby');
            }
        });
    }

    return function (config, element) {
        apply(element, new Date());
        element.addEventListener('focusin', function (event) {
            // Re-read on the way into a date field: the seller's day may have turned.
            if (event.target && event.target.hasAttribute && event.target.hasAttribute('data-tq-seller-today')) {
                apply(element, new Date());
            }
        });
    };
});

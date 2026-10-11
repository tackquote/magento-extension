/**
 * Unit test for view/frontend/web/js/buyer-prefill.js. Plain Node, no dependencies:
 *
 *   node Test/Js/buyer-prefill.test.js
 *
 * A shopper signed in before the `tackquote-buyer` section existed has never loaded it; the
 * prefill must ask for it once per page and apply the details when it answers (devstore Q/A
 * re-test of #6). Exits non-zero on the first failed assertion.
 *
 * Mutations (applied, red, restored):
 *   P1  drop the `reloaded` guard -> "an empty section triggers ONE reload per page"
 *   P2  drop the `data_id` check -> "a loaded section is never reloaded"
 *   P3  drop the firstname check -> "a guest never triggers a reload"
 *   P4  do not re-apply when the reload answers -> "the details are applied when the reload answers"
 */
'use strict';

var assert = require('assert'),
    path = require('path'),
    buyerPrefill = require(path.join(__dirname, '..', '..', 'view', 'frontend', 'web', 'js', 'buyer-prefill.js')),
    tests = [];

function test(name, fn) {
    tests.push([name, fn]);
}

/**
 * A stand-in for Magento_Customer/js/customer-data: get(name)() reads `sections`, reload()
 * records the call and answers with a promise whose done() runs when `answer()` is called.
 */
function fakeCustomerData(sections) {
    var waiting = [],
        fake = {
            reloads: [],
            get: function (name) {
                return function () {
                    return sections[name];
                };
            },
            reload: function (names, force) {
                fake.reloads.push([names, force]);

                return {
                    done: function (fn) {
                        waiting.push(fn);
                    }
                };
            },
            answer: function (name, data) {
                sections[name] = data;
                waiting.splice(0).forEach(function (fn) {
                    fn(data);
                });
            }
        };

    return fake;
}

var BUYER = {
    data_id: 1760000000,
    firstname: 'Pat',
    lastname: 'Buyer',
    email: 'buyer@example.test',
    telephone: '555-0199'
};

test('an empty section triggers ONE reload per page, without forcing', function () {
    var data = fakeCustomerData({customer: {firstname: 'Pat'}}),
        run = buyerPrefill(data, function () {});

    run();
    run();
    run();

    assert.deepStrictEqual(data.reloads, [[['tackquote-buyer'], false]]);
});

test('the details are applied when the reload answers', function () {
    var data = fakeCustomerData({customer: {firstname: 'Pat'}, 'tackquote-buyer': {}}),
        applied = [],
        run = buyerPrefill(data, function (buyer) {
            applied.push(buyer.email || '');
        });

    run();
    data.answer('tackquote-buyer', BUYER);

    assert.deepStrictEqual(applied, ['', 'buyer@example.test']);
});

test('a loaded section is never reloaded', function () {
    var data = fakeCustomerData({customer: {firstname: 'Pat'}, 'tackquote-buyer': BUYER}),
        applied = [],
        run = buyerPrefill(data, function (buyer, customer) {
            applied.push([buyer.telephone, customer.firstname]);
        });

    run();

    assert.deepStrictEqual(data.reloads, []);
    assert.deepStrictEqual(applied, [['555-0199', 'Pat']]);
});

test('a loaded guest answer (empty but with a data_id) is never reloaded', function () {
    var data = fakeCustomerData({customer: {firstname: 'Pat'}, 'tackquote-buyer': {data_id: 1760000000}}),
        run = buyerPrefill(data, function () {});

    run();

    assert.deepStrictEqual(data.reloads, []);
});

test('a guest never triggers a reload', function () {
    var data = fakeCustomerData({customer: {}, 'tackquote-buyer': undefined}),
        applied = 0,
        run = buyerPrefill(data, function (buyer, customer) {
            assert.deepStrictEqual(buyer, {});
            assert.deepStrictEqual(customer, {});
            applied++;
        });

    run();

    assert.deepStrictEqual(data.reloads, []);
    assert.strictEqual(applied, 1);
});

tests.forEach(function (t) {
    t[1]();
    process.stdout.write('ok - ' + t[0] + '\n');
});
process.stdout.write(tests.length + ' passed\n');

/**
 * Unit test for view/frontend/web/js/cart-seed.js. Plain Node, no dependencies:
 *
 *   node Test/Js/cart-seed.test.js
 *
 * Exits non-zero on the first failed assertion.
 */
'use strict';

var assert = require('assert'),
    path = require('path'),
    cartSeed = require(path.join(__dirname, '..', '..', 'view', 'frontend', 'web', 'js', 'cart-seed.js')),
    tests = [];

function test(name, fn) {
    tests.push([name, fn]);
}

function item(extra) {
    return Object.assign({
        product_type: 'simple',
        product_sku: 'S-1',
        product_name: 'Bolt',
        qty: 2,
        options: []
    }, extra);
}

test('a simple item becomes a row with its sku and quantity', function () {
    var out = cartSeed([item()], 50);

    assert.deepStrictEqual(out.lines, [{sku: 'S-1', name: 'Bolt', qty: 2, superAttribute: {}}]);
    assert.deepStrictEqual(out.skipped, []);
});

test('a configurable item keeps its (child) sku even with options listed', function () {
    var out = cartSeed([item({product_type: 'configurable', product_sku: 'TEE-S-BLUE',
        options: [{label: 'Size', value: 'S'}]})], 50);

    assert.strictEqual(out.lines.length, 1);
    assert.strictEqual(out.lines[0].sku, 'TEE-S-BLUE');
});

test('bundle and gift card items are skipped and named', function () {
    var out = cartSeed([
        item({product_type: 'bundle', product_sku: 'KIT-a-b', product_name: 'Kit'}),
        item({product_type: 'giftcard', product_sku: 'GC', product_name: 'Card'})
    ], 50);

    assert.deepStrictEqual(out.lines, []);
    assert.deepStrictEqual(out.skipped, ['Kit', 'Card']);
});

test('a simple item with custom options is skipped and named', function () {
    var out = cartSeed([item({options: [{label: 'Engraving', value: 'AB'}], product_name: 'Ring'})], 50);

    assert.deepStrictEqual(out.lines, []);
    assert.deepStrictEqual(out.skipped, ['Ring']);
});

test('a quantity below one or unreadable becomes 1; fractions are floored', function () {
    var out = cartSeed([item({qty: 0}), item({product_sku: 'S-2', qty: 'x'}), item({product_sku: 'S-3', qty: 3.7})], 50);

    assert.deepStrictEqual(out.lines.map(function (l) { return l.qty; }), [1, 1, 3]);
});

test('rows beyond the free space are skipped and named, not silently cut', function () {
    var out = cartSeed([item(), item({product_sku: 'S-2', product_name: 'Nut'})], 1);

    assert.strictEqual(out.lines.length, 1);
    assert.deepStrictEqual(out.skipped, ['Nut']);
});

test('garbage input yields nothing', function () {
    assert.deepStrictEqual(cartSeed(null, 50), {lines: [], skipped: []});
    assert.deepStrictEqual(cartSeed([null, 7, item({product_sku: ''})], 50).lines, []);
});

tests.forEach(function (t) {
    t[1]();
    process.stdout.write('ok - ' + t[0] + '\n');
});
process.stdout.write(tests.length + ' passed\n');

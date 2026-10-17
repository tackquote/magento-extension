/**
 * The seller's calendar on a "No dates in the past" date field
 * (view/frontend/web/js/seller-calendar.js): the earliest day is the
 * SELLER's today in their zone, the day the TackQuote server refuses earlier dates
 * against, and the hint shows only when the shopper's own date differs.
 *
 * Run: node Test/Js/seller-calendar.test.js   (Node 18+, no dependencies; exit 1 on any failure)
 *
 * The real RequireJS module runs in a VM context (a `define` stand-in captures it) against
 * a small DOM stand-in. "Now" is pinned
 * through the context's Date, and the shopper's zone through process.env.TZ.
 */
'use strict';

const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const vm = require('node:vm');

const file = path.join(__dirname, '..', '..', 'view', 'frontend', 'web', 'js', 'seller-calendar.js');
const source = fs.readFileSync(file, 'utf8');

let failures = 0;
function check(label, fn) {
  try {
    fn();
    console.log('  PASS  ' + label);
  } catch (e) {
    failures++;
    console.log('  FAIL  ' + label + '\n        ' + e.message);
  }
}

function El(attrs) {
  this.attrs = Object.assign({}, attrs || {});
  this.id = this.attrs.id || '';
  this.hidden = 'hidden' in this.attrs;
  this.min = this.attrs.min || '';
  this.textContent = '';
  this.children = [];
  this.listeners = {};
}
El.prototype.getAttribute = function (k) {
  return k in this.attrs ? this.attrs[k] : null;
};
El.prototype.setAttribute = function (k, v) {
  this.attrs[k] = String(v);
};
El.prototype.removeAttribute = function (k) {
  delete this.attrs[k];
};
El.prototype.hasAttribute = function (k) {
  return k in this.attrs;
};
El.prototype.addEventListener = function (type, fn) {
  this.listeners[type] = fn;
};
El.prototype.querySelector = function (sel) {
  return this.querySelectorAll(sel)[0] || null;
};
El.prototype.querySelectorAll = function (sel) {
  if (sel === 'input[data-tq-seller-today]') {
    return this.children.filter((c) => c.hasAttribute('data-tq-seller-today'));
  }
  if (sel === '.tq-seller-day') {
    return this.children.filter((c) => c.getAttribute('class') === 'tq-seller-day');
  }
  return [];
};

/**
 * A form with one date input, as the PHP renders it. `calendar` null = an older
 * server: no seller attributes and no hint.
 */
function render(calendar, min) {
  const form = new El({});
  const input = new El(
    Object.assign(
      { id: 'tq-sf-start', type: 'date', min },
      calendar
        ? { 'data-tq-seller-zone': calendar.timeZone, 'data-tq-seller-today': calendar.today }
        : {},
    ),
  );
  form.children.push(input);
  const byId = {};
  let hint = null;
  if (calendar) {
    hint = new El({ id: 'tq-sf-start-calendar', hidden: 'hidden' });
    const day = new El({ class: 'tq-seller-day' });
    day.textContent = calendar.today;
    hint.children.push(day);
    byId[hint.id] = hint;
  }
  return { form, input, hint, byId };
}

/** Run the script once at `nowIso`, the shopper in zone `buyerZone`. */
function run(page, nowIso, buyerZone, lang) {
  process.env.TZ = buyerZone;
  const fixed = new Date(nowIso).getTime();
  class FixedDate extends Date {
    constructor(...args) {
      super(...(args.length ? args : [fixed]));
    }
    static now() {
      return fixed;
    }
  }
  const document = {
    documentElement: { lang: lang || 'en-US' },
    getElementById: (id) => page.byId[id] || null,
  };
  page.form.ownerDocument = document;
  let factory = null;
  vm.runInNewContext(source, {
    define: (deps, fn) => {
      factory = fn;
    },
    document,
    Date: FixedDate,
    Intl,
    Array,
    Object,
    String,
  });
  factory()({}, page.form);
  return page;
}

// 20:00 UTC on Oct 16: 13:00 on the 16th in Los Angeles, 01:30 on the 17th in Kolkata.
const NOW = '2026-10-16T20:00:00Z';
const KOLKATA = { timeZone: 'Asia/Kolkata', today: '2026-10-17' };

check("a buyer in Los Angeles gets the seller's today (Kolkata) as the earliest day", () => {
  const p = run(render(KOLKATA, '2026-10-17'), NOW, 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-10-17');
});

check("the hint shows when the buyer's date differs, with the seller's day in the page's language", () => {
  const p = run(render(KOLKATA, '2026-10-17'), NOW, 'America/Los_Angeles');
  assert.equal(p.hint.hidden, false);
  assert.equal(p.hint.children[0].textContent, 'Oct 17, 2026');
  assert.equal(p.input.getAttribute('aria-describedby'), 'tq-sf-start-calendar');
});

check('an existing description is kept beside the hint', () => {
  const page = render(KOLKATA, '2026-10-17');
  page.input.setAttribute('aria-describedby', 'tq-sf-start-help');
  const p = run(page, NOW, 'America/Los_Angeles');
  assert.equal(p.input.getAttribute('aria-describedby'), 'tq-sf-start-help tq-sf-start-calendar');
});

check("the hint stays hidden when the buyer's date is the seller's", () => {
  const p = run(render(KOLKATA, '2026-10-17'), NOW, 'Asia/Kolkata');
  assert.equal(p.hint.hidden, true);
  assert.equal(p.input.min, '2026-10-17');
  assert.equal(p.input.getAttribute('aria-describedby'), null);
});

check('a hint hidden again (the days now agree) is unlinked from aria-describedby, other ids kept', () => {
  const page = render(KOLKATA, '2026-10-17');
  page.input.setAttribute('aria-describedby', 'tq-sf-start-help tq-sf-start-calendar');
  const p = run(page, NOW, 'Asia/Kolkata');
  assert.equal(p.hint.hidden, true);
  assert.equal(p.input.getAttribute('aria-describedby'), 'tq-sf-start-help');
  const q = render(KOLKATA, '2026-10-17');
  q.input.setAttribute('aria-describedby', 'tq-sf-start-calendar');
  assert.equal(run(q, NOW, 'Asia/Kolkata').input.getAttribute('aria-describedby'), null);
});

check("a page left open past the seller's midnight moves to the seller's new day", () => {
  // Rendered on the 16th (Kolkata); the shopper picks a date after Kolkata's midnight.
  const p = run(render({ timeZone: 'Asia/Kolkata', today: '2026-10-16' }, '2026-10-16'), NOW, 'Asia/Kolkata');
  assert.equal(p.input.min, '2026-10-17');
});

check('a zone this browser does not know falls back to the day the server sent', () => {
  const p = run(render({ timeZone: 'Mars/Olympus_Mons', today: '2026-10-17' }, '2026-10-17'), NOW, 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-10-17');
  assert.equal(p.hint.hidden, false);
});

check('an older server (no calendar) leaves the field exactly as rendered', () => {
  const p = run(render(null, '2026-10-16'), NOW, 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-10-16');
  assert.equal(p.input.getAttribute('aria-describedby'), null);
});

check('the field re-reads the calendar when the shopper enters it (focusin is wired)', () => {
  const p = run(render(KOLKATA, '2026-10-17'), NOW, 'America/Los_Angeles');
  assert.equal(typeof p.form.listeners.focusin, 'function');
});

if (failures) {
  console.log(`\n${failures} failure(s)`);
  process.exit(1);
}
console.log('\nall seller-calendar checks passed');

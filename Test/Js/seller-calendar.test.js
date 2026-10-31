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
  this.style = {};
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
  let fixed = new Date(nowIso).getTime();
  // Move "now" for later events (focusin) on the same page.
  page.setNow = (iso) => {
    fixed = new Date(iso).getTime();
  };
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

check("the field re-reads the calendar when the shopper enters it (focusin moves min past the seller's midnight)", () => {
  // 10:00 UTC on Oct 16 is 15:30 on the 16th in Kolkata: the sent day still holds.
  const p = run(render({ timeZone: 'Asia/Kolkata', today: '2026-10-16' }, '2026-10-16'), '2026-10-16T10:00:00Z', 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-10-16');
  assert.equal(typeof p.form.listeners.focusin, 'function');
  // Ten hours later it is 01:30 on the 17th in Kolkata; the shopper tabs into the field.
  p.setNow('2026-10-16T20:00:00Z');
  p.form.listeners.focusin({ target: p.input });
  assert.equal(p.input.min, '2026-10-17');
});

check('focusin on another field leaves the date field alone', () => {
  const p = run(render({ timeZone: 'Asia/Kolkata', today: '2026-10-16' }, '2026-10-16'), '2026-10-16T10:00:00Z', 'America/Los_Angeles');
  p.setNow('2026-10-16T20:00:00Z');
  p.form.listeners.focusin({ target: new El({ id: 'tq-sf-name' }) });
  assert.equal(p.input.min, '2026-10-16');
});

// The device clock is the shopper's and can be wrong. The server sent the seller's today
// (Oct 17 in Kolkata at the real NOW); the earliest day stays within [sent, sent + 1 day].
check('a device clock a day BEHIND never moves the earliest day before the day the server sent', () => {
  // The device thinks it is Oct 15, 20:00 UTC (Oct 16 in Kolkata).
  const p = run(render(KOLKATA, '2026-10-17'), '2026-10-15T20:00:00Z', 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-10-17');
});

check('a device clock a day AHEAD moves the earliest day at most to the day after the sent day', () => {
  // The device thinks it is Oct 17, 20:00 UTC (Oct 18 in Kolkata): allowed, as a page left open past midnight.
  const p = run(render(KOLKATA, '2026-10-17'), '2026-10-17T20:00:00Z', 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-10-18');
});

check('a device clock days AHEAD is held to the day after the sent day', () => {
  const p = run(render(KOLKATA, '2026-10-17'), '2026-10-20T20:00:00Z', 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-10-18');
});

check('the day after the sent day rolls over a month end', () => {
  const p = run(render({ timeZone: 'Asia/Kolkata', today: '2026-10-31' }, '2026-10-31'), '2026-11-03T20:00:00Z', 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-11-01');
});

// US DST ends 2026-11-01 at 06:00 UTC (02:00 EDT -> 01:00 EST). 05:30 UTC is 01:30 EDT on
// Nov 1 in New York and 22:30 PDT on Oct 31 in Los Angeles.
check('DST boundary (America/New_York, 2026-11-01T05:30Z): the seller has turned to Nov 1', () => {
  const NY = { timeZone: 'America/New_York', today: '2026-10-31' };
  const p = run(render(NY, '2026-10-31'), '2026-11-01T05:30:00Z', 'America/Los_Angeles');
  assert.equal(p.input.min, '2026-11-01');
  assert.equal(p.hint.hidden, false);
  assert.equal(p.hint.children[0].textContent, 'Nov 1, 2026');
  // The repeated hour after the change (01:30 EST) is still Nov 1, and the buyer in New York agrees.
  const q = run(render({ timeZone: 'America/New_York', today: '2026-11-01' }, '2026-11-01'), '2026-11-01T06:30:00Z', 'America/New_York');
  assert.equal(q.input.min, '2026-11-01');
  assert.equal(q.hint.hidden, true);
});

// Lib `.note` (Blank/Luma) carries a display value that outranks the UA `[hidden]` rule.
check('a shown hint clears the inline display, a hidden one sets display:none', () => {
  const shown = run(render(KOLKATA, '2026-10-17'), NOW, 'America/Los_Angeles');
  assert.equal(shown.hint.hidden, false);
  assert.equal(shown.hint.style.display, '');
  const hidden = run(render(KOLKATA, '2026-10-17'), NOW, 'Asia/Kolkata');
  assert.equal(hidden.hint.hidden, true);
  assert.equal(hidden.hint.style.display, 'none');
});

check('_module.less forces display:none on the hidden hint, and the renderer gives the hint that class', () => {
  const root = path.join(__dirname, '..', '..');
  const less = fs.readFileSync(path.join(root, 'view', 'frontend', 'web', 'css', 'source', '_module.less'), 'utf8')
    .replace(/\/\/[^\n]*/g, '');
  const selector = '.tackquote-application .tq-seller-calendar[hidden]';
  const at = less.indexOf(selector);
  assert.ok(at >= 0, 'selector missing from _module.less');
  const open = less.indexOf('{', at);
  const close = less.indexOf('}', open);
  assert.ok(open > at && close > open, 'no rule body after the selector');
  // Only selectors (comma-separated) between it and the brace: it belongs to that rule.
  assert.match(less.slice(at + selector.length, open), /^[\s,.\w\-\[\]='():]*$/);
  assert.match(less.slice(open + 1, close), /^\s*display:\s*none\s*!important;\s*$/);
  // The common group compiles once for every width.
  assert.ok(less.lastIndexOf('& when (@media-common = true)', at) >= 0);
  const php = fs.readFileSync(path.join(root, 'Model', 'Forms', 'FieldRenderer.php'), 'utf8');
  assert.match(php, /class="note tq-seller-calendar" id="' \. \$id \. '-calendar" hidden="hidden"/);
  const tpl = fs.readFileSync(path.join(root, 'view', 'frontend', 'templates', 'account', 'wholesale.phtml'), 'utf8');
  assert.match(tpl, /class="tackquote-application[ "]/);
});

if (failures) {
  console.log(`\n${failures} failure(s)`);
  process.exit(1);
}
console.log('\nall seller-calendar checks passed');

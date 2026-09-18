/**
 * The procurement items step, driven the way a storekeeper drives it.
 *
 * Everything on that step used to live only in the DOM until the whole form
 * was posted, so a reload lost a purchase order typed line by line. The draft
 * that fixes it is inline script in the Blade view, which is awkward to import
 * — so this reads the view, strips the Blade out of it, and runs the real
 * script. Testing a copy of the logic would only prove the copy works.
 */
import fs from 'node:fs';
import path from 'node:path';
import { beforeEach, describe, expect, it } from 'vitest';

const VIEW = path.resolve(__dirname, '../../views/procurement/items.blade.php');

/** The view's <script> body, with Blade echoes reduced to what they render as. */
function itemsScript() {
  const blade = fs.readFileSync(VIEW, 'utf8');
  const open = blade.indexOf('<script>');
  const close = blade.indexOf('</script>', open);
  let js = blade.slice(open + '<script>'.length, close);

  // @json(...) — matched by counting parens, since the argument contains its own.
  let out = '';
  let cursor = 0;
  for (;;) {
    const at = js.indexOf('@json(', cursor);
    if (at === -1) {
      out += js.slice(cursor);
      break;
    }
    out += js.slice(cursor, at);
    let depth = 0;
    let i = at + '@json'.length;
    for (; i < js.length; i++) {
      if (js[i] === '(') depth++;
      else if (js[i] === ')' && --depth === 0) break;
    }
    out += '[]';
    cursor = i + 1;
  }

  // {{ $supplier->id }} and friends are ids; 0 stands in for them.
  return out.replace(/\{\{.*?\}\}/g, '0');
}

const SCRIPT = itemsScript();

const PAGE = `
  <span id="itemCount">0</span>
  <span id="draftStatus"></span>
  <p id="productFormError" class="hidden"></p>
  <div id="draftRestored" class="hidden"></div>
  <div id="itemsList"></div>
  <span id="subtotalDisplay"></span>
  <span id="grandTotal"></span>
`;

const KEY = 'gp.procurement.items-draft';

/** Boots the step against a given localStorage, and hands back the window. */
function boot(seed = {}, { blocked = false } = {}) {
  document.body.innerHTML = PAGE;

  const store = { ...seed };
  Object.defineProperty(window, 'localStorage', {
    configurable: true,
    value: blocked
      ? {
          getItem() { throw new Error('blocked'); },
          setItem() { throw new Error('blocked'); },
          removeItem() { throw new Error('blocked'); },
        }
      : {
          getItem: (k) => (k in store ? store[k] : null),
          setItem: (k, v) => { store[k] = String(v); },
          removeItem: (k) => { delete store[k]; },
        },
  });

  // eslint-disable-next-line no-eval
  window.eval(SCRIPT);

  return store;
}

function draftOf(supplier, store, items, savedAt = Date.now()) {
  return { [KEY]: JSON.stringify({ supplier, store, savedAt, items }) };
}

function row(overrides = {}) {
  return {
    product_id: '',
    product_query: '',
    barcode: '',
    quantity: 1,
    unit_cost: '',
    selling_price: '',
    ...overrides,
  };
}

describe('procurement items draft', () => {
  beforeEach(() => {
    document.body.innerHTML = '';
  });

  it('does not store an untouched empty row', () => {
    const store = boot();
    window.eval('writeDraft()');

    expect(KEY in store).toBe(false);
  });

  it('stores what has been typed, including a product not yet picked', () => {
    const store = boot();

    document.querySelector('.product-search').value = 'Itel 20000mAh';
    document.querySelector('[name*="[unit_cost]"]').value = '21000';
    document.querySelector('[name*="[selling_price]"]').value = '28000';
    window.eval('writeDraft()');

    const draft = JSON.parse(store[KEY]);

    expect(draft.items[0]).toMatchObject({
      product_query: 'Itel 20000mAh',
      unit_cost: '21000',
      selling_price: '28000',
    });
    expect(document.getElementById('draftStatus').textContent).toBe('Draft saved');
  });

  it('rebuilds every row on the next load', () => {
    boot(draftOf(0, 0, [
      row({ product_query: 'Itel 20000mAh', barcode: 'X1', quantity: 3, unit_cost: '21000', selling_price: '28000' }),
      row({ product_query: 'Oraimo Charger', unit_cost: '4000', selling_price: '6500' }),
    ]));

    expect(document.querySelectorAll('.item-row')).toHaveLength(2);
    expect(document.querySelectorAll('.product-search')[0].value).toBe('Itel 20000mAh');
    expect(document.querySelectorAll('[name*="[barcode]"]')[0].value).toBe('X1');
    expect(document.querySelectorAll('[name*="[quantity]"]')[0].value).toBe('3');
    expect(document.querySelectorAll('[name*="[selling_price]"]')[1].value).toBe('6500');
  });

  it('says why there is already work on screen', () => {
    boot(draftOf(0, 0, [row({ product_query: 'Itel 20000mAh' })]));

    expect(document.getElementById('draftRestored').className).not.toContain('hidden');
  });

  it('ignores a draft belonging to another supplier', () => {
    boot(draftOf(99, 0, [row({ product_query: 'Another order' })]));

    expect(document.querySelector('.product-search').value).toBe('');
    expect(document.querySelectorAll('.item-row')).toHaveLength(1);
  });

  it('ignores a draft belonging to another branch', () => {
    boot(draftOf(0, 99, [row({ product_query: 'Other branch' })]));

    expect(document.querySelector('.product-search').value).toBe('');
  });

  it('ignores a draft older than a day', () => {
    const yesterday = Date.now() - 25 * 60 * 60 * 1000;
    boot(draftOf(0, 0, [row({ product_query: 'Yesterday' })], yesterday));

    expect(document.querySelector('.product-search').value).toBe('');
  });

  it('leaves a usable form when storage is blocked', () => {
    // Private browsing, or a device with site data switched off. The step
    // stops being recoverable, which is where it started — it must not stop
    // being fillable.
    boot({}, { blocked: true });
    window.eval('writeDraft()');

    expect(document.querySelectorAll('.item-row')).toHaveLength(1);
  });
});

describe('the draft must not lag behind the typing', () => {
  it('has written the last keystroke before the page can go away', () => {
    const store = boot();

    const cost = document.querySelector('[name*="[unit_cost]"]');
    cost.value = '150000';
    cost.dispatchEvent(new window.Event('input', { bubbles: true }));

    // The page goes away immediately — no time for a debounce to elapse.
    window.dispatchEvent(new window.Event('pagehide'));

    expect(KEY in store).toBe(true);
    expect(JSON.parse(store[KEY]).items[0].unit_cost).toBe('150000');
  });

  it('writes the moment a field is left, without waiting', () => {
    const store = boot();

    const cost = document.querySelector('[name*="[unit_cost]"]');
    cost.value = '150000';
    // change is what fires when focus moves away — clicking anywhere else.
    cost.dispatchEvent(new window.Event('change', { bubbles: true }));

    expect(KEY in store).toBe(true);
    expect(JSON.parse(store[KEY]).items[0].unit_cost).toBe('150000');
  });

  it('writes when the tab is backgrounded', () => {
    const store = boot();

    const price = document.querySelector('[name*="[selling_price]"]');
    price.value = '160000';
    price.dispatchEvent(new window.Event('input', { bubbles: true }));

    Object.defineProperty(document, 'visibilityState', {
      configurable: true,
      get: () => 'hidden',
    });
    document.dispatchEvent(new window.Event('visibilitychange'));

    expect(KEY in store).toBe(true);
    expect(JSON.parse(store[KEY]).items[0].selling_price).toBe('160000');
  });
});

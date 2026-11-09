/* Perfumarathi & Fixnote storefront. All cart maths comes from the WooCommerce Store API (server-side). */
(function () {
  'use strict';
  var C = window.PMShop || {};
  var nonce = '';
  var cart = null;
  var $ = function (s, r) { return (r || document).querySelector(s); };
  var $$ = function (s, r) { return Array.prototype.slice.call((r || document).querySelectorAll(s)); };

  function esc(s) {
    return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) {
      return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
    });
  }
  // Allow only the harmless tags WooCommerce emits in price HTML (already kses'd server-side).
  function priceHtml(h) { return String(h || ''); }

  function toast(msg, type) {
    var box = $('.pm-toasts'); if (!box) return;
    var t = document.createElement('div');
    t.className = 'pm-toast' + (type === 'error' ? ' pm-toast--error' : '');
    t.textContent = msg; box.appendChild(t);
    setTimeout(function () { t.classList.add('is-out'); setTimeout(function () { t.remove(); }, 300); }, 3200);
  }

  /* ---------- Store API ---------- */
  function api(path, method, body) {
    var h = { 'Content-Type': 'application/json' };
    if (nonce) h['Nonce'] = nonce;
    return fetch(C.store + path, { method: method || 'GET', headers: h, credentials: 'same-origin', body: body ? JSON.stringify(body) : undefined })
      .then(function (r) {
        var n = r.headers.get('Nonce') || r.headers.get('X-WC-Store-API-Nonce');
        if (n) nonce = n;
        return r.json().then(function (j) { if (!r.ok) { var e = new Error(j.message || 'Request failed'); e.data = j; throw e; } return j; });
      });
  }
  function loadCart() { return api('cart').then(setCart).catch(function () {}); }
  function setCart(c) { cart = c; renderCount(); renderDrawer(); return c; }
  function money(minor, t) {
    var u = (t && t.currency_minor_unit) != null ? t.currency_minor_unit : 2;
    var v = (parseInt(minor, 10) || 0) / Math.pow(10, u);
    return (t && t.currency_prefix || '') + v.toFixed(u) + (t && t.currency_suffix || '');
  }
  function renderCount() {
    var n = cart ? cart.items_count : 0;
    $$('[data-pm-count]').forEach(function (e) { e.textContent = n; e.hidden = !n; });
  }
  function addToCart(id, qty, variation) {
    var body = { id: id, quantity: qty || 1 };
    if (variation) body.variation = variation;
    return api('cart/add-item', 'POST', body).then(function (c) { setCart(c); toast('Added to your bag'); openDrawer(); return c; })
      .catch(function (e) { toast(e.message.replace(/<[^>]+>/g, ''), 'error'); });
  }

  /* ---------- Drawer ---------- */
  var lastFocus;
  function openDrawer() {
    var d = $('#pm-drawer'); if (!d) return;
    lastFocus = document.activeElement;
    d.classList.add('is-open'); d.setAttribute('aria-hidden', 'false'); $('.pm-overlay').hidden = false;
    document.documentElement.classList.add('pm-lock');
    var x = $('.pm-x', d); if (x) x.focus();
  }
  function closeAll() {
    var d = $('#pm-drawer'), m = $('#pm-modal');
    if (d) { d.classList.remove('is-open'); d.setAttribute('aria-hidden', 'true'); }
    if (m) { m.classList.remove('is-open'); m.setAttribute('aria-hidden', 'true'); m.innerHTML = ''; }
    var o = $('.pm-overlay'); if (o) o.hidden = true;
    document.documentElement.classList.remove('pm-lock');
    if (lastFocus && lastFocus.focus) lastFocus.focus();
  }
  function renderDrawer() {
    var body = $('.pm-drawer__body'), foot = $('.pm-drawer__foot'); if (!body) return;
    if (!cart || !cart.items.length) {
      body.innerHTML = '<div class="pm-empty"><svg viewBox="0 0 24 24" aria-hidden="true"><path d="M6 7h12l1 13H5L6 7Z"/><path d="M9 7a3 3 0 0 1 6 0"/></svg><h3>Your bag is empty</h3><p>Discover a fragrance you will love.</p></div>';
      foot.innerHTML = '<a class="pm-btn" href="' + esc(C.shopUrl) + '">Continue Shopping</a>';
      return;
    }
    var t = cart.totals;
    body.innerHTML = cart.items.map(function (it) {
      var img = it.images && it.images[0] ? it.images[0].thumbnail : '';
      var vars = (it.variation || []).map(function (v) { return esc(v.value); }).join(' / ');
      return '<div class="pm-line" data-key="' + esc(it.key) + '">' +
        (img ? '<img src="' + esc(img) + '" alt="" loading="lazy">' : '<span class="pm-line__ph"></span>') +
        '<div class="pm-line__info"><strong>' + esc(it.name) + '</strong>' + (vars ? '<small>' + vars + '</small>' : '') +
        '<div class="pm-qty"><button type="button" data-qty="-1" aria-label="Decrease quantity">&minus;</button><span>' + esc(it.quantity) + '</span><button type="button" data-qty="1" aria-label="Increase quantity">+</button></div></div>' +
        '<div class="pm-line__right"><span>' + money(it.totals.line_total, it.totals) + '</span><button type="button" class="pm-link" data-remove>Remove</button></div></div>';
    }).join('');
    var disc = parseInt(t.total_discount, 10) > 0 ? '<div class="pm-row"><span>Discount</span><span>&minus;' + money(t.total_discount, t) + '</span></div>' : '';
    var coupons = (cart.coupons || []).map(function (c) { return '<span class="pm-chip">' + esc(c.code) + ' <button type="button" data-rm-coupon="' + esc(c.code) + '" aria-label="Remove coupon ' + esc(c.code) + '">&times;</button></span>'; }).join('');
    foot.innerHTML =
      '<form class="pm-coupon"><label class="screen-reader-text" for="pm-coupon-in">Coupon code</label><input id="pm-coupon-in" name="code" placeholder="Coupon code" autocomplete="off"><button class="pm-btn pm-btn--ghost" type="submit">Apply</button></form>' + coupons +
      '<div class="pm-row"><span>Subtotal</span><span>' + money(t.total_items, t) + '</span></div>' + disc +
      '<p class="pm-note">Shipping and taxes are calculated at checkout.</p>' +
      '<div class="pm-row pm-row--total"><span>Estimated total</span><span>' + money(t.total_price, t) + '</span></div>' +
      '<a class="pm-btn" href="' + esc(C.checkout) + '">Proceed to Checkout</a>' +
      '<a class="pm-link pm-center" href="' + esc(C.shopUrl) + '">Continue Shopping</a>';
  }

  /* ---------- Product cards ---------- */
  var wish = (function () { try { return JSON.parse(localStorage.getItem('pm_wish') || '[]'); } catch (e) { return []; } })();
  function saveWish() { try { localStorage.setItem('pm_wish', JSON.stringify(wish)); } catch (e) {} }

  function stars(r) { var n = Math.round(r); return '<span class="pm-stars" aria-label="Rated ' + r.toFixed(1) + ' out of 5">' + '★★★★★'.slice(0, n) + '<span class="pm-stars__off">' + '★★★★★'.slice(n) + '</span></span>'; }

  function card(p) {
    var w = wish.indexOf(p.id) > -1;
    var buy = p.purchasable
      ? (p.simple
        ? '<button class="pm-btn pm-btn--sm" data-add="' + p.id + '">Add to Cart</button><button class="pm-btn pm-btn--sm pm-btn--ghost" data-buy="' + p.id + '">Buy Now</button>'
        : '<a class="pm-btn pm-btn--sm" href="' + esc(p.url) + '">Choose Size</a>')
      : '<button class="pm-btn pm-btn--sm" disabled>Out of stock</button>';
    return '<article class="pm-card" data-id="' + p.id + '">' +
      '<div class="pm-card__media"><a href="' + esc(p.url) + '"><img src="' + esc(p.image) + '" alt="' + esc(p.name) + '" loading="lazy" width="300" height="300"></a>' +
      (p.on_sale ? '<span class="pm-badge">Sale</span>' : '') +
      '<button class="pm-wish' + (w ? ' is-on' : '') + '" data-wish="' + p.id + '" aria-pressed="' + w + '" aria-label="Add ' + esc(p.name) + ' to wishlist">♥</button>' +
      '<button class="pm-qv" data-qv="' + p.id + '">Quick View</button></div>' +
      '<div class="pm-card__body"><span class="pm-card__brand">' + esc(p.brand) + '</span>' +
      '<h3><a href="' + esc(p.url) + '">' + esc(p.name) + '</a></h3>' +
      (p.reviews ? '<div class="pm-card__rating">' + stars(p.rating) + ' <small>(' + p.reviews + ')</small></div>' : '') +
      '<div class="pm-card__price">' + priceHtml(p.price_html) + '</div>' +
      '<small class="pm-stock ' + (p.in_stock ? 'in' : 'out') + '">' + esc(p.stock_label) + '</small>' +
      '<div class="pm-card__actions">' + buy + '</div></div></article>';
  }
  function skeleton(n) { var s = ''; for (var i = 0; i < n; i++) s += '<div class="pm-card pm-skel" aria-hidden="true"><div class="pm-card__media"></div><div class="pm-card__body"><i></i><i></i><i></i></div></div>'; return s; }

  function fetchProducts(params) {
    var q = Object.keys(params).filter(function (k) { return params[k] !== '' && params[k] != null; })
      .map(function (k) { return encodeURIComponent(k) + '=' + encodeURIComponent(params[k]); }).join('&');
    return fetch(C.rest + 'products?' + q).then(function (r) { if (!r.ok) throw new Error('Could not load products'); return r.json(); });
  }
  function emptyGrid(el) {
    el.innerHTML = '<div class="pm-empty pm-empty--grid"><h3>No perfumes found</h3><p>Try a different search or category.</p></div>';
  }

  function initShop(root) {
    var grid = $('.pm-grid', root), more = $('.pm-more', root), page = 1, timer;
    var st = { brand: root.dataset.brand, category: root.dataset.category, collection: root.dataset.collection, per_page: root.dataset.perPage || 12 };
    function load(append) {
      if (!append) { page = 1; grid.innerHTML = skeleton(6); }
      var p = Object.assign({}, st, { page: page, search: $('.pm-search', root).value.trim(), orderby: $('.pm-sort', root).value });
      var cat = $('.pm-cat', root); if (cat) p.category = cat.value;
      fetchProducts(p).then(function (d) {
        if (!append) grid.innerHTML = '';
        if (!d.items.length && !append) return emptyGrid(grid);
        grid.insertAdjacentHTML('beforeend', d.items.map(card).join(''));
        more.hidden = page >= d.pages;
      }).catch(function () { grid.innerHTML = '<p class="pm-error">Products could not be loaded. Please refresh.</p>'; });
    }
    $('.pm-search', root).addEventListener('input', function () { clearTimeout(timer); timer = setTimeout(function () { load(false); }, 350); });
    $('.pm-sort', root).addEventListener('change', function () { load(false); });
    var cat = $('.pm-cat', root); if (cat) cat.addEventListener('change', function () { load(false); });
    more.addEventListener('click', function () { page++; load(true); });
    load(false);
  }

  function initRail(sec) {
    var grid = $('.pm-grid', sec), cfg = {};
    try { cfg = JSON.parse(sec.dataset.pmRail); } catch (e) {}
    grid.innerHTML = skeleton(4);
    fetchProducts(Object.assign({ per_page: 4 }, cfg)).then(function (d) {
      if (!d.items.length) { sec.hidden = true; return; } // no empty sections on the homepage
      grid.innerHTML = d.items.map(card).join('');
    }).catch(function () { sec.hidden = true; });
  }

  /* ---------- Quick view ---------- */
  function quickView(id) {
    var m = $('#pm-modal'); lastFocus = document.activeElement;
    m.innerHTML = '<div class="pm-modal__box"><p>Loading&hellip;</p></div>';
    m.classList.add('is-open'); m.setAttribute('aria-hidden', 'false'); $('.pm-overlay').hidden = false;
    fetch(C.rest + 'products/' + id).then(function (r) { if (!r.ok) throw 0; return r.json(); }).then(function (p) {
      var notes = Object.keys(p.notes || {}).filter(function (k) { return p.notes[k]; }).map(function (k) { return '<li><strong>' + esc(k.charAt(0).toUpperCase() + k.slice(1)) + ':</strong> ' + esc(p.notes[k]) + '</li>'; }).join('');
      var sizes = p.sizes.length ? '<ul class="pm-sizes">' + p.sizes.map(function (s) { return '<li>' + esc(s.label) + ' &mdash; ' + priceHtml(s.price_html) + (s.in_stock ? '' : ' (out of stock)') + '</li>'; }).join('') + '</ul>' : '';
      m.innerHTML = '<div class="pm-modal__box"><button class="pm-x" data-pm-close aria-label="Close quick view">&times;</button>' +
        '<div class="pm-modal__grid"><img src="' + esc(p.gallery[0] || p.image) + '" alt="' + esc(p.name) + '">' +
        '<div><span class="pm-card__brand">' + esc(p.brand) + '</span><h2>' + esc(p.name) + '</h2><div class="pm-card__price">' + priceHtml(p.price_html) + '</div>' +
        '<div class="pm-prose">' + (p.excerpt || '') + '</div>' + (notes ? '<ul class="pm-notes-list">' + notes + '</ul>' : '') + sizes +
        '<div class="pm-card__actions">' + (p.simple && p.purchasable ? '<button class="pm-btn" data-add="' + p.id + '">Add to Cart</button>' : '') +
        '<a class="pm-btn pm-btn--ghost" href="' + esc(p.url) + '">' + (p.simple ? 'Full Details' : 'Choose Size') + '</a></div></div></div></div>';
      var x = $('.pm-x', m); if (x) x.focus();
    }).catch(function () { m.innerHTML = '<div class="pm-modal__box"><button class="pm-x" data-pm-close aria-label="Close">&times;</button><p>Could not load this product.</p></div>'; });
  }

  /* ---------- Tracking form ---------- */
  function initTrack(f) {
    f.addEventListener('submit', function (e) {
      e.preventDefault();
      var out = $('.pm-track__result', f); out.textContent = 'Checking…';
      fetch(C.rest + 'track', { method: 'POST', headers: { 'Content-Type': 'application/json' }, body: JSON.stringify({ order: f.order.value, email: f.email.value }) })
        .then(function (r) { return r.json().then(function (j) { return { ok: r.ok, j: j }; }); })
        .then(function (x) { out.innerHTML = x.ok ? '<h3>Order #' + esc(x.j.order) + '</h3>' + x.j.html : '<p class="pm-error">' + esc(x.j.message || 'Not found') + '</p>'; })
        .catch(function () { out.innerHTML = '<p class="pm-error">Something went wrong. Please try again.</p>'; });
    });
  }

  /* ---------- Events ---------- */
  document.addEventListener('click', function (e) {
    var t = e.target.closest('button,a,[data-pm-close]'); if (!t) return;
    if (t.hasAttribute('data-pm-open-cart')) { e.preventDefault(); openDrawer(); return; }
    if (t.hasAttribute('data-pm-close')) { closeAll(); return; }
    if (t.dataset.add) { addToCart(parseInt(t.dataset.add, 10), 1); return; }
    if (t.dataset.buy) { api('cart/add-item', 'POST', { id: parseInt(t.dataset.buy, 10), quantity: 1 }).then(function () { window.location.href = C.checkout; }).catch(function (er) { toast(er.message.replace(/<[^>]+>/g, ''), 'error'); }); return; }
    if (t.dataset.qv) { quickView(t.dataset.qv); return; }
    if (t.dataset.wish) {
      var id = parseInt(t.dataset.wish, 10), i = wish.indexOf(id);
      if (i > -1) wish.splice(i, 1); else wish.push(id);
      saveWish(); t.classList.toggle('is-on', i === -1); t.setAttribute('aria-pressed', String(i === -1));
      toast(i === -1 ? 'Saved to wishlist' : 'Removed from wishlist'); return;
    }
    var line = t.closest('.pm-line');
    if (line && t.dataset.qty) {
      var it = cart.items.filter(function (x) { return x.key === line.dataset.key; })[0];
      api('cart/update-item', 'POST', { key: line.dataset.key, quantity: Math.max(0, it.quantity + parseInt(t.dataset.qty, 10)) }).then(setCart).catch(function (er) { toast(er.message.replace(/<[^>]+>/g, ''), 'error'); });
      return;
    }
    if (line && t.hasAttribute('data-remove')) { api('cart/remove-item', 'POST', { key: line.dataset.key }).then(setCart); return; }
    if (t.dataset.rmCoupon) { api('cart/remove-coupon', 'POST', { code: t.dataset.rmCoupon }).then(setCart); return; }
  });
  document.addEventListener('submit', function (e) {
    if (!e.target.classList.contains('pm-coupon')) return;
    e.preventDefault();
    var code = e.target.code.value.trim(); if (!code) return;
    api('cart/apply-coupon', 'POST', { code: code }).then(function (c) { setCart(c); toast('Coupon applied'); })
      .catch(function (er) { toast(er.message.replace(/<[^>]+>/g, ''), 'error'); });
  });
  document.addEventListener('keydown', function (e) {
    if (e.key === 'Escape') closeAll();
    if (e.key === 'Tab') { // focus trap in open dialogs
      var d = $('.pm-drawer.is-open') || $('.pm-modal.is-open'); if (!d) return;
      var f = $$('button,a[href],input,select,[tabindex]:not([tabindex="-1"])', d).filter(function (x) { return !x.disabled; });
      if (!f.length) return;
      if (e.shiftKey && document.activeElement === f[0]) { e.preventDefault(); f[f.length - 1].focus(); }
      else if (!e.shiftKey && document.activeElement === f[f.length - 1]) { e.preventDefault(); f[0].focus(); }
    }
  });
  // Refresh counter when other WooCommerce scripts change the cart.
  if (window.jQuery) window.jQuery(document.body).on('added_to_cart removed_from_cart wc_fragments_refreshed', loadCart);

  function boot() {
    $$('.pm-shop').forEach(initShop);
    $$('[data-pm-rail]').forEach(initRail);
    $$('.pm-track').forEach(initTrack);
    loadCart();
    // Subtle scroll reveal (skipped for reduced motion).
    if ('IntersectionObserver' in window && !window.matchMedia('(prefers-reduced-motion: reduce)').matches) {
      var io = new IntersectionObserver(function (es) { es.forEach(function (en) { if (en.isIntersecting) { en.target.classList.add('is-in'); io.unobserve(en.target); } }); }, { threshold: .1 });
      $$('.pm-home__section').forEach(function (s) { s.classList.add('pm-reveal'); io.observe(s); });
    }
  }
  if (document.readyState === 'loading') document.addEventListener('DOMContentLoaded', boot); else boot();
})();

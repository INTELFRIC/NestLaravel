/* NestLaravel portal — vanilla JS, no dependencies. */
(function () {
  'use strict';
  var root = document.documentElement;
  var reduce = window.matchMedia && matchMedia('(prefers-reduced-motion: reduce)').matches;

  /* ---- theme: dark → light → system ---- */
  var themeBtn = document.getElementById('theme');
  var order = ['dark', 'light', 'system'];
  var icons = { dark: '☾', light: '☀', system: '◐' };
  function applyTheme(pref) {
    if (pref === 'system') root.removeAttribute('data-theme'); else root.setAttribute('data-theme', pref);
    root.setAttribute('data-theme-pref', pref);
    if (themeBtn) { themeBtn.textContent = icons[pref]; themeBtn.setAttribute('aria-label', 'Theme: ' + pref + ' (click to change)'); themeBtn.title = 'Theme: ' + pref; }
  }
  if (themeBtn) {
    applyTheme(root.getAttribute('data-theme-pref') || 'dark');
    themeBtn.addEventListener('click', function () {
      var cur = root.getAttribute('data-theme-pref') || 'dark';
      var next = order[(order.indexOf(cur) + 1) % order.length];
      try { localStorage.setItem('nl-theme', next); } catch (e) {}
      applyTheme(next);
    });
  }

  /* ---- mobile menu ---- */
  var menuBtn = document.getElementById('menu');
  var nav = document.getElementById('primary-nav');
  if (menuBtn && nav) {
    menuBtn.addEventListener('click', function () {
      var open = nav.classList.toggle('open');
      menuBtn.setAttribute('aria-expanded', String(open));
    });
    nav.addEventListener('click', function (e) { if (e.target.tagName === 'A') { nav.classList.remove('open'); menuBtn.setAttribute('aria-expanded', 'false'); } });
  }

  /* ---- copy buttons (event delegation) ---- */
  function copyText(text, btn) {
    var done = function () { var o = btn.textContent; btn.textContent = 'Copied'; btn.setAttribute('aria-live', 'polite'); setTimeout(function () { btn.textContent = o === 'Copied' ? 'Copy' : o; }, 1600); };
    if (navigator.clipboard && window.isSecureContext) { navigator.clipboard.writeText(text).then(done, function () { fallback(); }); } else { fallback(); }
    function fallback() {
      var ta = document.createElement('textarea'); ta.value = text; ta.setAttribute('readonly', ''); ta.style.position = 'fixed'; ta.style.opacity = '0';
      document.body.appendChild(ta); ta.select(); try { document.execCommand('copy'); done(); } catch (e) { btn.textContent = 'Ctrl+C'; } document.body.removeChild(ta);
    }
  }
  document.addEventListener('click', function (e) {
    var btn = e.target.closest && e.target.closest('button.copy');
    if (!btn) return;
    var explicit = btn.getAttribute('data-copy');
    var pre = btn.parentElement.querySelector('pre');
    copyText(explicit != null ? explicit : (pre ? pre.innerText.replace(/\s+$/, '') : ''), btn);
  });

  /* ---- tabs (WAI-ARIA) ---- */
  document.querySelectorAll('[data-tabs]').forEach(function (group) {
    var tabs = Array.prototype.slice.call(group.querySelectorAll('[role="tab"]'));
    function select(tab, focus) {
      tabs.forEach(function (t) {
        var on = t === tab;
        t.setAttribute('aria-selected', String(on)); t.tabIndex = on ? 0 : -1;
        document.getElementById(t.getAttribute('aria-controls')).hidden = !on;
      });
      if (focus) tab.focus();
    }
    tabs.forEach(function (t, i) {
      t.addEventListener('click', function () { select(t); });
      t.addEventListener('keydown', function (e) {
        if (e.key === 'ArrowRight') select(tabs[(i + 1) % tabs.length], true);
        else if (e.key === 'ArrowLeft') select(tabs[(i - 1 + tabs.length) % tabs.length], true);
        else if (e.key === 'Home') select(tabs[0], true); else if (e.key === 'End') select(tabs[tabs.length - 1], true);
      });
    });
  });

  /* ---- scroll reveal + active section ---- */
  if ('IntersectionObserver' in window && !reduce) {
    var io = new IntersectionObserver(function (es) { es.forEach(function (e) { if (e.isIntersecting) { e.target.classList.add('in'); io.unobserve(e.target); } }); }, { threshold: 0.12 });
    document.querySelectorAll('.reveal').forEach(function (el) { io.observe(el); });
  } else { document.querySelectorAll('.reveal').forEach(function (el) { el.classList.add('in'); }); }

  var links = Array.prototype.slice.call(document.querySelectorAll('nav.primary a[href^="/#"]'));
  if (links.length && 'IntersectionObserver' in window) {
    var map = {}; links.forEach(function (a) { map[a.getAttribute('href').slice(2)] = a; });
    var so = new IntersectionObserver(function (es) {
      es.forEach(function (e) {
        if (e.isIntersecting && map[e.target.id]) { links.forEach(function (a) { a.classList.remove('active'); }); map[e.target.id].classList.add('active'); }
      });
    }, { rootMargin: '-40% 0px -55% 0px' });
    Object.keys(map).forEach(function (id) { var s = document.getElementById(id); if (s) so.observe(s); });
  }

  /* ---- terminal typing effect ---- */
  var typed = document.getElementById('typed');
  if (typed) {
    var full = typed.getAttribute('data-text');
    if (reduce) { typed.textContent = full; } else {
      var i = 0; typed.textContent = '';
      (function tick() { typed.textContent = full.slice(0, ++i); if (i < full.length) setTimeout(tick, 45); })();
    }
  }

  /* ---- search (documentation) ---- */
  var dlg = document.getElementById('search');
  if (dlg && typeof dlg.showModal === 'function') {
    var q = document.getElementById('q'), list = document.getElementById('results'), index = null, active = -1;
    function load() { return index ? Promise.resolve(index) : fetch('/search-index.json').then(function (r) { return r.json(); }).then(function (j) { index = j; return j; }); }
    function esc(s) { return s.replace(/[&<>"]/g, function (c) { return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;' }[c]; }); }
    function mark(text, terms) { var out = esc(text); terms.forEach(function (t) { out = out.replace(new RegExp('(' + t.replace(/[.*+?^${}()|[\]\\]/g, '\\$&') + ')', 'ig'), '<mark>$1</mark>'); }); return out; }
    function run() {
      var terms = q.value.trim().toLowerCase().split(/\s+/).filter(Boolean);
      if (!terms.length) { list.innerHTML = '<li><small style="padding:10px 12px;display:block">Type to search the documentation…</small></li>'; return; }
      load().then(function (idx) {
        var hits = [];
        idx.forEach(function (p) {
          var score = 0, snippet = '', hay = p.x.toLowerCase();
          terms.forEach(function (t) {
            if (p.t.toLowerCase().indexOf(t) > -1) score += 10;
            p.h.forEach(function (h) { if (h.t.toLowerCase().indexOf(t) > -1) { score += 4; hits.push({ p: p, h: h, score: score }); } });
            var at = hay.indexOf(t); if (at > -1) { score += 1; if (!snippet) snippet = p.x.slice(Math.max(0, at - 40), at + 100); }
          });
          if (score) hits.push({ p: p, snippet: snippet, score: score });
        });
        var seen = {}; hits = hits.sort(function (a, b) { return b.score - a.score; }).filter(function (h) { var k = h.p.u + (h.h ? '#' + h.h.id : ''); if (seen[k]) return false; seen[k] = 1; return true; }).slice(0, 12);
        list.innerHTML = hits.length ? hits.map(function (h) {
          var url = h.p.u + (h.h ? '#' + h.h.id : '');
          return '<li><a href="' + url + '"><strong>' + mark(h.h ? h.h.t : h.p.t, terms) + '</strong><small>' + esc(h.p.g) + ' › ' + esc(h.p.t) + (h.snippet ? ' — ' + mark(h.snippet, terms) : '') + '</small></a></li>';
        }).join('') : '<li><small style="padding:10px 12px;display:block">No results.</small></li>';
        active = -1;
      });
    }
    function openSearch() { dlg.showModal(); q.value = ''; run(); q.focus(); }
    document.querySelectorAll('[data-open-search]').forEach(function (b) { b.addEventListener('click', openSearch); });
    document.addEventListener('keydown', function (e) {
      var typing = /^(INPUT|TEXTAREA|SELECT)$/.test((document.activeElement || {}).tagName || '');
      if ((e.key === '/' && !typing) || ((e.ctrlKey || e.metaKey) && e.key.toLowerCase() === 'k')) { e.preventDefault(); openSearch(); }
    });
    q.addEventListener('input', run);
    q.addEventListener('keydown', function (e) {
      var items = list.querySelectorAll('a'); if (!items.length) return;
      if (e.key === 'ArrowDown') { active = (active + 1) % items.length; items[active].focus(); e.preventDefault(); }
      else if (e.key === 'Enter') { (items[Math.max(active, 0)]).click(); }
    });
    list.addEventListener('keydown', function (e) {
      var items = Array.prototype.slice.call(list.querySelectorAll('a')), i = items.indexOf(document.activeElement);
      if (e.key === 'ArrowDown') { (items[i + 1] || items[0]).focus(); e.preventDefault(); }
      else if (e.key === 'ArrowUp') { (i > 0 ? items[i - 1] : q).focus(); e.preventDefault(); }
    });
    dlg.addEventListener('click', function (e) { if (e.target === dlg) dlg.close(); });
  }
})();

/* Facilon shared form helpers: toast notifications, inline field errors,
   and a small JSON POST wrapper. Exposed as window.FacilonForms. */
(function () {
  'use strict';

  function ensureToastHost() {
    var h = document.getElementById('ff-toast-host');
    if (!h) {
      h = document.createElement('div');
      h.id = 'ff-toast-host';
      document.body.appendChild(h);
    }
    return h;
  }

  /** Show a transient toast. type: 'error' (default) | 'success'. */
  function toast(message, type) {
    var host = ensureToastHost();
    var el = document.createElement('div');
    el.className = 'ff-toast ' + (type === 'success' ? 'success' : 'error');
    el.setAttribute('role', 'alert');

    var ic = document.createElement('span'); ic.className = 'ff-toast-ic';
    var msg = document.createElement('span'); msg.className = 'ff-toast-msg'; msg.textContent = message;
    var x = document.createElement('button'); x.className = 'ff-toast-x'; x.type = 'button';
    x.setAttribute('aria-label', 'Dismiss'); x.innerHTML = '&times;';

    el.appendChild(ic); el.appendChild(msg); el.appendChild(x);
    host.appendChild(el);
    requestAnimationFrame(function () { el.classList.add('show'); });

    var close = function () {
      el.classList.remove('show');
      setTimeout(function () { if (el.parentNode) el.parentNode.removeChild(el); }, 320);
    };
    x.addEventListener('click', close);
    setTimeout(close, 6000);
    return el;
  }

  function scopeEl(scope) { return scope || document; }

  /** Show an inline error for a field keyed by data-error="key". */
  function showError(key, message, scope) {
    var s = scopeEl(scope);
    var box = s.querySelector('[data-error="' + key + '"]');
    if (box) { box.textContent = message; box.classList.add('show'); }
    var field = s.querySelector('[data-field="' + key + '"]');
    if (field) field.classList.add('has-error');
  }

  function clearError(key, scope) {
    var s = scopeEl(scope);
    var box = s.querySelector('[data-error="' + key + '"]');
    if (box) { box.textContent = ''; box.classList.remove('show'); }
    var field = s.querySelector('[data-field="' + key + '"]');
    if (field) field.classList.remove('has-error');
  }

  /** Clear all inline errors within a scope. */
  function clearAll(scope) {
    var s = scopeEl(scope);
    s.querySelectorAll('[data-error]').forEach(function (b) {
      b.textContent = ''; b.classList.remove('show');
    });
    s.querySelectorAll('.has-error').forEach(function (f) { f.classList.remove('has-error'); });
  }

  /** Render a map of {key: message} as inline errors; scrolls to the first. */
  function showErrors(errors, scope) {
    var first = null;
    Object.keys(errors || {}).forEach(function (key) {
      showError(key, errors[key], scope);
      if (!first) first = key;
    });
    if (first) {
      var s = scopeEl(scope);
      var el = s.querySelector('[data-error="' + first + '"]') || s.querySelector('[data-field="' + first + '"]');
      if (el && el.scrollIntoView) el.scrollIntoView({ behavior: 'smooth', block: 'center' });
    }
  }

  /** POST JSON. Resolves {ok, status, data}. Never rejects. */
  function post(url, data) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json' },
      body: JSON.stringify(data)
    }).then(function (res) {
      return res.json().catch(function () { return null; }).then(function (json) {
        return { ok: res.ok && json && json.success === true, status: res.status, data: json || {} };
      });
    }).catch(function () {
      return { ok: false, status: 0, data: { message: 'Network error. Please check your connection and try again.' } };
    });
  }

  /** A name: letters/spaces/.'- only (no digits), at least 2 chars. Unicode-aware. */
  function isName(v) {
    v = (v || '').trim();
    return v.length >= 2 && /^[\p{L}][\p{L} .'-]*$/u.test(v);
  }

  /** A mobile number: no letters, 10 to 15 digits (grouping chars allowed). */
  function isMobile(v) {
    v = (v || '').trim();
    if (/[A-Za-z]/.test(v)) return false;
    if (!/^[+()\d\s-]+$/.test(v)) return false;
    var digits = v.replace(/\D/g, '').length;
    return digits >= 10 && digits <= 15;
  }

  /* ---- sessionStorage helpers (carry multi-step data across pages) ---- */
  function save(key, val) {
    try { sessionStorage.setItem(key, JSON.stringify(val)); } catch (e) {}
  }
  function load(key) {
    try { return JSON.parse(sessionStorage.getItem(key)); } catch (e) { return null; }
  }
  function drop(key) {
    try { sessionStorage.removeItem(key); } catch (e) {}
  }

  window.FacilonForms = {
    isName: isName,
    isMobile: isMobile,
    save: save,
    load: load,
    drop: drop,
    toast: toast,
    showError: showError,
    clearError: clearError,
    clearAll: clearAll,
    showErrors: showErrors,
    post: post
  };
})();

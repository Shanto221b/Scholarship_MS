/* =====================================================================
   ScholarHub — front-end behaviour
   - AJAX form submission (login, register, apply, review, delete, save…)
   - Live search / filters without page reload
   - Live username / email availability check
   - Toast messages, cookie notice, mobile sidebar
   Every feature also works without JavaScript (normal form posts).
   ===================================================================== */
(function () {
    'use strict';

    var BASE = (document.querySelector('meta[name="base-url"]') || {}).content || '/';

    /* ---------------- small helpers ---------------- */
    function $(sel, root) { return (root || document).querySelector(sel); }
    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }
    function debounce(fn, ms) {
        var t;
        return function () {
            var args = arguments, ctx = this;
            clearTimeout(t);
            t = setTimeout(function () { fn.apply(ctx, args); }, ms);
        };
    }
    function escapeHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[c];
        });
    }
    function ajax(url, options) {
        options = options || {};
        options.headers = Object.assign({ 'X-Requested-With': 'XMLHttpRequest' }, options.headers || {});
        options.credentials = 'same-origin';
        return fetch(url, options);
    }

    /* ---------------- toasts ---------------- */
    function toast(type, message) {
        var stack = $('#toastStack');
        if (!stack || !message) { return; }
        var icons = { success: 'check-circle', danger: 'exclamation-circle', warning: 'exclamation-triangle', info: 'info-circle' };
        var el = document.createElement('div');
        el.className = 'app-toast toast-' + (type || 'info');
        el.setAttribute('role', 'status');
        el.innerHTML = '<i class="bi bi-' + (icons[type] || icons.info) + '"></i><div>' + escapeHtml(message) + '</div>';
        stack.appendChild(el);
        requestAnimationFrame(function () { el.classList.add('show'); });
        setTimeout(function () {
            el.classList.remove('show');
            setTimeout(function () { el.remove(); }, 300);
        }, type === 'danger' ? 6000 : 4000);
    }
    window.appToast = toast;

    /* ---------------- sidebar (mobile) ---------------- */
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-sidebar-toggle]')) {
            document.body.classList.toggle('sidebar-open');
        }
    });

    /* ---------------- cookie notice ---------------- */
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-cookie-ok]')) { return; }
        var exp = new Date(Date.now() + 365 * 864e5).toUTCString();
        document.cookie = 'SH_COOKIE_OK=1; expires=' + exp + '; path=' + BASE + '; SameSite=Lax';
        var box = $('#cookieNotice');
        if (box) {
            box.classList.add('hide');
            setTimeout(function () { box.remove(); }, 300);
        }
    });

    /* ---------------- light / dark mode (remembered in the SH_THEME cookie) ---------------- */
    document.addEventListener('click', function (e) {
        if (!e.target.closest('[data-theme-toggle]')) { return; }
        var root = document.documentElement;
        var next = root.getAttribute('data-bs-theme') === 'dark' ? 'light' : 'dark';
        root.classList.add('theme-switching');
        root.setAttribute('data-bs-theme', next);
        root.setAttribute('data-theme-pref', next);
        var exp = new Date(Date.now() + 365 * 864e5).toUTCString();
        document.cookie = 'SH_THEME=' + next + '; expires=' + exp + '; path=' + BASE + '; SameSite=Lax';
    });

    /* ---------------- simple tabs (profile) ---------------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('[data-tab]');
        if (!btn) { return; }
        var group = btn.closest('[data-tabs]');
        var scope = group.parentElement;
        $all('[data-tab]', group).forEach(function (b) { b.classList.toggle('active', b === btn); b.setAttribute('aria-selected', b === btn); });
        $all('.tab-pane-clean', scope).forEach(function (p) { p.classList.toggle('active', p.id === btn.dataset.tab); });
        try { history.replaceState(null, '', '#' + btn.dataset.tab); } catch (err) {}
    });
    (function () {
        var h = window.location.hash.slice(1);
        var btn = h && document.querySelector('[data-tab="' + CSS.escape(h) + '"]');
        if (btn) { btn.click(); }
    })();

    /* ---------------- bulk selection in tables ---------------- */
    function refreshBulk(scope) {
        var boxes = $all('input[data-row-check]', scope);
        var chosen = boxes.filter(function (b) { return b.checked; });
        var bar = scope.querySelector('[data-bulk-bar]');
        var all = scope.querySelector('input[data-check-all]');
        boxes.forEach(function (b) { var tr = b.closest('tr'); if (tr) { tr.classList.toggle('is-selected', b.checked); } });
        if (all) { all.checked = boxes.length > 0 && chosen.length === boxes.length; all.indeterminate = chosen.length > 0 && chosen.length < boxes.length; }
        if (bar) {
            bar.hidden = chosen.length === 0;
            var n = bar.querySelector('[data-bulk-count]');
            if (n) { n.textContent = chosen.length; }
            var holder = bar.querySelector('[data-bulk-ids]');
            if (holder) { holder.value = chosen.map(function (b) { return b.value; }).join(','); }
        }
    }
    document.addEventListener('change', function (e) {
        var scope = e.target.closest('[data-bulk-scope]');
        if (!scope) { return; }
        if (e.target.matches('input[data-check-all]')) {
            $all('input[data-row-check]', scope).forEach(function (b) { b.checked = e.target.checked; });
        }
        if (e.target.matches('input[data-check-all], input[data-row-check]')) { refreshBulk(scope); }
    });

    /* ---------------- photo upload: submit as soon as a file is chosen ---------------- */
    document.addEventListener('change', function (e) {
        if (!e.target.matches('input[data-autosubmit]')) { return; }
        var form = e.target.form;
        var f = e.target.files && e.target.files[0];
        if (!f) { return; }
        if (f.size > 2 * 1024 * 1024) { toast('danger', 'The photo must be 2 MB or smaller.'); e.target.value = ''; return; }
        if (form.requestSubmit) { form.requestSubmit(); } else { form.submit(); }
    });

    /* ---------------- print buttons ---------------- */
    document.addEventListener('click', function (e) {
        if (e.target.closest('[data-print]')) { e.preventDefault(); window.print(); }
    });

    /* ---------------- show / hide password ---------------- */
    document.addEventListener('click', function (e) {
        var btn = e.target.closest('.password-toggle');
        if (!btn) { return; }
        var input = btn.parentElement.querySelector('input');
        var show = input.type === 'password';
        input.type = show ? 'text' : 'password';
        btn.querySelector('i').className = show ? 'bi bi-eye-slash' : 'bi bi-eye';
        btn.setAttribute('aria-label', show ? 'Hide password' : 'Show password');
    });

    /* ---------------- client-side validation (server validates again) ---------------- */
    function checkPasswordMatch(form) {
        var pass = form.querySelector('[name="password"]');
        var confirm = form.querySelector('[name="confirm_password"]');
        if (pass && confirm) {
            confirm.setCustomValidity(confirm.value && pass.value !== confirm.value ? 'Passwords do not match.' : '');
        }
    }
    document.addEventListener('input', function (e) {
        var form = e.target.form;
        if (form && (e.target.name === 'password' || e.target.name === 'confirm_password')) {
            checkPasswordMatch(form);
        }
    });
    function formIsValid(form) {
        if (!form.classList.contains('needs-validation')) { return true; }
        checkPasswordMatch(form);
        var ok = form.checkValidity();
        form.classList.add('was-validated');
        if (!ok) {
            var first = form.querySelector(':invalid');
            if (first) { first.focus(); }
        }
        return ok;
    }

    /* ---------------- error list inside a form ---------------- */
    function errorBox(form) {
        return form.querySelector('[data-errors]') ||
            (form.closest('[data-form-scope]') || form.parentElement).querySelector('[data-errors]');
    }
    function showErrors(form, errors) {
        var box = errorBox(form);
        if (!box) { toast('danger', errors.join(' ')); return; }
        box.querySelector('ul').innerHTML = errors.map(function (m) { return '<li>' + escapeHtml(m) + '</li>'; }).join('');
        box.hidden = false;
        box.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
    }
    function hideErrors(form) {
        var box = errorBox(form);
        if (box) { box.hidden = true; }
    }

    /* ---------------- button loading state ---------------- */
    function setBusy(btn, busy) {
        if (!btn) { return; }
        if (busy) {
            btn.dataset.label = btn.innerHTML;
            btn.disabled = true;
            btn.innerHTML = '<span class="spinner-border" aria-hidden="true"></span>' + (btn.dataset.busy || 'Please wait…');
        } else if (btn.dataset.label !== undefined) {
            btn.innerHTML = btn.dataset.label;
            btn.disabled = false;
            delete btn.dataset.label;
        }
    }

    /* ---------------- form submit: confirm + AJAX ---------------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (form.method.toLowerCase() !== 'post' || form.hasAttribute('data-pay')) { return; }

        if (!formIsValid(form)) {
            e.preventDefault();
            return;
        }
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) {
            e.preventDefault();
            return;
        }

        var btn = e.submitter || form.querySelector('[type="submit"]');

        if (!form.hasAttribute('data-ajax')) {
            // normal post: keep the clicked button's value and block double clicks
            if (btn && btn.name) {
                var hidden = document.createElement('input');
                hidden.type = 'hidden'; hidden.name = btn.name; hidden.value = btn.value;
                form.appendChild(hidden);
            }
            setTimeout(function () { setBusy(btn, true); }, 0);
            return;
        }

        e.preventDefault();
        var data = new FormData(form);
        if (btn && btn.name) { data.append(btn.name, btn.value); }
        setBusy(btn, true);

        ajax(form.getAttribute('action') || window.location.href, { method: 'POST', body: data })
            .then(function (res) {
                return res.json().catch(function () { throw new Error('bad response'); });
            })
            .then(function (res) {
                if (res.redirect) {
                    window.location.href = res.redirect;
                    return;
                }
                setBusy(btn, false);
                if (res.errors && res.errors.length) {
                    showErrors(form, res.errors);
                    return;
                }
                hideErrors(form);
                if (res.message) { toast(res.type || (res.ok ? 'success' : 'danger'), res.message); }
                if (res.pending !== undefined) { updatePending(res.pending); }
                if (res.reloadLive) { document.dispatchEvent(new CustomEvent('live:reload')); }
                if (!res.ok) { return; }

                if (form.dataset.onSuccess === 'remove-row') {
                    var row = form.closest('tr');
                    if (row) {
                        row.classList.add('row-removing');
                        setTimeout(function () {
                            var tbody = row.parentElement;
                            row.remove();
                            document.dispatchEvent(new CustomEvent('rows:changed', { detail: { tbody: tbody } }));
                        }, 300);
                    }
                }
                if (res.html && form.dataset.replace) {
                    var target = $(form.dataset.replace);
                    if (target) { target.outerHTML = res.html; }
                }
                if (res.fragments) {
                    Object.keys(res.fragments).forEach(function (sel) {
                        $all(sel).forEach(function (el) { el.outerHTML = res.fragments[sel]; });
                    });
                }
                if (form.hasAttribute('data-reset')) {
                    form.reset();
                    form.classList.remove('was-validated');
                }
                if (res.photo) {
                    $all('.avatar, .avatar-xl').forEach(function (el) {
                        el.innerHTML = '<img src="' + escapeHtml(res.photo) + '" alt="">';
                    });
                }
                if (res.name) {
                    $all('.user-name').forEach(function (el) { el.textContent = res.name; });
                    $all('.avatar').forEach(function (el) { el.textContent = res.name.charAt(0).toUpperCase(); });
                }
            })
            .catch(function () {
                setBusy(btn, false);
                toast('danger', 'Could not reach the server. Please check your connection and try again.');
            });
    });

    /* ---------------- live search & filters (AJAX) ---------------- */
    $all('form[data-live]').forEach(function (form) {
        var target = $(form.dataset.live);
        if (!target) { return; }
        var controller = null;

        function load() {
            var params = new URLSearchParams(new FormData(form));
            Array.from(params.keys()).forEach(function (k) { if (params.get(k) === '') { params.delete(k); } });
            var query = params.toString();
            var pageUrl = window.location.pathname + (query ? '?' + query : '');
            params.set('partial', '1');

            if (controller) { controller.abort(); }
            controller = new AbortController();
            target.classList.add('is-loading');

            ajax(window.location.pathname + '?' + params.toString(), { signal: controller.signal })
                .then(function (res) {
                    if (!res.ok) { throw new Error('HTTP ' + res.status); }
                    return res.text();
                })
                .then(function (html) {
                    target.innerHTML = html;
                    target.classList.remove('is-loading');
                    var sc = target.closest('[data-bulk-scope]') || target.querySelector('[data-bulk-scope]');
                    if (sc) { refreshBulk(sc); }
                    history.replaceState(null, '', pageUrl);
                })
                .catch(function (err) {
                    if (err.name === 'AbortError') { return; }
                    target.classList.remove('is-loading');
                    toast('danger', 'Could not load results. Please try again.');
                });
        }

        form.addEventListener('submit', function (e) { e.preventDefault(); load(); });
        document.addEventListener('live:reload', load);
        form.addEventListener('change', function (e) {
            if (e.target.matches('select, input[type="checkbox"], input[type="radio"]')) { load(); }
        });
        var typed = debounce(load, 300);
        form.addEventListener('input', function (e) {
            if (e.target.matches('input[type="search"], input[type="text"]')) { typed(); }
        });

        // tab links inside the results set a hidden field of the form
        target.addEventListener('click', function (e) {
            var link = e.target.closest('[data-live-set]');
            if (!link) { return; }
            e.preventDefault();
            var parts = link.dataset.liveSet.split('=');
            var field = form.querySelector('[name="' + parts[0] + '"]');
            if (field) { field.value = parts.slice(1).join('='); }
            load();
        });
    });

    /* ---------------- username / email availability (AJAX) ---------------- */
    $all('input[data-check]').forEach(function (input) {
        var status = document.createElement('div');
        status.className = 'field-status';
        status.setAttribute('aria-live', 'polite');
        var anchor = input.closest('.password-field') || input;
        anchor.insertAdjacentElement('afterend', status);
        var lastValue = null;

        var run = debounce(function () {
            var value = input.value.trim();
            input.setCustomValidity('');
            if (value === lastValue) { return; }
            lastValue = value;
            if (value === '' || !input.checkValidity()) {
                status.className = 'field-status';
                status.textContent = '';
                return;
            }
            status.className = 'field-status checking';
            status.textContent = 'Checking…';
            var url = BASE + 'api/check.php?field=' + encodeURIComponent(input.dataset.check) + '&value=' + encodeURIComponent(value);
            ajax(url).then(function (r) { return r.json(); }).then(function (res) {
                if (input.value.trim() !== value) { return; }
                status.className = 'field-status ' + (res.available ? 'ok' : 'bad');
                status.innerHTML = '<i class="bi bi-' + (res.available ? 'check-circle' : 'x-circle') + '"></i> ' + escapeHtml(res.message);
                input.setCustomValidity(res.available ? '' : res.message);
            }).catch(function () {
                status.className = 'field-status';
                status.textContent = '';
            });
        }, 400);
        input.addEventListener('input', run);
        input.addEventListener('blur', run);
    });

    /* ---------------- admin: pending applications badge ---------------- */
    function updatePending(n) {
        $all('[data-pending-count]').forEach(function (el) {
            el.textContent = n;
            el.hidden = !n;
        });
        $all('[data-pending-count-text]').forEach(function (el) { el.textContent = n; });
    }

    /* ---------------- keep "N total" labels right after an AJAX delete ---------------- */
    document.addEventListener('rows:changed', function (e) {
        var tbody = e.detail.tbody;
        var card = tbody && tbody.closest('.content-card');
        var label = card && card.querySelector('[data-row-count]');
        var n = tbody ? tbody.querySelectorAll('tr').length : 0;
        if (label) { label.textContent = n + ' total'; }
        if (tbody && n === 0) {
            var cols = card.querySelectorAll('thead th').length || 1;
            tbody.innerHTML = '<tr><td colspan="' + cols + '"><div class="empty-state"><i class="bi bi-inbox"></i>Nothing here yet.</div></td></tr>';
        }
    });
    if ($('[data-pending-count]')) {
        setInterval(function () {
            if (document.hidden) { return; }
            ajax(BASE + 'api/summary.php').then(function (r) { return r.ok ? r.json() : null; }).then(function (res) {
                if (res && res.ok) { updatePending(res.pending); }
            }).catch(function () {});
        }, 30000);
    }

    /* ---------------- auto-hide success messages ---------------- */
    $all('.alert-success.alert-dismissible').forEach(function (el) {
        setTimeout(function () {
            if (window.bootstrap && bootstrap.Alert && document.body.contains(el)) {
                bootstrap.Alert.getOrCreateInstance(el).close();
            }
        }, 5000);
    });
})();

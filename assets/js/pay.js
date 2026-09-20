/* =====================================================================
   ScholarHub checkout: step-by-step payment without page reloads
   ===================================================================== */
(function () {
    'use strict';
    var card  = document.getElementById('payCard');
    var stage = document.getElementById('payStage');
    if (!card || !stage) { return; }

    function $all(sel, root) { return Array.prototype.slice.call((root || document).querySelectorAll(sel)); }

    /* ---------- countdown ---------- */
    var left = parseInt(card.dataset.secondsLeft || '0', 10);
    var timer = card.querySelector('[data-pay-timer]');
    function tick() {
        if (!timer) { return; }
        var m = Math.floor(left / 60), s = left % 60;
        timer.querySelector('span').textContent = m + ':' + (s < 10 ? '0' : '') + s;
        timer.classList.toggle('low', left <= 60);
        if (left <= 0) { window.location.reload(); return; }
        left--;
    }
    if (timer) { tick(); var tId = setInterval(tick, 1000); }
    function stopTimer() {
        if (timer) { clearInterval(tId); timer.remove(); timer = null; }
        var cancel = card.querySelector('.pay-foot form');
        if (cancel) {
            var a = document.createElement('a');
            a.href = (document.querySelector('meta[name="base-url"]') || {}).content + 'student/payments.php';
            a.textContent = 'My payments';
            cancel.replaceWith(a);
        }
    }

    /* ---------- SMS pop-up (simulates the text message in test mode) ---------- */
    var popup = document.getElementById('smsPopup'), popTimer;
    function showSms(sms) {
        if (!popup || !sms) { return; }
        popup.querySelector('[data-sms-from]').textContent = sms.from;
        popup.querySelector('[data-sms-text]').innerHTML = sms.text;   // built on the server, only digits inserted
        popup.classList.add('show');
        clearTimeout(popTimer);
        popTimer = setTimeout(function () { popup.classList.remove('show'); }, 7000);
    }
    if (popup) { popup.addEventListener('click', function () { popup.classList.remove('show'); }); }

    /* ---------- enhance the current step ---------- */
    function enhance() {
        // method tiles
        $all('.method-tile input', stage).forEach(function (r) {
            r.addEventListener('change', function () {
                $all('.method-tile', stage).forEach(function (t) { t.classList.toggle('selected', t.contains(r) && r.checked); });
            });
        });
        // digit boxes: auto-advance, backspace, paste
        $all('[data-code-inputs]', stage).forEach(function (wrap) {
            var boxes = $all('input', wrap);
            boxes.forEach(function (box, i) {
                box.addEventListener('input', function () {
                    box.value = box.value.replace(/\D/g, '').slice(-1);
                    if (box.value && boxes[i + 1]) { boxes[i + 1].focus(); }
                    if (boxes.every(function (b) { return b.value; })) {
                        var btn = wrap.closest('form').querySelector('[type="submit"]:not([formnovalidate])');
                        if (btn) { btn.focus(); }
                    }
                });
                box.addEventListener('keydown', function (e) {
                    if (e.key === 'Backspace' && !box.value && boxes[i - 1]) { boxes[i - 1].focus(); boxes[i - 1].value = ''; }
                    if (e.key === 'ArrowLeft' && boxes[i - 1]) { boxes[i - 1].focus(); }
                    if (e.key === 'ArrowRight' && boxes[i + 1]) { boxes[i + 1].focus(); }
                });
                box.addEventListener('paste', function (e) {
                    var digits = (e.clipboardData || window.clipboardData).getData('text').replace(/\D/g, '');
                    if (!digits) { return; }
                    e.preventDefault();
                    boxes.forEach(function (b, j) { if (digits[j - i] !== undefined && j >= i) { b.value = digits[j - i]; } });
                    (boxes[Math.min(boxes.length - 1, i + digits.length)] || box).focus();
                });
            });
        });
        // phone: digits only
        $all('.phone-input input', stage).forEach(function (inp) {
            inp.addEventListener('input', function () { inp.value = inp.value.replace(/\D/g, '').slice(0, 11); });
        });
        // card number groups + brand
        $all('[data-card-number]', stage).forEach(function (inp) {
            var brandEl = stage.querySelector('[data-card-brand]');
            inp.addEventListener('input', function () {
                var d = inp.value.replace(/\D/g, '').slice(0, 19);
                inp.value = d.replace(/(.{4})/g, '$1 ').trim();
                var b = /^4/.test(d) ? 'VISA' : /^(5[1-5]|2[2-7])/.test(d) ? 'MASTERCARD' : /^3[47]/.test(d) ? 'AMEX' : '';
                if (brandEl) { brandEl.textContent = b; }
            });
        });
        $all('[data-card-expiry]', stage).forEach(function (inp) {
            inp.addEventListener('input', function () {
                var d = inp.value.replace(/\D/g, '').slice(0, 4);
                inp.value = d.length > 2 ? d.slice(0, 2) + '/' + d.slice(2) : d;
            });
        });
        // resend countdown
        $all('[data-resend]', stage).forEach(function (btn) {
            var wait = parseInt(btn.dataset.resend, 10) || 0;
            var label = 'Resend code';
            function upd() {
                if (wait > 0) { btn.disabled = true; btn.textContent = label + ' (' + wait + 's)'; wait--; setTimeout(upd, 1000); }
                else { btn.disabled = false; btn.textContent = label; }
            }
            upd();
        });
        var first = stage.querySelector('input:not([type=hidden]):not([type=radio]):not([type=checkbox])');
        if (first && !first.value) { first.focus(); }
    }
    enhance();

    /* ---------- submit steps with AJAX ---------- */
    document.addEventListener('submit', function (e) {
        var form = e.target;
        if (!form.hasAttribute('data-pay')) { return; }
        var btn = e.submitter || form.querySelector('[type="submit"]');
        var skipValidation = btn && btn.hasAttribute('formnovalidate');
        if (!skipValidation && !form.checkValidity()) {
            e.preventDefault();
            form.reportValidity();
            return;
        }
        if (form.dataset.confirm && !window.confirm(form.dataset.confirm)) { e.preventDefault(); return; }
        e.preventDefault();

        var data = new FormData(form);
        if (btn && btn.name) { data.append(btn.name, btn.value); }
        var label = btn ? btn.innerHTML : '';
        if (btn) {
            btn.disabled = true;
            if (!skipValidation) { btn.innerHTML = '<span class="spinner-border" aria-hidden="true"></span>' + (btn.dataset.busy || 'Please wait…'); }
        }

        fetch(form.getAttribute('action'), {
            method: 'POST', body: data, credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); }).then(function (res) {
            if (res.redirect) { window.location.href = res.redirect; return; }
            var sameStep = res.error && res.step && res.step === stage.dataset.step;
            if (sameStep) {
                // same step with an error: keep what the user typed, just show the message
                if (btn) { btn.disabled = false; btn.innerHTML = label; }
                var errEl = stage.querySelector('[data-pay-error]');
                if (errEl) { errEl.textContent = res.error; }
            } else if (res.stage !== undefined) {
                stage.innerHTML = res.stage;
                stage.dataset.step = res.step || '';
                enhance();
            }
            if (res.error) {
                var codes = stage.querySelector('[data-code-inputs]');
                if (codes) {
                    codes.classList.remove('shake'); void codes.offsetWidth; codes.classList.add('shake');
                    $all('input', codes).forEach(function (b) { b.value = ''; });
                    var f = codes.querySelector('input'); if (f) { f.focus(); }
                }
            }
            if (res.sms) { showSms(res.sms); }
            if (res.notice && window.appToast) { window.appToast('success', res.notice); }
            if (res.status && res.status !== 'Initiated') { stopTimer(); }
        }).catch(function () {
            if (btn) { btn.disabled = false; btn.innerHTML = label; }
            if (window.appToast) { window.appToast('danger', 'Connection problem. Please try again.'); }
        });
    });
})();

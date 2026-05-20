(function () {
    let activeField = null;

    function trackFocus(e) {
        const el = e.target;
        if (el.matches('input[type="text"], input[type="email"], textarea')) {
            activeField = el;
        }
    }

    function injectButtons(anchorId) {
        const anchor = document.getElementById(anchorId);
        if (!anchor) return;

        const marker = 'simplelogin-var-btn-wrap--' + anchorId;
        if (document.querySelector('.' + marker)) return;

        if (!window._simpleloginFocusTracking) {
            document.addEventListener('focusin', trackFocus);
            window._simpleloginFocusTracking = true;
        }

        // Labels hier uitlezen, niet bovenaan de IIFE
        const labels = window.SimpleloginBtnLabels || {
            name:   '#name',
            link:   '#link',
            expiry: '#expiry'
        };

        const wrap = document.createElement('div');
        wrap.className = 'simplelogin-var-btn-wrap ' + marker;
        wrap.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;';

        [
            { label: labels.name,   token: '#name' },
            { label: labels.link,   token: '#link' },
            { label: labels.expiry, token: '#expiry' }
        ].forEach(function (v) {
            const btn = document.createElement('button');
            btn.type        = 'button';
            btn.className   = 'btn btn-secondary btn-sm';
            btn.textContent = v.label;
            btn.addEventListener('click', function () {
                const field = activeField;
                if (!field) return;
                const s = field.selectionStart;
                const e = field.selectionEnd;
                field.value = field.value.substring(0, s) + v.token + field.value.substring(e);
                field.setSelectionRange(s + v.token.length, s + v.token.length);
                field.focus();
                field.dispatchEvent(new Event('change', { bubbles: true }));
            });
            wrap.appendChild(btn);
        });

        anchor.insertAdjacentElement('afterend', wrap);
    }

    function init() {
        injectButtons('jform_params_mail_login_body');
        injectButtons('jform_params_mail_invite_body');
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }

})();
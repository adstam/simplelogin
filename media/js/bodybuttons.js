(function () {
    // NB: Elk veld (textarea- of editor-variant) heeft in de Joomla-form
    // gewoon zijn EIGEN, echte DOM-id gelijk aan het veldnaam-attribuut,
    // bv. "jform_params_mail_login_body" en "jform_params_mail_login_body_html".
    // Er mag dus NOOIT een "_html" suffix afgeknipt worden om bij het
    // "echte" element te komen -- anchorId IS het echte element-id.

    function injectButtons(anchorId) {
        const anchor = document.getElementById(anchorId);
        if (!anchor) return;

        const marker = 'simplelogin-var-btn-wrap--' + anchorId;
        if (document.querySelector('.' + marker)) return;

        const labels = window.SimpleloginBtnLabels || {
            name: '#name',
            sitename: '#sitename',
            link: '#link',
            expiry: '#expiry',
            reason: '#reason',
            email: '#email'
        };

        let buttons = [
            { label: labels.name, token: '#name' },
            { label: labels.sitename, token: '#sitename' },
        ];

        if (anchorId.includes('mail_login_body') || anchorId.includes('mail_invite_body')) {
            buttons.push({ label: labels.expiry, token: '#expiry' });
        }

        if (anchorId.includes('mail_login_body') ||
            anchorId.includes('mail_invite_body') ||
            anchorId.includes('mail_approval_body')) {
            buttons.push({ label: labels.link, token: '#link' });
        }

        if (anchorId.includes('mail_admin_body')) {
            buttons.push({ label: labels.email, token: '#email' });
        }

        if (anchorId.includes('mail_rejection_body')) {
            buttons.push({ label: labels.reason, token: '#reason' });
        }

        const wrap = document.createElement('div');
        wrap.className = 'simplelogin-var-btn-wrap ' + marker;
        wrap.style.cssText = 'display:flex;gap:6px;flex-wrap:wrap;margin-top:6px;';

        buttons.forEach(function (v) {
            const btn = document.createElement('button');
            btn.type = 'button';
            btn.className = 'btn btn-secondary btn-sm';
            btn.textContent = v.label;
            btn.dataset.editorId = anchorId;

            btn.addEventListener('click', function () {
                const editorId = this.dataset.editorId;

                // 1) Probeer via de Joomla editor-API (TinyMCE, Codemirror, JCE, etc.)
                // LET OP: dit is GEEN Joomla.editors.get(id).insertText(text) -- die
                // methodes bestaan niet (meer). De officiële, huidige API is:
                //   Joomla.editors.instances[id].replaceSelection(text)
                // (zie media/system/js/core.js / Joomla core editor registratie).
                if (
                    typeof Joomla !== 'undefined' &&
                    Joomla.editors &&
                    Joomla.editors.instances &&
                    Object.prototype.hasOwnProperty.call(Joomla.editors.instances, editorId)
                ) {
                    const editor = Joomla.editors.instances[editorId];

                    if (editor && typeof editor.replaceSelection === 'function') {
                        editor.replaceSelection(v.token);
                        return;
                    }

                    // Sommige (oudere/afwijkende) editor-registraties bieden geen
                    // replaceSelection maar wel getValue/setValue.
                    if (editor && typeof editor.getValue === 'function' && typeof editor.setValue === 'function') {
                        editor.setValue(editor.getValue() + v.token);
                        return;
                    }
                }

                // 2) Fallback: gewoon een <textarea>/<input> (text-modus of "None" editor)
                const field = document.getElementById(editorId);
                if (field) {
                    const s = field.selectionStart ?? field.value.length;
                    const e = field.selectionEnd ?? field.value.length;
                    field.value = field.value.substring(0, s) + v.token + field.value.substring(e);
                    field.setSelectionRange(s + v.token.length, s + v.token.length);
                    field.focus();
                    field.dispatchEvent(new Event('change', { bubbles: true }));
                }
            });
            wrap.appendChild(btn);
        });

        insertAfterField(anchorId, anchor, wrap);
    }

    // Editor-onafhankelijke plaatsing: we gokken niet op interne DOM van
    // TinyMCE/JCE/CodeMirror (iframe-ids, toolbar-classes etc. verschillen
    // per editor en per versie). In plaats daarvan gebruiken we iets dat
    // Joomla voor ELK formulierveld standaard server-side rendert: een
    // <label for="veld-id">. De gemeenschappelijke voorouder van dat label
    // en het veld-element is de "veld-rij"; het directe kind van die rij dat
    // het veld bevat is de control-container. Daar plaatsen we de knoppen
    // direct na -- ongeacht welke editor-technologie het veld later
    // (client-side) verrijkt.
    function insertAfterField(anchorId, anchor, wrap) {
        const label = document.querySelector('label[for="' + anchorId + '"]');

        if (label) {
            const ancestors = new Set();
            for (let a = label; a; a = a.parentElement) ancestors.add(a);

            let row = anchor;
            while (row && !ancestors.has(row)) row = row.parentElement;

            if (row && row !== label) {
                let controlNode = anchor;
                while (controlNode.parentElement !== row) {
                    controlNode = controlNode.parentElement;
                }
                // BELANGRIJK: als laatste kind BINNEN controlNode toevoegen,
                // niet als sibling ernaast. controlNode is doorgaans zelf een
                // grid/flex-kolom (bv. Bootstrap .col-md-9); een sibling ernaast
                // plaatsen maakt de knoppenbalk een nieuwe kolom in diezelfde
                // rij (vandaar het links uitlijnen i.p.v. eronder staan).
                controlNode.appendChild(wrap);
                return;
            }
        }

        // Fallback als er (nog) geen label gevonden is: gewoon na het
        // anker-element zelf plaatsen.
        anchor.insertAdjacentElement('afterend', wrap);
    }

    // De label/veld-structuur staat al server-side vast, dus injectie kan
    // meteen. De MutationObserver is puur een vangnet voor randgevallen
    // (bv. tabbladen die in het admin-scherm pas later dynamisch renderen).
    const fieldIds = [
        'jform_params_mail_login_body',
        'jform_params_mail_login_body_html',
        'jform_params_mail_admin_body',
        'jform_params_mail_admin_body_html',
        'jform_params_mail_invite_body',
        'jform_params_mail_invite_body_html',
        'jform_params_mail_approval_body',
        'jform_params_mail_approval_body_html',
        'jform_params_mail_rejection_body',
        'jform_params_mail_rejection_body_html'
    ];

    function tryInjectAll() {
        let allDone = true;
        fieldIds.forEach(function (id) {
            injectButtons(id);
            if (!document.querySelector('.simplelogin-var-btn-wrap--' + id)) {
                allDone = false;
            }
        });
        return allDone;
    }

    function init() {
        if (tryInjectAll()) return;

        const observer = new MutationObserver(function () {
            if (tryInjectAll()) {
                observer.disconnect();
            }
        });
        observer.observe(document.body, { childList: true, subtree: true });

        // Vangnet: sowieso stoppen met kijken na 15s, ook als niet alles
        // gelukt is (bv. een veld dat door showon permanent verborgen blijft).
        setTimeout(function () { observer.disconnect(); }, 15000);
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})();
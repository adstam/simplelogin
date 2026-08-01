/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */
(function () {
    'use strict';

    window.SimpleLogin = window.SimpleLogin || {};

    var OVERLAY_ID = 'simplelogin-overlay';
    var AUTOPOST_ID = 'simplelogin-autopost';
    var AUTOSUBMIT_DELAY = 800;
    var CLOSE_ANIMATION_DELAY = 200;

    // Onthoudt welke overlay-elementen al zijn verwerkt, zodat autosubmit/redirect
    // niet meerdere keren wordt getriggerd als de DOM meerdere keren wordt gescand.
    var processed = (typeof WeakSet !== 'undefined') ? new WeakSet() : null;

    /**
     * Verwijdert de simplelogin-gerelateerde querystring parameters uit de URL
     * zonder de pagina te herladen.
     */
    function cleanUrl() {
        try {
            var url = new URL(window.location.href);

            ['simplelogin', 'sl_task', 'selector', 'validator'].forEach(function (param) {
                url.searchParams.delete(param);
            });

            var newUrl = url.pathname + (url.search ? '?' + url.searchParams.toString() : '') + url.hash;

            window.history.replaceState({}, document.title, newUrl);
        } catch (e) {
            // URL API niet beschikbaar of andere fout: stilzwijgend negeren.
        }
    }

    /**
     * Sluit de overlay met een fade-out animatie en ruimt de URL op.
     */
    function closeOverlay() {
        var overlay = document.getElementById(OVERLAY_ID);

        if (!overlay) {
            return;
        }

        overlay.classList.add('sl-closing');

        setTimeout(function () {
            overlay.remove();
            cleanUrl();
        }, CLOSE_ANIMATION_DELAY);
    }

    /**
     * Delegated click-handler: werkt ongeacht wanneer of hoe de overlay
     * (en de close-knop erin) in de DOM terechtkomen.
     */
    document.addEventListener('click', function (e) {
        var target = e.target && e.target.closest ? e.target.closest('[data-sl-close]') : null;

        if (target) {
            e.preventDefault();
            closeOverlay();
        }
    });

    /**
     * Voert de eenmalige initialisatie uit voor een specifiek overlay-element
     * (clean-on-load, autosubmit, redirect) op basis van de data-attributen.
     */
    function processOverlay(overlay) {
        if (!overlay) {
            return;
        }

        if (processed) {
            if (processed.has(overlay)) {
                return;
            }
            processed.add(overlay);
        } else if (overlay.dataset.slProcessed === '1') {
            return;
        } else {
            overlay.dataset.slProcessed = '1';
        }

        if (overlay.dataset.slCleanOnLoad === '1') {
            cleanUrl();
        }

        if (overlay.dataset.slAutosubmit === '1') {
            var autoForm = document.getElementById(AUTOPOST_ID);

            if (autoForm) {
                setTimeout(function () {
                    autoForm.submit();
                }, AUTOSUBMIT_DELAY);
            }
        }

        var redirectUrl = overlay.dataset.slRedirect;

        if (redirectUrl) {
            setTimeout(function () {
                window.location.href = redirectUrl;
            }, AUTOSUBMIT_DELAY);
        }
    }

    function scan() {
        var overlay = document.getElementById(OVERLAY_ID);

        if (overlay) {
            processOverlay(overlay);
        }
    }

    // Direct proberen: als de overlay al in de initiële HTML zit, hoeven we
    // niet te wachten op DOMContentLoaded.
    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', scan);
    } else {
        scan();
    }

    // Vangnet voor het geval de overlay pas later (dynamisch) wordt toegevoegd.
    if (window.MutationObserver) {
        var observer = new MutationObserver(function () {
            scan();
        });

        observer.observe(document.documentElement, { childList: true, subtree: true });
    }

    window.SimpleLogin.closeOverlay = closeOverlay;
    window.SimpleLogin.cleanUrl = cleanUrl;
})();
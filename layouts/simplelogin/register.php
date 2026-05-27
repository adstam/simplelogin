<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */
defined('_JEXEC') or die;

use Joomla\CMS\HTML\HTMLHelper;
use Joomla\CMS\Language\Text;
use Joomla\CMS\Uri\Uri;

$statusMessage = $displayData['statusMessage'] ?? '';
$statusType    = $displayData['statusType'] ?? 'info';
$showLoginForm = $displayData['showLoginForm'] ?? true;

$isError   = ($statusType === 'danger');
$isSuccess = ($statusType === 'success' && $statusMessage !== '');
$showForm  = $showLoginForm && !$isSuccess;

$action = Uri::root() . 'index.php?sl_task=register';

$bgColor = '#f8fafc';
if ($isSuccess) {
    $bgColor = '#f0fdf4';
} elseif ($isError) {
    $bgColor = '#fef2f2';
}
?>

<style>
.sl-overlay {
    position:fixed;
    inset:0;
    background:rgba(0,0,0,0.35);
    backdrop-filter: blur(6px);
    display:flex;
    align-items:center;
    justify-content:center;
    z-index:9999;
    opacity:0;
    animation: slFadeIn 0.25s ease forwards;
}
@keyframes slFadeIn { to { opacity:1; } }

.sl-modal {
    background:<?= $bgColor ?>;
    padding:30px 25px;
    border-radius:14px;
    max-width:420px;
    width:90%;
    text-align:center;
    position:relative;
    box-shadow:0 20px 60px rgba(0,0,0,0.25);
    transform: translateY(20px) scale(0.96);
    opacity:0;
    animation: slModalIn 0.3s ease forwards;
}
@keyframes slModalIn {
    to { transform: translateY(0) scale(1); opacity:1; }
}

.sl-overlay.sl-closing { animation: slFadeOut 0.2s ease forwards; }
.sl-overlay.sl-closing .sl-modal { animation: slModalOut 0.2s ease forwards; }
@keyframes slFadeOut { to { opacity:0; } }
@keyframes slModalOut {
    to { transform: translateY(10px) scale(0.97); opacity:0; }
}

.sl-modal h3 { margin:10px 0 20px; font-size:24px; }
.sl-modal h4 { margin-bottom:15px; font-size:16px; color:#444; }

.sl-alert {
    margin:0 0 20px 0;
    padding:14px 16px;
    border-radius:8px;
    font-size:15px;
    line-height:1.45;
    text-align:left;
}
.sl-alert-danger {
    background:#fee2e2;
    border:1px solid #fecaca;
    color:#991b1b;
}
.sl-alert-success {
    background:#dcfce7;
    border:1px solid #bbf7d0;
    color:#166534;
}

.sl-modal input {
    width:100%;
    padding:12px;
    margin-bottom:15px;
    border:1px solid #ccc;
    border-radius:6px;
    font-size:14px;
    box-sizing:border-box;
}
.sl-modal input:focus {
    outline:none;
    border-color:#2563eb;
    box-shadow:0 0 0 2px rgba(37,99,235,0.2);
}

.sl-btn {
    display:block;
    width:100%;
    padding:12px;
    border-radius:6px;
    font-size:14px;
    font-weight:bold;
    border:none;
    cursor:pointer;
    background:#2563eb;
    color:#fff;
}
.sl-box {
    border:2px solid #e5e7eb;
    border-radius:10px;
    padding:20px;
    background:#fff;
    text-align:left;
}
</style>

<div id="simplelogin-overlay" class="sl-overlay">
    <div id="simplelogin-modal" class="sl-modal">

        <button type="button" onclick="SimpleLogin.closeOverlay()" style="
            position:absolute;top:10px;right:10px;
            background:#ef4444;border:none;color:#fff;
            width:28px;height:28px;border-radius:6px;cursor:pointer;font-weight:bold;
        " aria-label="<?= htmlspecialchars(Text::_('JCLOSE')) ?>">&#10008;</button>

        <?php if ($isSuccess) : ?>
            <div class="sl-alert sl-alert-success" role="status">
                <?= htmlspecialchars($statusMessage) ?>
            </div>
        <?php else : ?>

            <?php if ($isError && $statusMessage) : ?>
                <div class="sl-alert sl-alert-danger" role="alert">
                    <?= htmlspecialchars($statusMessage) ?>
                </div>
            <?php endif; ?>

            <h3><?= htmlspecialchars(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_TITLE')) ?></h3>

            <?php if ($showForm) : ?>
                <h4><?= htmlspecialchars(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_INTRO')) ?></h4>
                <div class="sl-box">
                    <form method="post" action="<?= htmlspecialchars($action) ?>">
                        <input type="text" name="name" placeholder="<?= htmlspecialchars(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_NAME')) ?>" required>
                        <input type="email" name="email" placeholder="<?= htmlspecialchars(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_EMAIL')) ?>" required autocomplete="email">
                        <button type="submit" class="sl-btn">
                            <?= htmlspecialchars(Text::_('PLG_SYSTEM_SIMPLELOGIN_REGISTER_SUBMIT')) ?>
                        </button>
                        <?= HTMLHelper::_('form.token') ?>
                    </form>
                </div>
            <?php endif; ?>

        <?php endif; ?>

    </div>
</div>

<script>
(function () {
    window.SimpleLogin = window.SimpleLogin || {};

    SimpleLogin.cleanUrl = function () {
        try {
            const url = new URL(window.location.href);
            url.searchParams.delete('simplelogin');
            url.searchParams.delete('sl_task');
            url.searchParams.delete('selector');
            url.searchParams.delete('validator');
            window.history.replaceState({}, document.title, url.pathname + (url.search ? '?' + url.searchParams.toString() : ''));
        } catch (e) {}
    };

    SimpleLogin.closeOverlay = function () {
        const el = document.getElementById('simplelogin-overlay');
        if (!el) return;
        el.classList.add('sl-closing');
        setTimeout(function () {
            el.remove();
            SimpleLogin.cleanUrl();
        }, 200);
    };

    SimpleLogin.cleanUrl();
})();
</script>

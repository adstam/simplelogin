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
use Joomla\CMS\Router\Route;

$statusMessage = $displayData['statusMessage'] ?? '';
$statusType    = $displayData['statusType'] ?? 'info';
$autoSubmit    = $displayData['autoSubmit'] ?? false;
$redirectUrl   = $displayData['redirectUrl'] ?? '';
$showLoginForm = $displayData['showLoginForm'] ?? false;

$postLogin = $displayData['postLogin'] ?? false;
$selector  = $displayData['selector'] ?? '';
$validator = $displayData['validator'] ?? '';

$allowPasswordLogin  = !empty($displayData['allowPasswordLogin']);
$passwordLoginItemId = (int) ($displayData['passwordLoginItemId'] ?? 0);
$showPasswordOption  = $allowPasswordLogin && $showLoginForm && !$postLogin;
$isError = ($statusType === 'danger');

// achtergrondkleur
$bgColor = '#ffffff';

if ($statusType === 'success') {
    $bgColor = '#f0fdf4';
} elseif ($statusType === 'danger') {
    $bgColor = '#fef2f2';
} elseif ($statusType === 'info') {
    $bgColor = '#f8fafc';
}
?>

<style>
/* ===== OVERLAY ===== */
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

@keyframes slFadeIn {
    to { opacity:1; }
}

/* ===== MODAL ===== */
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
    to {
        transform: translateY(0) scale(1);
        opacity:1;
    }
}

/* ===== CLOSE ===== */
.sl-overlay.sl-closing {
    animation: slFadeOut 0.2s ease forwards;
}
.sl-overlay.sl-closing .sl-modal {
    animation: slModalOut 0.2s ease forwards;
}
@keyframes slFadeOut { to { opacity:0; } }
@keyframes slModalOut {
    to {
        transform: translateY(10px) scale(0.97);
        opacity:0;
    }
}

/* ===== TYPO ===== */
.sl-modal h3 {
    margin:10px 0 20px;
    font-size:24px;
}
.sl-modal h4 {
    margin-bottom:15px;
    font-size:16px;
    color:#444;
}
.sl-modal p {
    margin:0 0 20px 0;
    color:#666;
    font-size:14px;
}

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

/* ===== INPUT ===== */
.sl-modal input {
    width:100%;
    padding:12px;
    margin-bottom:15px;
    border:1px solid #ccc;
    border-radius:6px;
    font-size:16px;
    transition:all 0.15s ease;
}
.sl-modal input:focus {
    outline:none;
    border-color:#2563eb;
    box-shadow:0 0 0 2px rgba(37,99,235,0.2);
}

/* ===== BUTTONS ===== */
.sl-btn {
    display:block;
    width:100%;
    padding:12px;
    border-radius:6px;
    font-size:16px;
    font-weight:bold;
    text-decoration:none;
    cursor:pointer;
    transition:all 0.15s ease;
}

.sl-btn-primary {
    background:#2563eb;
    color:#fff;
    border:none;
}

.sl-btn-secondary {
    background:#6b7280;
    color:#fff;
}

.sl-btn:hover {
    transform: translateY(-1px);
    filter: brightness(1.05);
}

.sl-btn:active {
    transform: translateY(1px);
    filter: brightness(0.95);
}

/* ===== BOXES ===== */
.sl-box {
    border:2px solid #e5e7eb;
    border-radius:10px;
    padding:20px;
    background:#fff;
    margin-bottom:20px;
}

/* ===== DIVIDER ===== */
.sl-divider {
    margin:20px 0;
    font-size:14px;
    color:#222;
}

/* ===== LOADER ===== */
.sl-loader {
    width:30px;
    height:30px;
    border:3px solid #ddd;
    border-top:3px solid #2563eb;
    border-radius:50%;
    animation: spin 1s linear infinite;
    margin:20px auto;
}
@keyframes spin {
    to { transform: rotate(360deg); }
}
</style>

<div id="simplelogin-overlay" class="sl-overlay">

    <div id="simplelogin-modal" class="sl-modal">

        <!-- CLOSE -->
        <button onclick="closeSimpleLoginOverlay();" style="
            position:absolute;
            top:10px;
            right:10px;
            background:#ef4444;
            border:none;
            color:#fff;
            width:28px;
            height:28px;
            border-radius:6px;
            cursor:pointer;
            font-weight:bold;
        ">&#10008;</button>

        <!-- MESSAGE -->
        <?php if (!empty($statusMessage)) : ?>
            <?php if ($isError) : ?>
                <div class="sl-alert sl-alert-danger" role="alert">
                    <?= htmlspecialchars($statusMessage) ?>
                </div>
            <?php else : ?>
                <h3><?= htmlspecialchars($statusMessage) ?></h3>
            <?php endif; ?>
        <?php endif; ?>

        <!-- EMAIL LOGIN -->
        <?php if ($showLoginForm) : ?>
            <div class="sl-box">

                <h4><?= Text::_('PLG_SYSTEM_SIMPLELOGIN_OVERLAY_EMAIL_INTRO') ?></h4>

                <form method="post" action="index.php?simplelogin=1">

                    <input type="email" name="email"
                        placeholder="<?= htmlspecialchars(Text::_('PLG_SYSTEM_SIMPLELOGIN_OVERLAY_EMAIL_PLACEHOLDER')) ?>"
                        required>

                    <button type="submit" class="sl-btn sl-btn-primary">
                        <?= Text::_('PLG_SYSTEM_SIMPLELOGIN_OVERLAY_SUBMIT') ?>
                    </button>

                    <?= HTMLHelper::_('form.token'); ?>
                </form>

            </div>
        <?php endif; ?>

        <!-- DIVIDER -->
        <?php if ($showPasswordOption) : ?>
            <div class="sl-divider"><h3>&mdash; <?= Text::_('PLG_SYSTEM_SIMPLELOGIN_OVERLAY_OR') ?> &mdash;</h3></div>
        <?php endif; ?>

        <!-- PASSWORD LOGIN -->
        <?php if ($showPasswordOption) : ?>

            <?php
                $link = $passwordLoginItemId > 0
                    ? Route::_('index.php?Itemid=' . $passwordLoginItemId . '&sl_pw=1')
                    : Route::_('index.php?option=com_users&view=login&sl_pw=1');
            ?>

            <div class="sl-box">

                <a href="<?= htmlspecialchars($link) ?>" class="sl-btn sl-btn-secondary">
                    <?= Text::_('PLG_SYSTEM_SIMPLELOGIN_OVERLAY_PASSWORD_LOGIN') ?>
                </a>

            </div>

        <?php endif; ?>

        <!-- AUTO LOGIN -->
        <?php if ($postLogin && $selector && $validator) : ?>

            <div class="sl-loader"></div>
            <p><?= Text::_('PLG_SYSTEM_SIMPLELOGIN_STATUS_LOGGING_IN') ?></p>

            <form id="simplelogin-autopost" method="post" action="index.php?simplelogin=1">
                <input type="hidden" name="selector" value="<?= htmlspecialchars($selector) ?>">
                <input type="hidden" name="validator" value="<?= htmlspecialchars($validator) ?>">
                <input type="hidden" name="sl_js" value="1">
                <?= HTMLHelper::_('form.token'); ?>
            </form>

        <?php endif; ?>

    </div>
</div>

<!-- AUTO SUBMIT -->
<?php if ($autoSubmit && $postLogin) : ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    var form = document.getElementById('simplelogin-autopost');
    if (!form) return;

    setTimeout(function () {
        form.submit();
    }, 800);
});
</script>
<?php endif; ?>

<!-- AUTO REDIRECT -->
<?php if ($autoSubmit && !$postLogin && !empty($redirectUrl)) : ?>
<script>
document.addEventListener('DOMContentLoaded', function () {
    setTimeout(function () {
        window.location.href = <?= json_encode($redirectUrl) ?>;
    }, 800);
});
</script>
<?php endif; ?>

<script>
function closeSimpleLoginOverlay() {

    var overlay = document.getElementById('simplelogin-overlay');
    if (!overlay) return;

    overlay.classList.add('sl-closing');

    setTimeout(function () {

        overlay.remove();

        if (window.history && window.history.replaceState) {

            var url = new URL(window.location.href);

            url.searchParams.delete('simplelogin');
            url.searchParams.delete('selector');
            url.searchParams.delete('validator');
            url.searchParams.delete('sl_task');

            window.history.replaceState({}, document.title, url.toString());
        }

    }, 200);
}
</script>
<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */

defined('_JEXEC') or die;
use Joomla\CMS\Language\Text;
?>
<div class="d-flex align-items-center gap-3 mb-3">
    <select
        name="simplelogin_log_type"
        id="sl-log-type-select"
        class="form-select"
        style="max-width: 300px;"
    >
        <option value=""><?= Text::_('PLG_SYSTEM_SIMPLELOGIN_LOG_ALL_TYPES') ?></option>
        <option value="debug*" <?= $type === 'debug*' ? 'selected' : '' ?>>debug*</option>
        <?php foreach ($types as $logType): ?>
            <option
                value="<?= htmlspecialchars($logType) ?>"
                <?= $type === $logType ? 'selected' : '' ?>
            >
                <?= htmlspecialchars($logType) ?>
            </option>
        <?php endforeach; ?>
    </select>

    <button
        id="sl-log-purge-btn"
        type="button"
        class="btn btn-danger btn-sm"
    >
        <?= $type
            ? Text::_('PLG_SYSTEM_SIMPLELOGIN_LOG_DELETE_TYPE') . htmlspecialchars($type)
            : Text::_('PLG_SYSTEM_SIMPLELOGIN_LOG_DELETE_ALL') ?>
    </button>
</div>
<hr>
<div id="sl-log-table-wrapper">
    <?php require __DIR__ . '/logs_table.php'; ?>
</div>
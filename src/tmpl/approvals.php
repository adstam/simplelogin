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
use Joomla\CMS\Session\Session;
?>
<div id="simplelogin-approval-container">
    <table class="table table-striped">
        <thead>
            <tr>
                <th><?= Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_NAME') ?></th>
                <th><?= Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_EMAIL') ?></th>
                <th><?= Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_REGISTERED') ?></th>
                <th><?= Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_ACTIONS') ?></th>
            </tr>
        </thead>
        <tbody>
            <?php if (empty($rows)) : ?>
                <tr>
                    <td colspan="4" class="text-center">
                        <?= Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_NO_PENDING') ?>
                    </td>
                </tr>
            <?php else : ?>
                <?php foreach ($rows as $row) : ?>
                    <tr data-user-id="<?= (int) ($row->id ?? 0) ?>">
                        <td><?= htmlspecialchars($row->name ?? '') ?></td>
                        <td><?= htmlspecialchars($row->email ?? '') ?></td>
                        <td><?= htmlspecialchars($row->registerDate ?? '') ?></td>
                        <td>
                            <button type="button"
                                    class="btn btn-success btn-sm sl-approve-btn"
                                    data-user-id="<?= (int) ($row->id ?? 0) ?>">
                                <?= Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_APPROVE') ?>
                            </button>
                            <button type="button"
                                    class="btn btn-danger btn-sm sl-reject-btn"
                                    data-user-id="<?= (int) ($row->id ?? 0) ?>">
                                <?= Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_REJECT') ?>
                            </button>
                        </td>
                    </tr>
                <?php endforeach; ?>
            <?php endif; ?>
        </tbody>
    </table>
</div>

<script>
(function() {
    var token = '<?= \Joomla\CMS\Session\Session::getFormToken() ?>';

    document.addEventListener('DOMContentLoaded', function() {
        // Approve buttons
        document.querySelectorAll('.sl-approve-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var userId = this.dataset.userId;
                if (!userId) return;

                if (!confirm('<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_CONFIRM_APPROVE')) ?>')) {
                    return;
                }

                var row = this.closest('tr');
                var btn = this;
                btn.disabled = true;
                btn.textContent = '...';

                fetch('index.php?option=com_ajax&plugin=simplelogin&group=system&format=json&method=ApproveUser', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: encodeURIComponent(token) + '=1&user_id=' + encodeURIComponent(userId)
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network error');
                    return response.json();
                })
                .then(data => {
                    if (data && data.success) {
                        row.remove();
                        Joomla.renderMessages({ success: [data.message || 'Gebruiker goedgekeurd'] });
                    } else {
                        var msg = data && data.message ? data.message : 'Fout bij goedkeuren';
                        btn.disabled = false;
                        btn.textContent = '<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_APPROVE')) ?>';
                        Joomla.renderMessages({ error: [msg] });
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.textContent = '<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_APPROVE')) ?>';
                    Joomla.renderMessages({ error: ['<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_GENERIC')) ?>'] });
                });
            });
        });

        // Reject buttons (met redeninvoer)
        document.querySelectorAll('.sl-reject-btn').forEach(function(btn) {
            btn.addEventListener('click', function() {
                var userId = this.dataset.userId;
                if (!userId) return;

                var row = this.closest('tr');
                var btn = this;

                var reason = prompt('<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_REJECT_REASON_PROMPT')) ?>');
                if (reason === null) {
                    return;
                }
                if (reason.trim() === '') {
                    alert('<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_REJECT_REASON_REQUIRED')) ?>');
                    return;
                }

                btn.disabled = true;
                btn.textContent = '...';

                fetch('index.php?option=com_ajax&plugin=simplelogin&group=system&format=json&method=RejectUser', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/x-www-form-urlencoded',
                        'X-Requested-With': 'XMLHttpRequest'
                    },
                    body: encodeURIComponent(token) + '=1&user_id=' + encodeURIComponent(userId) +
                          '&reason=' + encodeURIComponent(reason)
                })
                .then(response => {
                    if (!response.ok) throw new Error('Network error');
                    return response.json();
                })
                .then(data => {
                    if (data && data.success) {
                        row.remove();
                        Joomla.renderMessages({ success: [data.message || 'Gebruiker afgekeurd'] });
                    } else {
                        var msg = data && data.message ? data.message : 'Fout bij afkeuren';
                        btn.disabled = false;
                        btn.textContent = '<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_REJECT')) ?>';
                        Joomla.renderMessages({ error: [msg] });
                    }
                })
                .catch(err => {
                    btn.disabled = false;
                    btn.textContent = '<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_APPROVAL_REJECT')) ?>';
                    Joomla.renderMessages({ error: ['<?= addslashes(Text::_('PLG_SYSTEM_SIMPLELOGIN_ERR_GENERIC')) ?>'] });
                });
            });
        });
    });
})();
</script>
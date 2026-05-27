<?php
/**
 * @package   Simplelogin
 * @author    Ad Stam
 * @copyright Copyright (C) 2026 Ad Stam. All rights reserved.
 * @license   GNU General Public License version 2 or later; see LICENSE.txt
 * @link      https://demo.adstam.nl
 */
defined('_JEXEC') or die; ?>
<table class="table table-striped">
    <thead>
        <tr>
            <th>Type</th>
            <th>User</th>
            <th>Status</th>
            <th>IP</th>
            <th>Created</th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $row): ?>
            <tr>
                <td><?= htmlspecialchars($row->type) ?></td>
                <td><?= htmlspecialchars($row->username ?? '-') ?></td>
                <td><?= htmlspecialchars($row->status) ?></td>
                <td><?= inet_ntop($row->ip) ?></td>
                <td><?= htmlspecialchars($row->created) ?></td>
            </tr>
        <?php endforeach; ?>
    </tbody>
</table>
<?php
/**
 * @var \App\View\AppView $this
 * @var iterable $logs
 * @var iterable $users
 * @var iterable $products
 */
$badge = function (string $action): string {
    $color = '#3273dc';
    if (strpos($action, 'create') !== false || strpos($action, 'login') !== false) {
        $color = '#23d160';
    } elseif (strpos($action, 'delete') !== false || strpos($action, 'logout') !== false) {
        $color = '#ff3860';
    } elseif (strpos($action, 'update') !== false) {
        $color = '#ffaa00';
    }

    return '<span style="background:' . $color . ';color:#fff;padding:2px 8px;border-radius:10px;font-size:12px;white-space:nowrap">'
        . h($action) . '</span>';
};
?>
<h2>📋 Audit Trail</h2>
<p class="note" style="color:#7a7a7a">Every create / update / delete and login / logout, related by person and by product.</p>

<div style="display:flex;gap:24px;flex-wrap:wrap">
    <div style="flex:1;min-width:560px">
        <!-- Filters -->
        <form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
            <label style="margin:0">User
                <select name="user_id">
                    <option value="">All</option>
                    <?php foreach ($users as $u) : ?>
                        <option value="<?= $u->id ?>" <?= (string)$userId === (string)$u->id ? 'selected' : '' ?>><?= h($u->name) ?></option>
                    <?php endforeach; ?>
                </select>
            </label>
            <label style="margin:0">Table
                <select name="table_name">
                    <option value="">All</option>
                    <option value="products" <?= $tableName === 'products' ? 'selected' : '' ?>>products</option>
                    <option value="users" <?= $tableName === 'users' ? 'selected' : '' ?>>users</option>
                </select>
            </label>
            <label style="margin:0">Action
                <input type="text" name="action" value="<?= h($action) ?>" placeholder="e.g. update, login">
            </label>
            <button type="submit" class="button">Filter</button>
            <a class="button button-outline" href="<?= $this->Url->build(['action' => 'index']) ?>">Reset</a>
        </form>

        <!-- Feed -->
        <table>
            <thead>
                <tr><th>When</th><th>Who</th><th>Action</th><th>Entity</th><th>Changed</th></tr>
            </thead>
            <tbody>
            <?php foreach ($logs as $log) :
                $changed = $log->changed_fields ? json_decode($log->changed_fields, true) : [];
            ?>
                <tr>
                    <td style="white-space:nowrap;font-size:12px"><?= h($log->created->format('M d, H:i:s')) ?></td>
                    <td>
                        <?php if ($log->user_id) : ?>
                            <a href="<?= $this->Url->build(['action' => 'userFlow', $log->user_id]) ?>">
                                <?= h($log->user->name ?? ('User #' . $log->user_id)) ?>
                            </a>
                        <?php else : ?>
                            <em style="color:#999">system</em>
                        <?php endif; ?>
                    </td>
                    <td><?= $badge($log->action) ?></td>
                    <td style="font-size:13px">
                        <?php if ($log->table_name === 'products' && $log->entity_id) : ?>
                            <a href="<?= $this->Url->build(['action' => 'productFlow', $log->entity_id]) ?>">product #<?= h($log->entity_id) ?></a>
                        <?php else : ?>
                            <?= h($log->table_name) ?><?= $log->entity_id ? ' #' . h($log->entity_id) : '' ?>
                        <?php endif; ?>
                    </td>
                    <td style="font-size:12px;color:#666"><?= h($changed ? implode(', ', $changed) : '—') ?></td>
                </tr>
            <?php endforeach; ?>
            <?php if (!count($logs->toArray())) : ?>
                <tr><td colspan="5"><em>No audit records yet. Log in and create/edit a product.</em></td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <div class="paginator">
            <ul class="pagination">
                <?= $this->Paginator->first('« first') ?>
                <?= $this->Paginator->prev('‹ prev') ?>
                <?= $this->Paginator->numbers() ?>
                <?= $this->Paginator->next('next ›') ?>
                <?= $this->Paginator->last('last »') ?>
            </ul>
        </div>
    </div>

    <!-- Flow pickers -->
    <div style="width:280px">
        <p style="font-size:12px;color:#999;margin:0 0 10px">
            Each flow can be read two ways — <strong>DB</strong> (MySQL <code>audit_logs</code>)
            or <strong>OS</strong> (live from OpenSearch, shows the query used).
        </p>
        <h4>👤 User flows</h4>
        <ul style="list-style:none;margin-left:0">
            <?php foreach ($users as $u) : ?>
                <li>
                    <?= h($u->name) ?> —
                    <a href="<?= $this->Url->build(['action' => 'userFlow', $u->id]) ?>">DB</a> ·
                    <a href="<?= $this->Url->build(['action' => 'userFlowOs', $u->id]) ?>">OS</a>
                </li>
            <?php endforeach; ?>
        </ul>
        <h4 style="margin-top:20px">📦 Product flows</h4>
        <ul style="list-style:none;margin-left:0">
            <?php foreach ($products as $p) : ?>
                <li>
                    <?= h($p->name) ?> (#<?= $p->id ?>) —
                    <a href="<?= $this->Url->build(['action' => 'productFlow', $p->id]) ?>">DB</a> ·
                    <a href="<?= $this->Url->build(['action' => 'productFlowOs', $p->id]) ?>">OS</a>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
</div>

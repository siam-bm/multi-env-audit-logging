<?php
/**
 * Recent audit activity, read live from OpenSearch.
 *
 * @var \App\View\AppView $this
 * @var string $index
 * @var array $query
 * @var array $events
 * @var string|null $userId
 * @var string|null $action
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
$prettyQuery = json_encode($query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
<h2>📋 Audit Trail <span style="font-size:13px;color:#23d160">· live from OpenSearch</span></h2>
<p class="note" style="color:#7a7a7a">Every create / update / delete and login / logout — read straight from the <code><?= h($index) ?></code> indices.</p>

<!-- How this page fetched the data -->
<details style="background:#f6f8fa;border:1px solid #e1e4e8;border-radius:8px;padding:12px 16px;margin:14px 0">
    <summary style="cursor:pointer;font-weight:600">🔎 How this page fetched the data — <code>POST <?= h($index) ?>/_search</code></summary>
    <pre style="background:#0d1117;color:#c9d1d9;padding:12px;border-radius:6px;overflow:auto;font-size:12px;margin:10px 0 0"><?= h($prettyQuery) ?></pre>
    <p style="font-size:12px;color:#999;margin:8px 0 0"><a href="?format=json">view raw JSON response</a></p>
</details>

<!-- Filters -->
<form method="get" style="display:flex;gap:8px;flex-wrap:wrap;align-items:flex-end;margin-bottom:16px">
    <label style="margin:0">User id
        <input type="text" name="user_id" value="<?= h($userId) ?>" placeholder="e.g. 777" style="width:120px">
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
    <?php foreach ($events as $e) :
        $act = (string)($e['action'] ?? '');
        $uid = $e['user_id'] ?? null;
        $who = $e['user_name'] ?? ($uid ? ('user #' . $uid) : 'system');
        $changed = array_keys((array)($e['changes'] ?? []));
    ?>
        <tr>
            <td style="white-space:nowrap;font-size:12px"><?= h($e['@timestamp'] ?? $e['timestamp'] ?? '') ?></td>
            <td>
                <?php if ($uid) : ?>
                    <a href="<?= $this->Url->build(['action' => 'userFlowOs', $uid]) ?>"><?= h($who) ?></a>
                <?php else : ?>
                    <em style="color:#999">system</em>
                <?php endif; ?>
                <?php if (!empty($e['session_id'])) : ?>
                    <div style="font-size:11px"><a title="<?= h($e['session_id']) ?>" href="<?= $this->Url->build(['action' => 'sessionFlowOs', '?' => ['session' => $e['session_id']]]) ?>">session ↗</a></div>
                <?php endif; ?>
            </td>
            <td><?= $badge($act) ?></td>
            <td style="font-size:13px">
                <?php if (($e['table'] ?? '') === 'products' && !empty($e['entity_id'])) : ?>
                    <a href="<?= $this->Url->build(['action' => 'productFlowOs', $e['entity_id']]) ?>">product #<?= h($e['entity_id']) ?></a>
                <?php else : ?>
                    <?= h($e['table'] ?? '') ?><?= !empty($e['entity_id']) ? ' #' . h($e['entity_id']) : '' ?>
                <?php endif; ?>
            </td>
            <td style="font-size:12px;color:#666"><?= h($changed ? implode(', ', $changed) : '—') ?></td>
        </tr>
    <?php endforeach; ?>
    <?php if (!count($events)) : ?>
        <tr><td colspan="5"><em>No audit records in OpenSearch yet. Create/edit a product to generate some.</em></td></tr>
    <?php endif; ?>
    </tbody>
</table>
<p style="font-size:12px;color:#999">Showing the most recent <?= count($events) ?> event(s). Click a <strong>Who</strong> or <strong>Entity</strong> to see its full flow.</p>

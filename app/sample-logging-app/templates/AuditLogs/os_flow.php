<?php
/**
 * OpenSearch-backed flow view. Shows HOW the data was fetched (endpoint +
 * Query DSL + KQL) and then the resulting events as a timeline.
 *
 * @var \App\View\AppView $this
 * @var string $title
 * @var string $subtitle
 * @var string $index
 * @var array $query
 * @var array $events
 */

// Build a human KQL string from the DSL filters, for the "Dashboards" hint.
$kqlParts = [];
foreach ($query['query']['bool']['filter'] ?? [] as $clause) {
    $kv = $clause['term'] ?? $clause['match'] ?? [];
    foreach ($kv as $field => $value) {
        $kqlParts[] = $field . ':' . $value;
    }
}
$kql = implode(' AND ', $kqlParts);
$prettyQuery = json_encode($query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
?>
<p><a href="<?= $this->Url->build(['action' => 'index']) ?>">&laquo; Back to audit trail</a></p>
<h2><?= h($title) ?></h2>
<p style="color:#7a7a7a"><?= h($subtitle) ?> · <strong><?= count($events) ?></strong> event(s)</p>

<!-- ============ HOW WE GOT THIS DATA (the demo talking point) ============ -->
<div style="background:#f6f8fa;border:1px solid #e1e4e8;border-radius:8px;padding:16px 18px;margin:18px 0">
    <div style="font-weight:600;margin-bottom:8px">🔎 How this page fetched the data (live from OpenSearch)</div>

    <div style="font-size:13px;color:#444;margin:4px 0">
        <span style="display:inline-block;width:150px;color:#888">HTTP endpoint</span>
        <code>POST <?= h($index) ?>/_search</code>
    </div>
    <div style="font-size:13px;color:#444;margin:4px 0">
        <span style="display:inline-block;width:150px;color:#888">In Dashboards (KQL)</span>
        <code><?= h($kql) ?></code> &nbsp;<span style="color:#888">(sort @timestamp asc)</span>
    </div>

    <div style="font-size:13px;color:#888;margin:10px 0 4px">Query DSL sent from PHP (Cake\Http\Client):</div>
    <pre style="background:#0d1117;color:#c9d1d9;padding:12px;border-radius:6px;overflow:auto;font-size:12px;margin:0"><?= h($prettyQuery) ?></pre>
    <p style="font-size:12px;color:#999;margin:8px 0 0">
        Source: <strong>OpenSearch</strong> (not the MySQL <code>audit_logs</code> table) ·
        <a href="?format=json">view raw JSON response</a>
    </p>
</div>

<!-- ============ THE RESULTING FLOW (timeline) ============ -->
<div style="border-left:3px solid #dbdbdb;margin-left:12px;padding-left:24px">
    <?php foreach ($events as $e) :
        $action = (string)($e['action'] ?? '');
        $ts = (string)($e['@timestamp'] ?? $e['timestamp'] ?? '');
        $actor = $e['user_name'] ?? ('user ' . ($e['user_id'] ?? '?'));

        // before -> after list (updates carry a 'changes' object)
        $changeLines = [];
        foreach ((array)($e['changes'] ?? []) as $field => $ba) {
            $changeLines[] = $field . ': ' . (string)($ba['before'] ?? '∅') . ' → ' . (string)($ba['after'] ?? '∅');
        }

        $dot = strpos($action, 'login') !== false || strpos($action, 'logout') !== false ? '#3273dc'
            : (strpos($action, 'delete') !== false ? '#ff3860'
            : (strpos($action, 'update') !== false ? '#ffab00' : '#23d160'));
        $dim = strpos($action, 'attempt') !== false ? 'opacity:0.55' : '';
    ?>
        <div style="position:relative;margin-bottom:20px;<?= $dim ?>">
            <span style="position:absolute;left:-32px;top:2px;width:12px;height:12px;border-radius:50%;background:<?= $dot ?>;border:2px solid #fff;box-shadow:0 0 0 2px <?= $dot ?>"></span>
            <div style="font-size:12px;color:#999"><?= h($ts) ?></div>
            <div>
                <strong><?= h($action) ?></strong>
                <span style="color:#666">· by <?= h($actor) ?></span>
                <?php if (!empty($e['entity_id'])) : ?>
                    <span style="color:#666">· <?= h($e['table'] ?? '') ?> #<?= h($e['entity_id']) ?></span>
                <?php endif; ?>
                <?php if (!empty($e['trace_id'])) : ?>
                    <span style="font-size:11px;color:#b0b0b0"> · trace <?= h($e['trace_id']) ?></span>
                <?php endif; ?>
            </div>
            <?php if (!empty($e['message'])) : ?>
                <div style="font-size:13px;color:#555"><?= h($e['message']) ?></div>
            <?php endif; ?>
            <?php if ($changeLines) : ?>
                <ul style="margin:6px 0 0 0;font-size:13px;color:#555">
                    <?php foreach ($changeLines as $c) : ?><li><?= h($c) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>

    <?php if (!count($events)) : ?>
        <p><em>No events found in OpenSearch for this filter.</em></p>
    <?php endif; ?>
</div>

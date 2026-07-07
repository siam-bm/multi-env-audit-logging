<?php
/**
 * OpenSearch-backed flow view. Shows the exact index, the correlation logic
 * (how the flow is stitched), the query used, then the events as a timeline.
 *
 * @var \App\View\AppView $this
 * @var string $title
 * @var string $subtitle
 * @var string $indexPattern
 * @var array $indicesUsed   Exact index names the results came from.
 * @var string $filterKey    Field we filtered on (user_id / entity_id / trace_id).
 * @var mixed  $filterValue
 * @var array  $query        The Query DSL sent.
 * @var array  $events
 */
$prettyQuery = json_encode($query, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
$kql = $filterKey . ':' . $filterValue;
?>
<p><a href="<?= $this->Url->build(['action' => 'index']) ?>">&laquo; Back to audit trail</a></p>
<h2 style="margin-bottom:2px"><?= h($title) ?></h2>
<p style="color:#7a7a7a;margin-top:0"><?= h($subtitle) ?></p>

<!-- ===== THE CORRELATION LOGIC (senior's question: how do we get the flow?) ===== -->
<div style="background:#eef6ff;border:1px solid #cfe3ff;border-radius:10px;padding:16px 18px;margin:16px 0">
    <div style="font-weight:700;font-size:15px;margin-bottom:10px">🧠 How this flow is reconstructed</div>
    <div style="display:flex;gap:10px;flex-wrap:wrap;align-items:stretch">
        <div style="flex:1;min-width:170px;background:#fff;border:1px solid #dbe7f5;border-radius:8px;padding:10px 12px">
            <div style="font-size:11px;color:#888;text-transform:uppercase">1 · Same index</div>
            <div style="font-size:13px;margin-top:4px">Every process on every server writes to one index pattern
                <br><code><?= h($indexPattern) ?></code></div>
        </div>
        <div style="display:flex;align-items:center;color:#3273dc;font-weight:700">→</div>
        <div style="flex:1;min-width:170px;background:#fff;border:1px solid #dbe7f5;border-radius:8px;padding:10px 12px">
            <div style="font-size:11px;color:#888;text-transform:uppercase">2 · Filter by one id</div>
            <div style="font-size:13px;margin-top:4px">Keep only lines where
                <br><code style="background:#fff4d6;padding:1px 5px;border-radius:4px"><?= h($kql) ?></code></div>
        </div>
        <div style="display:flex;align-items:center;color:#3273dc;font-weight:700">→</div>
        <div style="flex:1;min-width:170px;background:#fff;border:1px solid #dbe7f5;border-radius:8px;padding:10px 12px">
            <div style="font-size:11px;color:#888;text-transform:uppercase">3 · Sort by time</div>
            <div style="font-size:13px;margin-top:4px">Order by <code>@timestamp</code> → the full chronological flow, <strong><?= count($events) ?></strong> event(s)</div>
        </div>
    </div>
    <p style="font-size:12px;color:#5a6b7b;margin:12px 0 0">
        Because the id is stamped on every line and all servers write to the same index,
        <strong>the flow lines up even if the lines came from different servers/processes</strong> —
        no joins, just one filter + a time sort. <em>(In RGS this id becomes <code>request_id</code>, propagated web → RabbitMQ worker.)</em>
    </p>
</div>

<!-- ===== EXACT INDEX + QUERY (proof of where the data lives / how it's fetched) ===== -->
<div style="background:#f6f8fa;border:1px solid #e1e4e8;border-radius:8px;padding:14px 16px;margin:16px 0">
    <div style="font-size:13px;color:#444;margin:2px 0">
        <span style="display:inline-block;width:170px;color:#888">Index pattern queried</span>
        <code>POST <?= h($indexPattern) ?>/_search</code>
    </div>
    <div style="font-size:13px;color:#444;margin:6px 0">
        <span style="display:inline-block;width:170px;color:#888">Exact index(es) matched</span>
        <?php foreach ($indicesUsed as $ix) : ?>
            <code style="background:#e6ffed;padding:1px 6px;border-radius:4px;margin-right:6px"><?= h($ix) ?></code>
        <?php endforeach; ?>
        <?php if (!$indicesUsed) : ?><span style="color:#999">— none (no matching docs)</span><?php endif; ?>
    </div>
    <div style="font-size:13px;color:#444;margin:6px 0">
        <span style="display:inline-block;width:170px;color:#888">In Dashboards (KQL)</span>
        <code><?= h($kql) ?></code> <span style="color:#888">(sort @timestamp asc)</span>
    </div>
    <details style="margin-top:8px">
        <summary style="cursor:pointer;font-size:13px;color:#888">Show Query DSL sent from PHP</summary>
        <pre style="background:#0d1117;color:#c9d1d9;padding:12px;border-radius:6px;overflow:auto;font-size:12px;margin:8px 0 0"><?= h($prettyQuery) ?></pre>
    </details>
    <p style="font-size:12px;color:#999;margin:8px 0 0"><a href="?<?= h($filterKey === 'trace_id' ? 'trace=' . urlencode((string)$filterValue) . '&' : '') ?>format=json">view raw JSON response</a></p>
</div>

<!-- ===== THE FLOW (timeline) ===== -->
<div style="border-left:3px solid #dbdbdb;margin-left:12px;padding-left:24px">
    <?php foreach ($events as $e) :
        $action = (string)($e['action'] ?? '');
        $ts = (string)($e['@timestamp'] ?? $e['timestamp'] ?? '');
        $actor = $e['user_name'] ?? ('user ' . ($e['user_id'] ?? '?'));

        $changeLines = [];
        foreach ((array)($e['changes'] ?? []) as $field => $ba) {
            $changeLines[] = $field . ': ' . (string)($ba['before'] ?? '∅') . ' → ' . (string)($ba['after'] ?? '∅');
        }

        $dot = strpos($action, 'login') !== false || strpos($action, 'logout') !== false ? '#3273dc'
            : (strpos($action, 'delete') !== false ? '#ff3860'
            : (strpos($action, 'update') !== false ? '#ffab00' : '#23d160'));
        $dim = strpos($action, 'attempt') !== false ? 'opacity:0.55' : '';
    ?>
        <div style="position:relative;margin-bottom:22px;<?= $dim ?>">
            <span style="position:absolute;left:-32px;top:2px;width:12px;height:12px;border-radius:50%;background:<?= $dot ?>;border:2px solid #fff;box-shadow:0 0 0 2px <?= $dot ?>"></span>
            <div style="font-size:12px;color:#999"><?= h($ts) ?></div>
            <div>
                <strong><?= h($action) ?></strong>
                <span style="color:#666">· by <?= h($actor) ?></span>
                <?php if (!empty($e['entity_id'])) : ?>
                    <span style="color:#666">· <?= h($e['table'] ?? '') ?> #<?= h($e['entity_id']) ?></span>
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
            <!-- provenance: which server/index this exact line came from -->
            <div style="font-size:11px;color:#aaa;margin-top:5px">
                index <code><?= h($e['_index'] ?? '?') ?></code>
                <?php if (!empty($e['environment'])) : ?> · env <?= h($e['environment']) ?><?php endif; ?>
                <?php if (!empty($e['host'])) : ?> · host <?= h($e['host']) ?><?php endif; ?>
                <?php if (!empty($e['session_id'])) : ?>
                    · session <a href="<?= $this->Url->build(['action' => 'sessionFlowOs', '?' => ['session' => $e['session_id']]]) ?>"><?= h($e['session_id']) ?></a>
                <?php endif; ?>
                <?php if (!empty($e['trace_id'])) : ?>
                    · trace <a href="<?= $this->Url->build(['action' => 'traceFlowOs', '?' => ['trace' => $e['trace_id']]]) ?>"><?= h($e['trace_id']) ?></a>
                <?php endif; ?>
            </div>
        </div>
    <?php endforeach; ?>

    <?php if (!count($events)) : ?>
        <p><em>No events found in OpenSearch for this filter.</em></p>
    <?php endif; ?>
</div>

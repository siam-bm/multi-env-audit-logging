<?php
/**
 * Manage which log fields are encrypted before shipping to OpenSearch.
 *
 * @var \App\View\AppView $this
 * @var array<string> $fields
 */
?>
<h2>🔐 Encrypted Log Fields</h2>
<p style="color:#7a7a7a">
    Fields listed here are encrypted (AES-256-GCM) <strong>before</strong> the log line is written —
    OpenSearch and Dashboards only ever see <code>enc:v1:…</code> ciphertext. Our app decrypts them
    when displaying flows. The key (<code>LOG_ENCRYPT_KEY</code>) never leaves the app.
</p>

<div style="display:flex;gap:24px;flex-wrap:wrap;align-items:flex-start">

    <!-- ===== Manage list ===== -->
    <div style="flex:1;min-width:340px">
        <table>
            <thead><tr><th>Encrypted field</th><th>Applies to</th><th></th></tr></thead>
            <tbody>
            <?php foreach ($fields as $f) : ?>
                <tr>
                    <td><code style="background:#fff4d6;padding:2px 8px;border-radius:4px">🔒 <?= h($f) ?></code></td>
                    <td style="font-size:12px;color:#888">wherever it appears — <code>new_values</code>, <code>original_values</code>, <code>changes.before/after</code>, <code>request</code></td>
                    <td>
                        <?= $this->Form->postLink(
                            'Remove',
                            ['action' => 'delete', $f],
                            ['confirm' => "Stop encrypting '{$f}' for new events?", 'class' => 'button button-outline', 'style' => 'padding:2px 10px;font-size:12px']
                        ) ?>
                    </td>
                </tr>
            <?php endforeach; ?>
            <?php if (!count($fields)) : ?>
                <tr><td colspan="3"><em>No fields encrypted — everything ships in plaintext.</em></td></tr>
            <?php endif; ?>
            </tbody>
        </table>

        <?= $this->Form->create(null, ['url' => ['action' => 'add'], 'style' => 'display:flex;gap:8px;align-items:flex-end;margin-top:12px']) ?>
            <label style="margin:0">Add a field
                <input type="text" name="field" placeholder="e.g. price, phone, address" required
                       pattern="[a-z][a-z0-9_]*" title="snake_case, e.g. order_notes" style="width:220px">
            </label>
            <button type="submit" class="button">🔒 Encrypt this field</button>
        <?= $this->Form->end() ?>
        <p style="font-size:12px;color:#999;margin-top:6px">
            Takes effect on the <strong>next request</strong> — no deploy, no restart.
            History is unaffected: values encrypted earlier stay encrypted (and still decrypt in the app);
            values written in plaintext earlier stay plaintext.
        </p>
    </div>

    <!-- ===== What we can / can't do ===== -->
    <div style="width:420px;min-width:320px">
        <div style="background:#e6ffed;border:1px solid #b7ebc6;border-radius:10px;padding:14px 16px;margin-bottom:12px">
            <div style="font-weight:700;margin-bottom:8px">✅ What still works with encrypted fields</div>
            <ul style="margin:0 0 0 18px;font-size:13px;color:#2d5a3d">
                <li><strong>Storing</strong> them safely — OpenSearch/Dashboards/backups only hold ciphertext</li>
                <li><strong>Reading</strong> them in this app — flow views decrypt automatically, incl. <strong>before → after</strong> diffs</li>
                <li><strong>All flow queries</strong> — <code>user_id</code>, <code>entity_id</code>, <code>session_id</code>, <code>trace_id</code>, <code>action</code>, time stay plaintext, so user / product / session / request flows are untouched</li>
                <li><strong>Find-then-read</strong> — locate events by the plain keys, then read the decrypted secret here</li>
                <li><strong>Key control</strong> — wrong/absent key shows <code>[decrypt-failed]</code>; data is locked to our key</li>
            </ul>
        </div>

        <div style="background:#ffecec;border:1px solid #f5c2c7;border-radius:10px;padding:14px 16px">
            <div style="font-weight:700;margin-bottom:8px">❌ What we CANNOT do with encrypted fields</div>
            <ul style="margin:0 0 0 18px;font-size:13px;color:#7a2e35">
                <li><strong>Query/filter by them in OpenSearch</strong> — e.g. <code>description:*laptop*</code> finds nothing; each encryption produces a different blob, so there is nothing to match</li>
                <li><strong>Search inside them in Dashboards</strong> — Discover shows only <code>enc:v1:…</code></li>
                <li><strong>Aggregate/count/group by them</strong> — no "top emails" chart</li>
                <li><strong>Sort by them</strong></li>
                <li><strong>Read them without the key</strong> — that includes the OpenSearch admin (by design)</li>
            </ul>
            <p style="font-size:12px;color:#7a2e35;margin:10px 0 0">
                Rule: <strong>find-then-read, not search-by.</strong> If a field must stay searchable,
                don't encrypt it — or ask about the HMAC exact-match option (see the encryption findings page).
            </p>
        </div>
    </div>
</div>

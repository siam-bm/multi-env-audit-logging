<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\User $user
 * @var iterable $events
 */
?>
<p><a href="<?= $this->Url->build(['action' => 'index']) ?>">&laquo; Back to audit trail</a></p>
<h2>👤 Activity flow — <?= h($user->name) ?></h2>
<p class="note" style="color:#7a7a7a"><?= h($user->email) ?> · <?= count($events->toArray()) ?> event(s), oldest first</p>

<div class="timeline" style="border-left:3px solid #dbdbdb;margin-left:12px;padding-left:24px">
    <?php foreach ($events as $e) :
        $changes = [];
        if ($e->action === 'products.update' || $e->action === 'users.update') {
            $orig = $e->original_values ? json_decode($e->original_values, true) : [];
            $new = $e->new_values ? json_decode($e->new_values, true) : [];
            $fields = $e->changed_fields ? json_decode($e->changed_fields, true) : [];
            foreach ((array)$fields as $f) {
                $changes[] = $f . ': ' . (string)($orig[$f] ?? '∅') . ' → ' . (string)($new[$f] ?? '∅');
            }
        }
        $isAuth = strpos($e->action, 'login') !== false || strpos($e->action, 'logout') !== false;
        $dot = $isAuth ? '#3273dc' : (strpos($e->action, 'delete') !== false ? '#ff3860' : '#23d160');
    ?>
        <div style="position:relative;margin-bottom:20px">
            <span style="position:absolute;left:-32px;top:2px;width:12px;height:12px;border-radius:50%;background:<?= $dot ?>;border:2px solid #fff;box-shadow:0 0 0 2px <?= $dot ?>"></span>
            <div style="font-size:12px;color:#999"><?= h($e->created->format('M d, Y H:i:s')) ?></div>
            <div><strong><?= h($e->action) ?></strong>
                <?php if ($e->entity_id) : ?>
                    <span style="color:#666">· <?= h($e->table_name) ?> #<?= h($e->entity_id) ?></span>
                <?php endif; ?>
            </div>
            <?php if ($changes) : ?>
                <ul style="margin:6px 0 0 0;font-size:13px;color:#555">
                    <?php foreach ($changes as $c) : ?><li><?= h($c) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!count($events->toArray())) : ?>
        <p><em>No activity recorded for this user yet.</em></p>
    <?php endif; ?>
</div>

<?php
/**
 * @var \App\View\AppView $this
 * @var \App\Model\Entity\Product|null $product
 * @var iterable $events
 * @var string $id
 */
?>
<p><a href="<?= $this->Url->build(['action' => 'index']) ?>">&laquo; Back to audit trail</a></p>
<h2>📦 Product flow — <?= $product ? h($product->name) : 'product #' . h($id) ?></h2>
<p class="note" style="color:#7a7a7a">Everyone who touched this product, oldest first · <?= count($events->toArray()) ?> event(s)</p>

<div class="timeline" style="border-left:3px solid #dbdbdb;margin-left:12px;padding-left:24px">
    <?php foreach ($events as $e) :
        $changes = [];
        if ($e->action === 'products.update') {
            $orig = $e->original_values ? json_decode($e->original_values, true) : [];
            $new = $e->new_values ? json_decode($e->new_values, true) : [];
            $fields = $e->changed_fields ? json_decode($e->changed_fields, true) : [];
            foreach ((array)$fields as $f) {
                $changes[] = $f . ': ' . (string)($orig[$f] ?? '∅') . ' → ' . (string)($new[$f] ?? '∅');
            }
        }
        $dot = strpos($e->action, 'create') !== false ? '#23d160' : (strpos($e->action, 'delete') !== false ? '#ff3860' : '#ffaa00');
    ?>
        <div style="position:relative;margin-bottom:20px">
            <span style="position:absolute;left:-32px;top:2px;width:12px;height:12px;border-radius:50%;background:<?= $dot ?>;border:2px solid #fff;box-shadow:0 0 0 2px <?= $dot ?>"></span>
            <div style="font-size:12px;color:#999"><?= h($e->created->format('M d, Y H:i:s')) ?></div>
            <div><strong><?= h($e->action) ?></strong>
                <span style="color:#666">· by
                    <?php if ($e->user_id) : ?>
                        <a href="<?= $this->Url->build(['action' => 'userFlow', $e->user_id]) ?>"><?= h($e->user->name ?? ('User #' . $e->user_id)) ?></a>
                    <?php else : ?>
                        <em>system</em>
                    <?php endif; ?>
                </span>
            </div>
            <?php if ($changes) : ?>
                <ul style="margin:6px 0 0 0;font-size:13px;color:#555">
                    <?php foreach ($changes as $c) : ?><li><?= h($c) ?></li><?php endforeach; ?>
                </ul>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
    <?php if (!count($events->toArray())) : ?>
        <p><em>No activity recorded for this product yet.</em></p>
    <?php endif; ?>
</div>

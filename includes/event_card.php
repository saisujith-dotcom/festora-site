<?php
/** Renders one event card. Expects $ev (row) with 'taken' count. */
$left = max(0, (int)$ev['capacity'] - (int)$ev['taken']);
$pct = (int)$ev['capacity'] > 0 ? min(100, (int)round((int)$ev['taken'] / (int)$ev['capacity'] * 100)) : 0;
?>
<article class="card event-card">
    <?php if (css_url($ev['image_url']) !== 'none'): ?>
    <div class="card-img" data-cat="<?= e($ev['category']) ?>">
        <div class="card-img-bg" style="background-image:<?= e(css_url($ev['image_url'])) ?>"></div>
        <span class="badge"><?= e($ev['category']) ?></span>
    </div>
    <?php else: ?>
    <div class="card-img is-empty" data-cat="<?= e($ev['category']) ?>">
        <span class="badge"><?= e($ev['category']) ?></span>
    </div>
    <?php endif; ?>
    <div class="card-body">
        <h3><a href="<?= url('event.php?id=' . (int)$ev['id']) ?>"><?= e($ev['title']) ?></a></h3>
        <p class="meta"><?= e(fmt_date($ev['event_date'])) ?> &middot; <?= e(fmt_time($ev['start_time'])) ?></p>
        <p class="meta"><?= e($ev['venue']) ?></p>
        <div class="seat-bar" aria-hidden="true"><span style="width:<?= $pct ?>%"></span></div>
        <p class="seats <?= $left === 0 ? 'full' : '' ?>"><?= $left === 0 ? 'Registration full' : $left . ' of ' . (int)$ev['capacity'] . ' seats left' ?></p>
        <a class="btn btn-sm btn-outline" href="<?= url('event.php?id=' . (int)$ev['id']) ?>">View details</a>
    </div>
</article>

<?php
$pageTitle = 'Home';
$mainClass = 'main-flush';
require __DIR__ . '/includes/header.php';

$events = db()->query("
    SELECT e.*, (SELECT COUNT(*) FROM registrations r WHERE r.event_id=e.id AND r.status='registered') AS taken
    FROM events e WHERE e.event_date >= CURDATE()
    ORDER BY e.event_date, e.start_time LIMIT 6")->fetchAll();

$anns = db()->query("
    SELECT a.*, e.title AS event_title FROM announcements a
    LEFT JOIN events e ON e.id=a.event_id
    ORDER BY a.created_at DESC, a.id DESC LIMIT 3")->fetchAll();
?>
<section class="hero on-dark">
    <div class="art" aria-hidden="true">
        <i class="art-ring r4"></i><i class="art-ring r3"></i><i class="art-ring r2"></i><i class="art-ring r1"></i>
        <span class="art-orb"></span><b class="art-dot"></b>
    </div>
    <div class="container hero-inner">
        <p class="eyebrow">College events, organised</p>
        <h1>Discover. Register. <span>Celebrate.</span></h1>
        <p class="lede">One place for every college event: technical contests, cultural nights, sports and workshops.</p>
        <div class="hero-actions">
            <a class="btn btn-lg btn-light btn-split" href="<?= url('events.php') ?>">Browse all events <?= $navArrow ?></a>
        </div>
        <ul class="hero-index">
            <li><span>01</span><a href="<?= url('events.php') ?>">Events</a></li>
            <li><span>02</span><a href="<?= url($user ? ($user['role'] === 'organizer' ? 'admin/index.php' : 'my-registrations.php') : 'signup.php') ?>">Registrations</a></li>
            <li><span>03</span><a href="#announcements">Announcements</a></li>
        </ul>
    </div>
</section>

<div class="container">
<?php if ($anns): ?>
<section class="section" id="announcements">
    <div class="section-head"><h2>Latest announcements</h2></div>
    <div class="announce-list">
        <?php foreach ($anns as $a): ?>
            <div class="announce">
                <p class="announce-date"><?= e(date('d M Y', strtotime($a['created_at']))) ?></p>
                <div>
                    <strong><?= e($a['title']) ?></strong>
                    <?php if ($a['event_title']): ?><span class="badge badge-soft"><?= e($a['event_title']) ?></span><?php endif; ?>
                    <p><?= e($a['message']) ?></p>
                </div>
            </div>
        <?php endforeach; ?>
    </div>
</section>
<?php endif; ?>

<section class="section">
    <div class="section-head">
        <h2>Upcoming events</h2>
        <a class="link-arrow" href="<?= url('events.php') ?>">View all <?= $navArrow ?></a>
    </div>
    <?php if (!$events): ?>
        <p class="empty">No upcoming events yet. Check back soon.</p>
    <?php else: ?>
        <div class="grid">
            <?php foreach ($events as $ev) { include __DIR__ . '/includes/event_card.php'; } ?>
        </div>
    <?php endif; ?>
</section>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

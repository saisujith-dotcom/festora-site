<?php
require_once __DIR__ . '/includes/bootstrap.php';
require_once __DIR__ . '/includes/event-search.php';
$pageTitle = 'Events';
$pageDesc = 'Search and filter upcoming college events by name and category.';
$q = is_string($_GET['q'] ?? null) ? trim($_GET['q']) : '';
$cat = is_string($_GET['category'] ?? null) ? trim($_GET['category']) : '';
$showPast = ($_GET['past'] ?? '') === '1';
$events = search_events(db(), $q, $cat, $showPast);
require __DIR__ . '/includes/header.php';
?>
<div class="page-head">
    <div>
        <p class="eyebrow">Browse</p>
        <h1>Events</h1>
        <p class="lede">Search by name, venue or keyword, and filter by category.</p>
    </div>
</div>
<form class="filters" id="filter-form" method="get" action="<?= url('events.php') ?>" role="search">
    <label class="sr-only" for="q">Search events</label>
    <input type="search" id="q" name="q" placeholder="Search by name, venue or keyword" value="<?= e($q) ?>">
    <label class="sr-only" for="category">Category</label>
    <select id="category" name="category">
        <option value="">All categories</option>
        <?php foreach (CATEGORIES as $c): ?>
            <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
        <?php endforeach; ?>
    </select>
    <label class="check"><input type="checkbox" id="past" name="past" value="1" <?= $showPast ? 'checked' : '' ?>> Include past</label>
    <button class="btn" type="submit">Search</button>
</form>
<p id="result-count" class="result-count" role="status" aria-live="polite"><?= count($events) ?> <?= count($events) === 1 ? 'event found' : 'events found' ?></p>
<div id="events-grid" class="grid">
    <?php if (!$events): ?>
        <p class="empty">No events match your search.</p>
    <?php else: ?>
        <?php foreach ($events as $ev) { include __DIR__ . '/includes/event_card.php'; } ?>
    <?php endif; ?>
</div>
<?php require __DIR__ . '/includes/footer.php'; ?>

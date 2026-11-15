<?php
require_once __DIR__ . '/bootstrap.php';
$user = current_user();
$pageTitle = $pageTitle ?? APP_NAME;
$flash = flash();
$authLayout = !empty($authLayout);
$mainClass = $mainClass ?? 'container';

// Presentation only: which nav link is current.
$navScript = str_replace('\\', '/', $_SERVER['SCRIPT_NAME'] ?? '');
$navFile = basename($navScript);
$navAdmin = str_contains($navScript, '/admin/');
$navCur = fn(bool $on) => $on ? ' aria-current="page"' : '';
$navArrow = '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M5 12h14M13 6l6 6-6 6"/></svg>';
$navBack = '<svg class="icon" width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true"><path d="M19 12H5M11 6l-6 6 6 6"/></svg>';
$navBrandMark = e(mb_strtoupper(mb_substr(APP_NAME, 0, 1)));
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?= e($pageTitle) ?> | <?= APP_NAME ?></title>
    <meta name="description" content="<?= e($pageDesc ?? 'Festora: discover and register for college events online.') ?>">
    <meta name="theme-color" content="#0a0a0a">
    <link rel="icon" href="data:image/svg+xml,%3Csvg xmlns='http://www.w3.org/2000/svg' viewBox='0 0 32 32'%3E%3Crect width='32' height='32' rx='7' fill='%230a0a0a'/%3E%3Ctext x='16' y='22.5' font-family='Arial,sans-serif' font-size='19' font-weight='700' text-anchor='middle' fill='%23fff'%3EF%3C/text%3E%3C/svg%3E">
    <link rel="stylesheet" href="<?= url('assets/css/style.css') ?>">
</head>
<body data-base="<?= e(BASE_URL) ?>"<?= $authLayout ? ' class="auth-body"' : '' ?>>
<a class="skip-link" href="#main">Skip to content</a>
<?php if ($authLayout): ?>
<div class="auth">
    <aside class="auth-art on-dark">
        <div class="art" aria-hidden="true">
            <i class="art-ring r4"></i><i class="art-ring r3"></i><i class="art-ring r2"></i><i class="art-ring r1"></i>
            <span class="art-orb"></span><b class="art-dot"></b>
        </div>
        <div class="auth-art-top">
            <a class="brand" href="<?= url('index.php') ?>"><span class="brand-mark" aria-hidden="true"><?= $navBrandMark ?></span><?= e(APP_NAME) ?></a>
            <span class="auth-label"><?= e($authLabel ?? 'Event platform') ?></span>
        </div>
        <div class="auth-art-bottom">
            <p class="auth-headline">The fest,<br>organised.</p>
            <ul class="auth-index">
                <li><span>01</span><b>Events</b></li>
                <li><span>02</span><b>Registrations</b></li>
                <li><span>03</span><b>Announcements</b></li>
            </ul>
        </div>
    </aside>
    <main id="main" class="auth-main">
        <div class="auth-top">
            <a class="back-link" href="<?= url('index.php') ?>"><?= $navBack ?>Back to <?= e(APP_NAME) ?></a>
        </div>
        <div class="auth-content">
<?php if ($flash): ?>
            <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['msg']) ?></div>
<?php endif; ?>
<?php else: ?>
<header class="site-header">
    <div class="container nav">
        <a class="brand" href="<?= url('index.php') ?>"><span class="brand-mark" aria-hidden="true"><?= $navBrandMark ?></span><?= e(APP_NAME) ?></a>
        <button class="nav-toggle" type="button" aria-label="Toggle menu" aria-expanded="false" aria-controls="nav-links">
            <svg class="i-open" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M4 7h16M4 12h16M4 17h16"/></svg>
            <svg class="i-close" width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><path d="M6 6l12 12M18 6L6 18"/></svg>
        </button>
        <nav class="nav-links" id="nav-links" aria-label="Main">
            <a href="<?= url('index.php') ?>"<?= $navCur(!$navAdmin && $navFile === 'index.php') ?>>Home</a>
            <a href="<?= url('events.php') ?>"<?= $navCur(!$navAdmin && in_array($navFile, ['events.php', 'event.php'], true)) ?>>Events</a>
            <?php if ($user): ?>
                <?php if (is_admin_role($user['role'])): ?>
                    <?php $navAdminMgmt = $navAdmin && in_array($navFile, ['admins.php', 'admin_form.php', 'admin_events.php'], true); ?>
                    <a href="<?= url('admin/index.php') ?>"<?= $navCur($navAdmin && !$navAdminMgmt && $navFile !== 'announcements.php') ?>>Dashboard</a>
                    <?php if (is_super_admin_role($user['role'])): ?>
                        <a href="<?= url('admin/admins.php') ?>"<?= $navCur($navAdminMgmt) ?>>Event Admins</a>
                    <?php endif; ?>
                    <a href="<?= url('admin/announcements.php') ?>"<?= $navCur($navAdmin && $navFile === 'announcements.php') ?>>Announcements</a>
                <?php else: ?>
                    <a href="<?= url('my-registrations.php') ?>"<?= $navCur($navFile === 'my-registrations.php') ?>>My Registrations</a>
                <?php endif; ?>
                <span class="nav-user">Hi, <?= e(explode(' ', $user['name'])[0]) ?></span>
                <form method="post" action="<?= url('logout.php') ?>" class="nav-logout">
                    <?= csrf_field() ?>
                    <button type="submit" class="btn btn-outline btn-sm">Logout</button>
                </form>
            <?php else: ?>
                <a href="<?= url('login.php') ?>"<?= $navCur($navFile === 'login.php') ?>>Login</a>
                <a class="btn btn-sm" href="<?= url('signup.php') ?>">Sign up</a>
            <?php endif; ?>
        </nav>
    </div>
</header>
<main id="main" class="<?= e($mainClass) ?>">
<?php if ($flash): ?>
    <?= $mainClass === 'container' ? '' : '<div class="container flash-wrap">' ?>
    <div class="alert alert-<?= e($flash['type']) ?>" role="alert"><?= e($flash['msg']) ?></div>
    <?= $mainClass === 'container' ? '' : '</div>' ?>
<?php endif; ?>
<?php endif; ?>

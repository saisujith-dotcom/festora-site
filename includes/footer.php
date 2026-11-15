<?php $footUser = current_user(); ?>
<?php if (!empty($authLayout)): ?>
        </div>
        <footer class="auth-foot">
            <span>&copy; <?= date('Y') ?> <?= e(APP_NAME) ?></span>
            <span>Web development mini-project</span>
        </footer>
    </main>
</div>
<?php else: ?>
</main>
<footer class="site-footer">
    <div class="container">
        <div class="footer-grid">
            <div class="footer-brand">
                <a class="brand" href="<?= url('index.php') ?>"><span class="brand-mark" aria-hidden="true"><?= e(mb_strtoupper(mb_substr(APP_NAME, 0, 1))) ?></span><?= e(APP_NAME) ?></a>
                <p>College Event Registration and Management System. The fest, organised.</p>
            </div>
            <nav aria-label="Explore">
                <p class="footer-title">Explore</p>
                <ul class="footer-links">
                    <li><a href="<?= url('index.php') ?>">Home</a></li>
                    <li><a href="<?= url('events.php') ?>">Events</a></li>
                </ul>
            </nav>
            <nav aria-label="Account">
                <p class="footer-title">Account</p>
                <ul class="footer-links">
                    <?php if ($footUser && is_admin_role($footUser['role'])): ?>
                        <li><a href="<?= url('admin/index.php') ?>">Dashboard</a></li>
                        <li><a href="<?= url('admin/announcements.php') ?>">Announcements</a></li>
                        <li>
                            <form method="post" action="<?= url('logout.php') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="link-button">Logout</button>
                            </form>
                        </li>
                    <?php elseif ($footUser): ?>
                        <li><a href="<?= url('my-registrations.php') ?>">My Registrations</a></li>
                        <li>
                            <form method="post" action="<?= url('logout.php') ?>">
                                <?= csrf_field() ?>
                                <button type="submit" class="link-button">Logout</button>
                            </form>
                        </li>
                    <?php else: ?>
                        <li><a href="<?= url('login.php') ?>">Login</a></li>
                        <li><a href="<?= url('signup.php') ?>">Sign up</a></li>
                    <?php endif; ?>
                </ul>
            </nav>
        </div>
        <div class="footer-bar">
            <p>&copy; <?= date('Y') ?> Festora &middot; College Event Registration and Management System</p>
            <p>Web development mini-project</p>
        </div>
    </div>
</footer>
<?php endif; ?>
<script src="<?= url('assets/js/app.js') ?>"></script>
</body>
</html>

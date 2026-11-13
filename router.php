<?php
/**
 * Router for PHP's built-in server:   php -S localhost:8000 -t public router.php
 *
 * Requires public/ to be the document root, allows only intended PHP entry points, and
 * returns false for public assets and routes. Private code and data remain outside public/.
 * Apache does not use this file; see the deployment guide and .htaccess files.
 *
 * Every URL-decode stage is checked (so %2e%2e, %252e and %5c tricks are caught), traversal
 * segments are refused, Windows quirks are handled (backslashes, trailing dots/spaces, ::$DATA,
 * 8.3 short names), and the file the server will map is checked again after realpath().
 */
if (PHP_SAPI !== 'cli-server') {
    return false;
}

(function (): void {
    $deny = static function (int $code): never {
        http_response_code($code);
        header('Content-Type: text/plain; charset=UTF-8');
        echo match ($code) {
            400 => 'Bad request',
            403 => 'Forbidden',
            500 => 'Server configuration error',
            default => 'Not found',
        };
        exit;
    };

    $protectedDirs = ['backups', 'database', 'includes', 'logs', 'private', 'private-storage',
                      'scripts', 'tests', 'uploads'];
    $protectedExt  = ['sql', 'md', 'bak', 'log', 'dump', 'ini', 'env', 'key', 'lock', 'yml', 'yaml', 'json',
                      'sh', 'bat', 'cmd', 'tmp', 'swp', 'old', 'orig'];

    /** @param list<string> $segments */
    $segmentsBlocked = static function (array $segments) use ($protectedDirs, $protectedExt): bool {
        $first = true;
        foreach ($segments as $seg) {
            if ($seg === '' || $seg === '.') continue;
            if (preg_match('/^\.{2,}$/', $seg)) return true;                 // "..", "..." (traversal)
            if (preg_match('/[:*?<>"|]/', $seg)) return true;               // ADS (::$DATA), drive letters, wildcards
            if (preg_match('/~\d/', $seg)) return true;                     // 8.3 short names (FESTOR~1.SQL)
            $n = strtolower(rtrim($seg, '. '));                              // Windows ignores trailing dots/spaces
            if ($n === '') return true;
            if ($n[0] === '.') return true;                                  // .git, .env, .htaccess, .gitignore ...
            if ($n[strlen($n) - 1] === '~') return true;                     // editor backups
            if (in_array($n, $protectedDirs, true)) return true;
            if (preg_match('/^(config(\.local(\.example)?)?|router)\.php$/', $n)) return true;
            $dot = strrpos($n, '.');
            if ($dot !== false && in_array(substr($n, $dot + 1), $protectedExt, true)) return true;
            $first = false;
        }
        return false;
    };

    // 1. Raw request path (no query / fragment; absolute-form request targets reduced to the path).
    $path = (string)preg_split('/[?#]/', (string)($_SERVER['REQUEST_URI'] ?? '/'), 2)[0];
    if (preg_match('#^[A-Za-z][A-Za-z0-9+.\-]*://[^/]*(/.*)?$#', $path, $m)) $path = $m[1] ?? '/';
    if ($path === '') $path = '/';

    // 2. Decode repeatedly; every stage is inspected. Still changing after 5 rounds = hostile.
    $stages = [$path];
    for ($i = 0; $i < 5; $i++) {
        $last = end($stages);
        $dec = rawurldecode($last);
        if ($dec === $last) break;
        $stages[] = $dec;
    }
    if (rawurldecode(end($stages)) !== end($stages)) $deny(400);

    foreach ($stages as $stage) {
        if (preg_match('/[\x00-\x1F\x7F]/', $stage)) $deny(400);            // NUL / control characters
        if ($segmentsBlocked(explode('/', str_replace('\\', '/', $stage)))) $deny(403);
    }

    // 3. Check the file the server will actually map (it decodes the path once) after realpath().
    $root = realpath(__DIR__ . '/public');
    $documentRoot = realpath((string)($_SERVER['DOCUMENT_ROOT'] ?? ''));
    if ($root === false || $documentRoot === false
        || strcasecmp(str_replace('\\', '/', $root), str_replace('\\', '/', $documentRoot)) !== 0) {
        $deny(500);
    }

    $served = str_replace('\\', '/', $stages[1] ?? $stages[0]);
    $route = '/' . ltrim($served, '/');
    $phpRoutes = [
        '/index.php',
        '/events.php',
        '/event.php',
        '/login.php',
        '/signup.php',
        '/logout.php',
        '/register.php',
        '/my-registrations.php',
        '/admin/index.php',
        '/admin/announcements.php',
        '/admin/event_delete.php',
        '/admin/event_form.php',
        '/admin/participants.php',
        '/admin/schedule.php',
        '/api/events.php',
    ];
    $hasPhpLikePath = preg_match('~\.(?:php[0-9]*|phtml|pht|phar)(?:/|$)~i', $route) === 1;
    if ($hasPhpLikePath && !in_array(strtolower($route), $phpRoutes, true)) {
        $deny(404);
    }

    $real = realpath($root . '/' . ltrim($served, '/'));
    if ($real !== false) {
        $r = str_replace('\\', '/', $root);
        $p = str_replace('\\', '/', $real);
        $win = PHP_OS_FAMILY === 'Windows';
        $inside = $win ? (stripos($p . '/', $r . '/') === 0) : (strpos($p . '/', $r . '/') === 0);
        if (!$inside) $deny(403);                                            // symlink / junction escape
        if ($p !== $r && $segmentsBlocked(explode('/', substr($p, strlen($r) + 1)))) $deny(403);
    }
})();

return false;   // let the built-in server serve or execute the file as usual

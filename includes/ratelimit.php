<?php
/**
 * Persistent, file-based login rate limiting (no database / schema use).
 *
 * Storage (normally outside the project and web document root):
 *   <state dir>/secret.key      random 256-bit HMAC key, created once under an exclusive lock
 *   <state dir>/rl/<hmac>.json  one file per bucket: a JSON list of attempt timestamps (microseconds)
 *
 * State dir, first match wins:
 *   1. FESTORA_STATE_DIR (absolute path)
 *   2. Windows: %LOCALAPPDATA%\Festora
 *   3. $XDG_STATE_HOME/festora, else $HOME/.local/state/festora
 * The only exception is the exact DOCUMENT_ROOT/_festora-state directory, and only when
 * explicitly enabled and protected by a deny-all .htaccess file. If no path is usable, or
 * anything fails while reading or writing, RateLimitUnavailable is thrown and login fails closed.
 *
 * Buckets (sliding window, one attempt is consumed per login POST *before* the password check,
 * under flock, so concurrent requests cannot slip past the limit):
 *   account+IP : 5 / 15 min     account : 20 / 15 min     IP : 30 / 15 min
 * File names are HMAC-SHA256(secret, type|value): raw emails and IPs are never written to disk.
 * The client address is REMOTE_ADDR only (X-Forwarded-For is never trusted); IPv6 is bucketed by /64.
 */

const RL_WINDOW_SECONDS    = 900;
const RL_LIMIT_ACCOUNT_IP  = 5;
const RL_LIMIT_IP          = 30;
const RL_LIMIT_ACCOUNT     = 20;

class RateLimitUnavailable extends RuntimeException {}

function rl_is_absolute(string $p): bool {
    return $p !== '' && strpos($p, "\0") === false
        && (preg_match('#^[A-Za-z]:[\\\\/]#', $p) === 1 || strpos($p, '\\\\') === 0 || $p[0] === '/');
}

function rl_norm(string $p): string {
    $p = rtrim(str_replace('\\', '/', $p), '/');
    return PHP_OS_FAMILY === 'Windows' ? strtolower($p) : $p;
}

function rl_within(string $path, string $root): bool {
    $p = rl_norm($path); $r = rl_norm($root);
    return $r !== '' && ($p === $r || strpos($p . '/', $r . '/') === 0);
}

function rl_docroot_state_is_allowed(string $candidate, string $projectRoot): bool {
    if (getenv('FESTORA_ALLOW_DOCROOT_STATE') !== '1' || is_link($candidate)) return false;

    $documentRoot = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $documentRoot = $documentRoot !== '' ? realpath($documentRoot) : false;
    $stateRoot = realpath($candidate);
    if ($documentRoot === false || $stateRoot === false || !is_dir($stateRoot)
        || rl_within($stateRoot, $projectRoot)) {
        return false;
    }

    $expected = rtrim($documentRoot, '\\/') . DIRECTORY_SEPARATOR . '_festora-state';
    if (rl_norm($stateRoot) !== rl_norm($expected)) return false;

    $accessFile = $stateRoot . DIRECTORY_SEPARATOR . '.htaccess';
    if (is_link($accessFile) || !is_file($accessFile)) return false;
    $policy = @file_get_contents($accessFile);
    $expectedPolicy = "Options -Indexes -ExecCGI\n\n"
        . "<IfModule mod_authz_core.c>\n"
        . "    Require all denied\n"
        . "</IfModule>\n"
        . "<IfModule !mod_authz_core.c>\n"
        . "    Order allow,deny\n"
        . "    Deny from all\n"
        . "</IfModule>";
    return is_string($policy)
        && str_replace(["\r\n", "\r"], "\n", trim($policy)) === $expectedPolicy;
}

/** Validated, private state directory (created with mode 0700 where the OS supports it). */
function rl_state_dir(): string {
    static $dir = null;
    if ($dir !== null) return $dir;

    $cfg = getenv('FESTORA_STATE_DIR');
    if ($cfg !== false && $cfg !== '') {
        $cand = $cfg;
    } elseif (PHP_OS_FAMILY === 'Windows' && ($la = getenv('LOCALAPPDATA')) && $la !== '') {
        $cand = $la . '\\Festora';
    } elseif (($x = getenv('XDG_STATE_HOME')) && $x !== '') {
        $cand = $x . '/festora';
    } elseif (($h = getenv('HOME')) && $h !== '') {
        $cand = $h . '/.local/state/festora';
    } else {
        throw new RateLimitUnavailable('no state directory configured');
    }
    if (!rl_is_absolute($cand)) throw new RateLimitUnavailable('state directory must be an absolute path');
    if (preg_grep('/^\.\.$/', preg_split('#[\\\\/]#', $cand) ?: [])) throw new RateLimitUnavailable('state directory must not contain ".."');

    // Roots the web server may serve. Checked BEFORE anything is created, so a bad setting
    // never leaves a stray directory inside the project.
    $projectRoot = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
    $roots = [$projectRoot];
    $doc = (string)($_SERVER['DOCUMENT_ROOT'] ?? '');
    $documentRoot = $doc !== '' ? realpath($doc) : false;
    if ($documentRoot !== false) $roots[] = $documentRoot;

    // Resolve the candidate through its nearest existing ancestor (follows symlinks/junctions).
    $missing = [];
    $probe = $cand;
    while (!is_dir($probe)) {
        $parent = dirname($probe);
        if ($parent === $probe) throw new RateLimitUnavailable('state directory has no existing parent');
        array_unshift($missing, basename($probe));
        $probe = $parent;
    }
    $base = realpath($probe);
    if ($base === false) throw new RateLimitUnavailable('state directory not resolvable');
    $planned = $missing ? rtrim($base, '\\/') . DIRECTORY_SEPARATOR . implode(DIRECTORY_SEPARATOR, $missing) : $base;
    $allowDocrootState = rl_docroot_state_is_allowed($cand, $projectRoot);
    foreach ($roots as $root) {
        if (rl_within($planned, $root)
            && !($allowDocrootState && $documentRoot !== false && rl_norm($root) === rl_norm($documentRoot))) {
            throw new RateLimitUnavailable('state directory is inside the web root/project');
        }
    }

    if (!is_dir($cand) && !@mkdir($cand, 0700, true) && !is_dir($cand)) {
        throw new RateLimitUnavailable('cannot create state directory');
    }
    $real = realpath($cand);
    if ($real === false || !is_dir($real)) throw new RateLimitUnavailable('state directory not usable');
    $allowRealDocrootState = rl_docroot_state_is_allowed($real, $projectRoot);
    foreach ($roots as $root) {
        if (rl_within($real, $root)
            && !($allowRealDocrootState && $documentRoot !== false && rl_norm($root) === rl_norm($documentRoot))) {
            throw new RateLimitUnavailable('state directory is inside the web root/project');
        }
    }

    $rl = $real . DIRECTORY_SEPARATOR . 'rl';
    if (!is_dir($rl) && !@mkdir($rl, 0700) && !is_dir($rl)) throw new RateLimitUnavailable('cannot create bucket directory');
    return $dir = $real;
}

/** HMAC key. Created exactly once, atomically, under an exclusive lock; malformed key = unavailable. */
function rl_secret(string $dir): string {
    static $secret = null;
    if ($secret !== null) return $secret;
    $path = $dir . DIRECTORY_SEPARATOR . 'secret.key';
    $read = static function () use ($path): ?string {
        $v = @file_get_contents($path);
        return is_string($v) ? trim($v) : null;
    };
    $v = $read();
    if ($v === null) {
        $lock = @fopen($dir . DIRECTORY_SEPARATOR . '.lock', 'c');
        if ($lock === false || !flock($lock, LOCK_EX)) throw new RateLimitUnavailable('cannot lock state directory');
        try {
            $v = $read();                       // another request may have created it while we waited
            if ($v === null) {
                $new = bin2hex(random_bytes(32));
                $tmp = $path . '.' . bin2hex(random_bytes(4)) . '.tmp';
                if (@file_put_contents($tmp, $new, LOCK_EX) !== strlen($new)) { @unlink($tmp); throw new RateLimitUnavailable('cannot write secret'); }
                @chmod($tmp, 0600);
                if (!@rename($tmp, $path)) { @unlink($tmp); throw new RateLimitUnavailable('cannot install secret'); }
                $v = $new;
            }
        } finally {
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }
    if (!preg_match('/^[0-9a-f]{64}$/', (string)$v)) throw new RateLimitUnavailable('secret key malformed');
    return $secret = $v;
}

/** Client identifier from REMOTE_ADDR only. IPv6 is grouped by /64; unparseable = one shared bucket. */
function rl_client_id(): string {
    $packed = @inet_pton((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    if ($packed === false) return 'unknown';
    if (strlen($packed) === 16) {
        if (substr($packed, 0, 12) === "\0\0\0\0\0\0\0\0\0\0\xff\xff") return 'v4:' . bin2hex(substr($packed, 12));
        return 'v6:' . bin2hex(substr($packed, 0, 8));
    }
    return 'v4:' . bin2hex($packed);
}

function rl_open_locked(string $path) {
    for ($i = 0; $i < 3; $i++) {
        $h = @fopen($path, 'c+');
        if ($h === false) throw new RateLimitUnavailable('cannot open bucket');
        if (!flock($h, LOCK_EX)) { fclose($h); throw new RateLimitUnavailable('cannot lock bucket'); }
        clearstatcache(true, $path);
        if (is_file($path)) {                   // not removed by the pruner while we waited for the lock
            if ((int)(fstat($h)['size'] ?? 0) === 0) @chmod($path, 0600);
            return $h;
        }
        flock($h, LOCK_UN);
        fclose($h);
    }
    throw new RateLimitUnavailable('bucket file vanished');
}

function rl_read_bucket($h, int $cutoffUs): array {
    rewind($h);
    $raw = stream_get_contents($h);
    if ($raw === false) throw new RateLimitUnavailable('cannot read bucket');
    if ($raw === '') return [];
    $d = json_decode($raw, true);
    if (!is_array($d)) throw new RateLimitUnavailable('bucket file corrupt');
    $out = [];
    foreach ($d as $v) if (is_int($v) && $v > $cutoffUs) $out[] = $v;
    sort($out);
    return $out;
}

function rl_write_bucket($h, array $entries): void {
    $json = json_encode(array_values($entries));
    rewind($h);
    if (!ftruncate($h, 0)) throw new RateLimitUnavailable('cannot truncate bucket');
    rewind($h);
    if (fwrite($h, $json) !== strlen($json) || !fflush($h)) throw new RateLimitUnavailable('cannot write bucket');
}

/** Lock all given bucket files in a fixed (sorted) order, run $fn(handles), always unlock. */
function rl_with_buckets(array $paths, callable $fn) {
    asort($paths, SORT_STRING);
    $handles = [];
    try {
        foreach ($paths as $name => $p) $handles[$name] = rl_open_locked($p);
        return $fn($handles);
    } finally {
        foreach ($handles as $h) { @flock($h, LOCK_UN); @fclose($h); }
    }
}

/**
 * Atomically check and consume one login attempt.
 * @return array{allowed:bool, retry_after:int, token:array}
 * @throws RateLimitUnavailable (caller must fail closed)
 */
function rl_login_attempt(string $email, string $clientId): array {
    $dir    = rl_state_dir();
    $secret = rl_secret($dir);
    $email  = strtolower(trim($email));
    $key = static fn(string $type, string $value): string => hash_hmac('sha256', $type . "\0" . $value, $secret);

    $limits = ['account_ip' => RL_LIMIT_ACCOUNT_IP, 'ip' => RL_LIMIT_IP, 'account' => RL_LIMIT_ACCOUNT];
    $paths = [
        'account_ip' => $dir . DIRECTORY_SEPARATOR . 'rl' . DIRECTORY_SEPARATOR . $key('account_ip', $email . "\0" . $clientId) . '.json',
        'ip'         => $dir . DIRECTORY_SEPARATOR . 'rl' . DIRECTORY_SEPARATOR . $key('ip', $clientId) . '.json',
        'account'    => $dir . DIRECTORY_SEPARATOR . 'rl' . DIRECTORY_SEPARATOR . $key('account', $email) . '.json',
    ];
    $window = RL_WINDOW_SECONDS * 1000000;

    $result = rl_with_buckets($paths, function (array $handles) use ($limits, $window, $paths): array {
        $now = (int)round(microtime(true) * 1000000);
        $entries = [];
        $retry = 0;
        foreach ($handles as $name => $h) {
            $entries[$name] = rl_read_bucket($h, $now - $window);
            $n = count($entries[$name]);
            if ($n >= $limits[$name]) {
                $freeAt = $entries[$name][$n - $limits[$name]] + $window;   // when the next slot opens
                $retry = max($retry, (int)ceil(($freeAt - $now) / 1000000));
            }
        }
        if ($retry > 0) return ['allowed' => false, 'retry_after' => max(1, $retry), 'token' => []];
        foreach ($handles as $name => $h) {
            $entries[$name][] = $now;
            rl_write_bucket($h, $entries[$name]);
        }
        return ['allowed' => true, 'retry_after' => 0, 'token' => ['ts' => $now, 'paths' => $paths]];
    });

    if ($result['allowed'] && random_int(1, 100) === 1) {
        try { rl_prune($dir); } catch (Throwable $e) { /* housekeeping only */ }
    }
    return $result;
}

/** After a successful login: refund this attempt and reset the account+IP bucket. Never throws. */
function rl_login_success(array $token): void {
    try {
        if (empty($token['paths']) || !isset($token['ts'])) return;
        rl_with_buckets($token['paths'], function (array $handles) use ($token): void {
            $now = (int)round(microtime(true) * 1000000);
            foreach ($handles as $name => $h) {
                $e = rl_read_bucket($h, $now - RL_WINDOW_SECONDS * 1000000);
                $e = $name === 'account_ip' ? [] : array_values(array_diff($e, [$token['ts']]));
                rl_write_bucket($h, $e);
            }
        });
    } catch (Throwable $e) {
        error_log('festora rate-limit success cleanup failed: ' . get_class($e));
    }
}

/** Remove bucket files whose entries have all expired. Skips any file that is busy. */
function rl_prune(string $dir): void {
    $cutoff = time() - RL_WINDOW_SECONDS - 60;
    foreach (glob($dir . DIRECTORY_SEPARATOR . 'rl' . DIRECTORY_SEPARATOR . '*.json') ?: [] as $f) {
        clearstatcache(true, $f);
        $m = @filemtime($f);
        if ($m === false || $m >= $cutoff) continue;
        $h = @fopen($f, 'c+');
        if ($h === false) continue;
        if (!flock($h, LOCK_EX | LOCK_NB)) { fclose($h); continue; }
        clearstatcache(true, $f);
        if ((@filemtime($f) ?: PHP_INT_MAX) < $cutoff) {
            if (PHP_OS_FAMILY === 'Windows') { flock($h, LOCK_UN); fclose($h); @unlink($f); continue; }
            @unlink($f);                        // POSIX: unlink while holding the lock; waiters re-check is_file()
        }
        flock($h, LOCK_UN);
        fclose($h);
    }
}

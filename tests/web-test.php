<?php
if (PHP_SAPI !== 'cli') {
    exit(2);
}

$project = dirname(__DIR__);
require $project . '/config.php';

$input = json_decode((string)stream_get_contents(STDIN), true);
if (!is_array($input) || !isset($input['database'], $input['temporary_root'], $input['admin_email'],
    $input['admin_password'], $input['second_student_email'], $input['second_student_password'])) {
    fwrite(STDERR, 'Invalid web-test input.');
    exit(3);
}

$passed = 0;
$stage = 'initializing';
$failure = false;
$serverProcess = null;
$testPdo = null;

function web_check(bool $condition, string $name): void {
    global $passed;
    if (!$condition) {
        throw new RuntimeException('Assertion failed: ' . $name);
    }
    $passed++;
    echo 'PASS HTTP ' . $name . PHP_EOL;
}

function web_copy_tree(string $source, string $destination): void {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($source, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST
    );
    foreach ($iterator as $item) {
        $relative = substr($item->getPathname(), strlen($source) + 1);
        if ($relative === 'config.local.php') {
            continue;
        }
        $target = $destination . DIRECTORY_SEPARATOR . $relative;
        if ($item->isDir()) {
            if (!is_dir($target) && !mkdir($target, 0700, true) && !is_dir($target)) {
                throw new RuntimeException('Could not prepare the isolated HTTP application.');
            }
        } else {
            $parent = dirname($target);
            if (!is_dir($parent) && !mkdir($parent, 0700, true) && !is_dir($parent)) {
                throw new RuntimeException('Could not prepare the isolated HTTP application.');
            }
            if (!copy($item->getPathname(), $target)) {
                throw new RuntimeException('Could not copy an application test file.');
            }
        }
    }
}

function web_request(string $base, string $path, string $cookieFile, ?array $fields = null): array {
    $handle = curl_init($base . $path);
    if ($handle === false) {
        throw new RuntimeException('Could not initialize the local HTTP client.');
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HEADER => true,
        CURLOPT_FOLLOWLOCATION => false,
        CURLOPT_CONNECTTIMEOUT => 3,
        CURLOPT_TIMEOUT => 8,
        CURLOPT_COOKIEFILE => $cookieFile,
        CURLOPT_COOKIEJAR => $cookieFile,
    ]);
    if ($fields !== null) {
        curl_setopt($handle, CURLOPT_POST, true);
        curl_setopt($handle, CURLOPT_POSTFIELDS, http_build_query($fields));
    }
    $response = curl_exec($handle);
    if (!is_string($response)) {
        $reason = curl_error($handle);
        curl_close($handle);
        throw new RuntimeException('Local HTTP request failed: ' . $reason);
    }
    $status = (int)curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    $headerSize = (int)curl_getinfo($handle, CURLINFO_HEADER_SIZE);
    curl_close($handle);
    return [
        'status' => $status,
        'headers' => substr($response, 0, $headerSize),
        'body' => substr($response, $headerSize),
    ];
}

function web_csrf(string $html): string {
    if (!preg_match('/name="csrf"\s+value="([^"]+)"/', $html, $matches)) {
        throw new RuntimeException('A rendered form did not contain its CSRF field.');
    }
    return $matches[1];
}

function web_login(string $base, string $cookieFile, string $email, string $password): array {
    $page = web_request($base, '/login.php', $cookieFile);
    $token = web_csrf($page['body']);
    $response = web_request($base, '/login.php', $cookieFile, [
        'csrf' => $token,
        'email' => $email,
        'password' => $password,
        'next' => '',
    ]);
    return [$response, $token];
}

function web_cookie_value(string $cookieFile): string {
    $contents = is_file($cookieFile) ? file_get_contents($cookieFile) : '';
    return is_string($contents) && preg_match('/\tPHPSESSID\t([^\t\r\n]+)/', $contents, $matches)
        ? $matches[1] : '';
}

$temporaryRoot = $input['temporary_root'];
$appRoot = $temporaryRoot . DIRECTORY_SEPARATOR . 'web-app';
$logFile = $temporaryRoot . DIRECTORY_SEPARATOR . 'web-server.log';
$sessionState = $temporaryRoot . DIRECTORY_SEPARATOR . 'web-state';
$cookieDirectory = $temporaryRoot . DIRECTORY_SEPARATOR . 'cookies';
mkdir($appRoot, 0700, true);
mkdir($cookieDirectory, 0700, true);
mkdir($sessionState, 0700, true);

try {
    $stage = 'creating isolated application copy';
    web_copy_tree($project, $appRoot);
    $privateUploadDirectory = $appRoot . DIRECTORY_SEPARATOR . 'private-storage'
        . DIRECTORY_SEPARATOR . 'uploads';
    if (!mkdir($privateUploadDirectory, 0700, true)
        || file_put_contents($privateUploadDirectory . DIRECTORY_SEPARATOR . 'upload-probe.php',
            '<?php echo "UPLOAD_PROBE_EXECUTED";') === false
        || file_put_contents($privateUploadDirectory . DIRECTORY_SEPARATOR . 'upload-probe.txt',
            'PRIVATE_UPLOAD_PROBE') === false) {
        throw new RuntimeException('Could not create the isolated private-upload probe.');
    }
    if (file_put_contents(
        $appRoot . DIRECTORY_SEPARATOR . 'public' . DIRECTORY_SEPARATOR . 'assets'
            . DIRECTORY_SEPARATOR . 'img' . DIRECTORY_SEPARATOR . 'events'
            . DIRECTORY_SEPARATOR . 'upload-execution-probe.php',
        '<?php echo "PUBLIC_UPLOAD_PROBE_EXECUTED";'
    ) === false) {
        throw new RuntimeException('Could not create the isolated public execution probe.');
    }
    $testPdo = new PDO(
        'mysql:host=' . DB_HOST . ';port=' . (int)DB_PORT . ';dbname=' . $input['database'] . ';charset=utf8mb4',
        DB_USER,
        DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );

    $socket = stream_socket_server('tcp://127.0.0.1:0', $socketError, $socketMessage);
    if (!is_resource($socket)) {
        throw new RuntimeException('Could not allocate a local HTTP test port.');
    }
    $socketName = stream_socket_get_name($socket, false);
    fclose($socket);
    $port = (int)substr(strrchr((string)$socketName, ':'), 1);
    $base = 'http://127.0.0.1:' . $port;

    $environment = getenv();
    if (!is_array($environment)) {
        $environment = [];
    }
    $environment['FESTORA_DB_HOST'] = DB_HOST;
    $environment['FESTORA_DB_NAME'] = $input['database'];
    $environment['FESTORA_DB_USER'] = DB_USER;
    $environment['FESTORA_DB_PASS'] = DB_PASS;
    $environment['FESTORA_ENV'] = 'development';
    $environment['FESTORA_DEBUG'] = '0';
    $environment['FESTORA_STATE_DIR'] = $sessionState;
    unset($environment['FESTORA_TRUSTED_PROXY_IPS']);

    $log = fopen($logFile, 'ab');
    $descriptors = [0 => ['pipe', 'r'], 1 => $log, 2 => $log];
    $serverProcess = proc_open([
        PHP_BINARY,
        '-S',
        '127.0.0.1:' . $port,
        '-t',
        $appRoot . DIRECTORY_SEPARATOR . 'public',
        $appRoot . DIRECTORY_SEPARATOR . 'router.php',
    ],
        $descriptors, $serverPipes, $appRoot, $environment, ['bypass_shell' => true]);
    if (!is_resource($serverProcess)) {
        fclose($log);
        throw new RuntimeException('Could not start the local PHP HTTP server.');
    }
    fclose($serverPipes[0]);
    fclose($log);

    $stage = 'waiting for isolated server';
    $ready = false;
    $startupError = '';
    $probeCookie = $cookieDirectory . DIRECTORY_SEPARATOR . 'probe.txt';
    file_put_contents($probeCookie, '');
    for ($attempt = 0; $attempt < 50; $attempt++) {
        try {
            $probe = web_request($base, '/login.php', $probeCookie);
            if ($probe['status'] === 200) {
                $ready = true;
                break;
            }
        } catch (RuntimeException $error) {
            $startupError = $error->getMessage();
        }
        usleep(100000);
    }
    if (!$ready) {
        $status = proc_get_status($serverProcess);
        $serverLog = is_file($logFile) ? file_get_contents($logFile) : '';
        throw new RuntimeException('The local PHP server did not become ready (running='
            . (!empty($status['running']) ? 'yes' : 'no') . '). ' . $startupError . ' ' . substr((string)$serverLog, -1200));
    }

    $stage = 'private-file HTTP denial and headers';
    foreach ([
        '/config.local.php',
        '/config.local.example.php',
        '/config.php',
        '/includes/bootstrap.php',
        '/database/festora.sql',
        '/database/migrations/001_create_users.sql',
        '/scripts/provision-admin.php',
        '/tests/run.php',
        '/.gitignore',
        '/README.md',
        '/router.php',
        '/.htaccess',
        '/private-storage/uploads/upload-probe.php',
        '/private-storage/uploads/upload-probe.txt',
        '/uploads/upload-probe.php',
        '/assets/img/events/upload-execution-probe.php',
    ] as $privatePath) {
        $denied = web_request($base, $privatePath, $probeCookie);
        web_check(in_array($denied['status'], [403, 404], true),
            'public document root denies ' . $privatePath);
    }
    $privateUpload = web_request($base, '/private-storage/uploads/upload-probe.php', $probeCookie);
    web_check(in_array($privateUpload['status'], [403, 404], true)
        && !str_contains($privateUpload['body'], 'UPLOAD_PROBE_EXECUTED'),
        'private PHP upload probe is inaccessible and never executes');
    $privateTextUpload = web_request(
        $base,
        '/private-storage/uploads/upload-probe.txt',
        $probeCookie
    );
    web_check(in_array($privateTextUpload['status'], [403, 404], true)
        && !str_contains($privateTextUpload['body'], 'PRIVATE_UPLOAD_PROBE'),
        'private non-executable upload content is inaccessible');
    $publicPhpUpload = web_request(
        $base,
        '/assets/img/events/upload-execution-probe.php',
        $probeCookie
    );
    web_check(in_array($publicPhpUpload['status'], [403, 404], true)
        && !str_contains($publicPhpUpload['body'], 'PUBLIC_UPLOAD_PROBE_EXECUTED'),
        'unapproved PHP files inside public assets are not executable');
    $publicPhpPathInfo = web_request(
        $base,
        '/assets/img/events/upload-execution-probe.php/path-info',
        $probeCookie
    );
    web_check(in_array($publicPhpPathInfo['status'], [403, 404], true)
        && !str_contains($publicPhpPathInfo['body'], 'PUBLIC_UPLOAD_PROBE_EXECUTED'),
        'unapproved PHP files cannot execute through PATH_INFO');
    web_check(str_contains($probe['headers'], 'X-Content-Type-Options: nosniff')
        && str_contains($probe['headers'], 'X-Frame-Options: SAMEORIGIN'),
        'security headers are sent');

    $home = web_request($base, '/', $probeCookie);
    $homePage = web_request($base, '/index.php', $probeCookie);
    web_check($home['status'] === 200 && $homePage['status'] === 200
        && str_contains($homePage['body'], 'Browse all events'),
        'home page and root index entrypoint remain available');
    $assets = [
        '/assets/css/style.css' => 'text/css',
        '/assets/js/app.js' => 'javascript',
        '/assets/img/events/cultural.svg' => 'image/svg+xml',
        '/assets/img/events/other.svg' => 'image/svg+xml',
        '/assets/img/events/seminar.svg' => 'image/svg+xml',
        '/assets/img/events/sports.svg' => 'image/svg+xml',
        '/assets/img/events/technical.svg' => 'image/svg+xml',
        '/assets/img/events/workshop.svg' => 'image/svg+xml',
    ];
    foreach ($assets as $assetPath => $expectedType) {
        $asset = web_request($base, $assetPath, $probeCookie);
        preg_match('/^Content-Type:\s*([^;\r\n]+)/im', $asset['headers'], $type);
        $contentType = strtolower(trim($type[1] ?? ''));
        web_check($asset['status'] === 200 && $asset['body'] !== ''
            && str_contains($contentType, $expectedType),
            'public asset is served: ' . $assetPath);
    }

    $stage = 'server-rendered event listing and API';
    $listing = web_request($base, '/events.php', $probeCookie);
    web_check($listing['status'] === 200 && str_contains($listing['body'], 'AI in Everyday Life')
        && !str_contains($listing['body'], 'JavaScript is needed'),
        'event listing renders without JavaScript');
    $filtered = web_request($base, '/events.php?q=AI', $probeCookie);
    web_check($filtered['status'] === 200 && str_contains($filtered['body'], 'AI in Everyday Life'),
        'GET search works without JavaScript');
    $api = web_request($base, '/api/events.php?q=Everyday', $probeCookie);
    $apiData = json_decode($api['body'], true);
    if (!($api['status'] === 200 && is_array($apiData) && $apiData['count'] === 1)) {
        throw new RuntimeException('API result status=' . $api['status'] . ' count='
            . (is_array($apiData) ? (string)($apiData['count'] ?? 'missing') : 'invalid-json'));
    }
    web_check(true, 'events API returns filtered JSON');
    $injection = web_request($base, '/api/events.php?q=%27%20OR%201%3D1%20--', $probeCookie);
    $injectionData = json_decode($injection['body'], true);
    web_check($injection['status'] === 200 && is_array($injectionData) && $injectionData['count'] === 0,
        'events API treats SQL injection payload as search data');

    $stage = 'student signup, login and registration';
    $studentCookie = $cookieDirectory . DIRECTORY_SEPARATOR . 'student.txt';
    file_put_contents($studentCookie, '');
    $signup = web_request($base, '/signup.php', $studentCookie);
    $signupToken = web_csrf($signup['body']);
    $studentEmail = 'http-' . bin2hex(random_bytes(5)) . '@regression.invalid';
    $studentPassword = 'Web-Regression-Password-519!';
    $studentName = '<script>alert(1)</script>';
    $signupFields = [
        'csrf' => $signupToken,
        'name' => $studentName,
        'email' => $studentEmail,
        'enrollment_no' => '25HTTP' . strtoupper(bin2hex(random_bytes(2))),
        'branch' => 'CSE',
        'password' => $studentPassword,
        'password2' => $studentPassword,
    ];
    $badSignup = $signupFields;
    $badSignup['csrf'] = 'invalid';
    $csrfSignup = web_request($base, '/signup.php', $studentCookie, $badSignup);
    $created = $testPdo->prepare('SELECT COUNT(*) FROM users WHERE email=?');
    $created->execute([$studentEmail]);
    web_check($csrfSignup['status'] === 419 && (int)$created->fetchColumn() === 0,
        'signup rejects missing/invalid CSRF without creating an account');

    $invalidSignup = $signupFields;
    $invalidSignup['csrf'] = $signupToken;
    $invalidSignup['email'] = 'not-an-email';
    $invalidSignup['password'] = 'short';
    $invalidSignup['password2'] = 'short';
    $invalidSignupResponse = web_request($base, '/signup.php', $studentCookie, $invalidSignup);
    $created->execute([$studentEmail]);
    web_check($invalidSignupResponse['status'] === 200
        && str_contains($invalidSignupResponse['body'], 'valid email address')
        && (int)$created->fetchColumn() === 0,
        'signup validates malformed input without creating an account');

    $signupResponse = web_request($base, '/signup.php', $studentCookie, $signupFields);
    $created->execute([$studentEmail]);
    web_check($signupResponse['status'] === 302 && (int)$created->fetchColumn() === 1,
        'student signup creates an isolated account');

    $loginPage = web_request($base, '/login.php', $studentCookie);
    $loginToken = web_csrf($loginPage['body']);
    $wrongPassword = web_request($base, '/login.php', $studentCookie, [
        'csrf' => $loginToken, 'email' => $studentEmail, 'password' => 'wrong-password', 'next' => '',
    ]);
    web_check($wrongPassword['status'] === 200 && str_contains($wrongPassword['body'], 'Invalid email or password.'),
        'invalid credentials receive a generic login error');
    $sqlLogin = web_request($base, '/login.php', $studentCookie, [
        'csrf' => $loginToken, 'email' => "' OR 1=1 --", 'password' => 'not-a-password', 'next' => '',
    ]);
    web_check($sqlLogin['status'] === 200 && str_contains($sqlLogin['body'], 'Invalid email or password.'),
        'login query does not authenticate a SQL injection payload');
    $beforeLoginId = web_cookie_value($studentCookie);
    $loginResponse = web_request($base, '/login.php', $studentCookie, [
        'csrf' => $loginToken, 'email' => $studentEmail, 'password' => $studentPassword, 'next' => '',
    ]);
    $afterLoginId = web_cookie_value($studentCookie);
    web_check($loginResponse['status'] === 302 && $beforeLoginId !== '' && $afterLoginId !== ''
        && $beforeLoginId !== $afterLoginId
        && stripos($loginResponse['headers'], 'HttpOnly') !== false
        && stripos($loginResponse['headers'], 'SameSite=Lax') !== false,
        'login rotates the session identifier and sets HttpOnly/SameSite cookies');
    $preAuthCsrf = web_request($base, '/register.php', $studentCookie, [
        'csrf' => $loginToken, 'event_id' => 1, 'action' => 'register',
    ]);
    web_check($preAuthCsrf['status'] === 419, 'login rotates the CSRF token with the session');
    $adminDenied = web_request($base, '/admin/index.php', $studentCookie);
    web_check($adminDenied['status'] === 403, 'student cannot access organizer pages');

    $ai = $testPdo->prepare("SELECT id FROM events WHERE title='AI in Everyday Life' LIMIT 1");
    $ai->execute();
    $aiEventId = (int)$ai->fetchColumn();
    $eventPage = web_request($base, '/event.php?id=' . $aiEventId, $studentCookie);
    web_check($eventPage['status'] === 200 && str_contains($eventPage['body'], '&lt;script&gt;alert(1)&lt;/script&gt;')
        && !str_contains($eventPage['body'], '<script>alert(1)</script>'),
        'stored student name is HTML-escaped in page output');
    $csrf = web_csrf($eventPage['body']);
    $missingRegistrationCsrf = web_request($base, '/register.php', $studentCookie, [
        'event_id' => $aiEventId, 'action' => 'register',
    ]);
    web_check($missingRegistrationCsrf['status'] === 419,
        'registration rejects missing CSRF tokens');

    $registrationResponse = web_request($base, '/register.php', $studentCookie, [
        'csrf' => $csrf, 'event_id' => $aiEventId, 'action' => 'register',
    ]);
    $activeRegistrations = $testPdo->prepare(
        "SELECT COUNT(*) FROM registrations WHERE event_id=? AND user_id=(SELECT id FROM users WHERE email=?) AND status='registered'"
    );
    $activeRegistrations->execute([$aiEventId, $studentEmail]);
    web_check($registrationResponse['status'] === 302 && (int)$activeRegistrations->fetchColumn() === 1,
        'student registration is persisted');

    $secondStudentCookie = $cookieDirectory . DIRECTORY_SEPARATOR . 'second-student.txt';
    file_put_contents($secondStudentCookie, '');
    [$secondStudentLogin] = web_login(
        $base, $secondStudentCookie, $input['second_student_email'], $input['second_student_password']
    );
    $otherStudentToken = web_csrf(web_request($base, '/event.php?id=' . $aiEventId, $secondStudentCookie)['body']);
    $firstStudentIdQuery = $testPdo->prepare('SELECT id FROM users WHERE email=?');
    $firstStudentIdQuery->execute([$studentEmail]);
    $otherStudentCancel = web_request($base, '/register.php', $secondStudentCookie, [
        'csrf' => $otherStudentToken, 'event_id' => $aiEventId, 'action' => 'cancel',
        'user_id' => (int)$firstStudentIdQuery->fetchColumn(),
    ]);
    $activeRegistrations->execute([$aiEventId, $studentEmail]);
    web_check($secondStudentLogin['status'] === 302 && $otherStudentCancel['status'] === 302
        && (int)$activeRegistrations->fetchColumn() === 1,
        'student cannot cancel another user registration with a forged user id');

    $duplicateResponse = web_request($base, '/register.php', $studentCookie, [
        'csrf' => $csrf, 'event_id' => $aiEventId, 'action' => 'register',
    ]);
    $activeRegistrations->execute([$aiEventId, $studentEmail]);
    web_check($duplicateResponse['status'] === 302 && (int)$activeRegistrations->fetchColumn() === 1,
        'duplicate registration does not create another active row');
    $myRegistrations = web_request($base, '/my-registrations.php', $studentCookie);
    web_check($myRegistrations['status'] === 200 && str_contains($myRegistrations['body'], 'AI in Everyday Life'),
        'student can view their registrations');
    $cancel = web_request($base, '/register.php', $studentCookie, [
        'csrf' => $csrf, 'event_id' => $aiEventId, 'action' => 'cancel',
    ]);
    $cancelled = $testPdo->prepare(
        "SELECT COUNT(*) FROM registrations WHERE event_id=? AND user_id=(SELECT id FROM users WHERE email=?) AND status='cancelled'"
    );
    $cancelled->execute([$aiEventId, $studentEmail]);
    web_check($cancel['status'] === 302 && (int)$cancelled->fetchColumn() === 1,
        'student can cancel while preserving the unique registration row');

    $logoutGet = web_request($base, '/logout.php', $studentCookie);
    $logoutCsrf = web_request($base, '/logout.php', $studentCookie, []);
    $stillLoggedIn = web_request($base, '/my-registrations.php', $studentCookie);
    web_check($logoutGet['status'] === 405 && $logoutCsrf['status'] === 419
        && $stillLoggedIn['status'] === 200,
        'GET and CSRF-less logout requests do not end the session');
    $oldSessionCookie = $studentCookie . '.old';
    if (!copy($studentCookie, $oldSessionCookie)) {
        throw new RuntimeException('Could not preserve the pre-logout cookie for invalidation testing.');
    }
    $logoutOldId = web_cookie_value($studentCookie);
    $logout = web_request($base, '/logout.php', $studentCookie, ['csrf' => $csrf]);
    $logoutNewId = web_cookie_value($studentCookie);
    $afterLogout = web_request($base, '/my-registrations.php', $oldSessionCookie);
    $logoutPassed = $logout['status'] === 302 && $afterLogout['status'] === 302
        && $logoutOldId !== '' && $logoutNewId !== '' && $logoutOldId !== $logoutNewId;
    if (!$logoutPassed) {
        throw new RuntimeException('Logout response=' . $logout['status'] . ' follow-up=' . $afterLogout['status']
            . ' old-cookie=' . ($logoutOldId !== '' ? 'present' : 'missing')
            . ' new-cookie=' . ($logoutNewId !== '' ? 'present' : 'missing')
            . ' cookie-rotated=' . ($logoutOldId !== '' && $logoutOldId !== $logoutNewId ? 'yes' : 'no')
            . ' old-cookie-rejected=' . ($afterLogout['status'] === 302 ? 'yes' : 'no'));
    }
    web_check(true, 'valid POST logout rotates the cookie and invalidates the old authenticated session');

    $stage = 'organizer/admin functionality';
    $adminCookie = $cookieDirectory . DIRECTORY_SEPARATOR . 'admin.txt';
    file_put_contents($adminCookie, '');
    [$adminLogin] = web_login($base, $adminCookie, $input['admin_email'], $input['admin_password']);
    web_check($adminLogin['status'] === 302, 'provisioned organizer can log in');
    $dashboard = web_request($base, '/admin/index.php', $adminCookie);
    web_check($dashboard['status'] === 200 && str_contains($dashboard['body'], 'Organizer Dashboard'),
        'organizer dashboard is accessible');

    $createPage = web_request($base, '/admin/event_form.php', $adminCookie);
    $adminToken = web_csrf($createPage['body']);
    $eventTitle = '<script>alert(2)</script>';
    $newEvent = [
        'csrf' => $adminToken,
        'id' => '0',
        'title' => $eventTitle,
        'description' => 'A disposable HTTP integration event for regression testing.',
        'category' => 'Technical',
        'venue' => 'HTTP Test Hall',
        'event_date' => date('Y-m-d', strtotime('+5 days')),
        'start_time' => '10:00',
        'end_time' => '11:00',
        'capacity' => '2',
        'image_url' => '',
    ];
    $createResponse = web_request($base, '/admin/event_form.php', $adminCookie, $newEvent);
    $eventLookup = $testPdo->prepare('SELECT id FROM events WHERE title=?');
    $eventLookup->execute([$eventTitle]);
    $httpEventId = (int)$eventLookup->fetchColumn();
    web_check($createResponse['status'] === 302 && $httpEventId > 0,
        'organizer can create an event');
    $dashboard = web_request($base, '/admin/index.php', $adminCookie);
    web_check(str_contains($dashboard['body'], '&lt;script&gt;alert(2)&lt;/script&gt;')
        && !str_contains($dashboard['body'], '<script>alert(2)</script>'),
        'stored event title is escaped in organizer dashboard');

    $schedulePage = web_request($base, '/admin/schedule.php?event_id=' . $httpEventId, $adminCookie);
    $scheduleToken = web_csrf($schedulePage['body']);
    $scheduleAdd = web_request($base, '/admin/schedule.php?event_id=' . $httpEventId, $adminCookie, [
        'csrf' => $scheduleToken, 'event_id' => $httpEventId, 'item_time' => '10:30', 'title' => 'Check-in and welcome',
    ]);
    $scheduleCount = $testPdo->prepare('SELECT COUNT(*) FROM schedule_items WHERE event_id=?');
    $scheduleCount->execute([$httpEventId]);
    web_check($scheduleAdd['status'] === 302 && (int)$scheduleCount->fetchColumn() === 1,
        'organizer can add a schedule item');

    $announcementPage = web_request($base, '/admin/announcements.php', $adminCookie);
    $announcementToken = web_csrf($announcementPage['body']);
    $announcementAdd = web_request($base, '/admin/announcements.php', $adminCookie, [
        'csrf' => $announcementToken,
        'title' => 'HTTP test announcement',
        'event_id' => $httpEventId,
        'message' => 'A disposable notice for the public event page.',
    ]);
    $announcementCount = $testPdo->prepare('SELECT COUNT(*) FROM announcements WHERE event_id=?');
    $announcementCount->execute([$httpEventId]);
    web_check($announcementAdd['status'] === 302 && (int)$announcementCount->fetchColumn() === 1,
        'organizer can create an announcement');
    $publicEvent = web_request($base, '/event.php?id=' . $httpEventId, $probeCookie);
    web_check(str_contains($publicEvent['body'], 'Check-in and welcome')
        && str_contains($publicEvent['body'], 'HTTP test announcement'),
        'public event page shows schedules and announcements');

    $firstStudentCookie = $cookieDirectory . DIRECTORY_SEPARATOR . 'first-student-again.txt';
    file_put_contents($firstStudentCookie, '');
    [$firstStudentLogin] = web_login($base, $firstStudentCookie, $studentEmail, $studentPassword);
    web_check($firstStudentLogin['status'] === 302, 'student can authenticate after logout');
    foreach ([$firstStudentCookie, $secondStudentCookie] as $cookie) {
        $detail = web_request($base, '/event.php?id=' . $httpEventId, $cookie);
        $studentToken = web_csrf($detail['body']);
        $registered = web_request($base, '/register.php', $cookie, [
            'csrf' => $studentToken, 'event_id' => $httpEventId, 'action' => 'register',
        ]);
        web_check($registered['status'] === 302, 'student can register for organizer-created event');
    }
    $activeCount = $testPdo->prepare(
        "SELECT COUNT(*) FROM registrations WHERE event_id=? AND status='registered'"
    );
    $activeCount->execute([$httpEventId]);
    web_check((int)$activeCount->fetchColumn() === 2, 'two students are active before capacity reduction');

    $editPage = web_request($base, '/admin/event_form.php?id=' . $httpEventId, $adminCookie);
    $editToken = web_csrf($editPage['body']);
    $reduced = $newEvent;
    $reduced['csrf'] = $editToken;
    $reduced['id'] = (string)$httpEventId;
    $reduced['capacity'] = '1';
    $reduceResponse = web_request($base, '/admin/event_form.php?id=' . $httpEventId, $adminCookie, $reduced);
    $capacityQuery = $testPdo->prepare('SELECT capacity FROM events WHERE id=?');
    $capacityQuery->execute([$httpEventId]);
    web_check($reduceResponse['status'] === 200
        && str_contains($reduceResponse['body'], 'Capacity cannot be lower than the number of active registrations.')
        && (int)$capacityQuery->fetchColumn() === 2,
        'admin capacity reduction below registrations is rejected');

    $participantsPage = web_request($base, '/admin/participants.php?event_id=' . $httpEventId, $adminCookie);
    $participantsToken = web_csrf($participantsPage['body']);
    $registrationLookup = $testPdo->prepare(
        "SELECT id FROM registrations WHERE event_id=? AND status='registered' LIMIT 1"
    );
    $registrationLookup->execute([$httpEventId]);
    $registrationId = (int)$registrationLookup->fetchColumn();
    $remove = web_request($base, '/admin/participants.php?event_id=' . $httpEventId, $adminCookie, [
        'csrf' => $participantsToken, 'event_id' => $httpEventId, 'reg_id' => $registrationId,
    ]);
    $activeCount->execute([$httpEventId]);
    web_check($remove['status'] === 302 && (int)$activeCount->fetchColumn() === 1,
        'organizer can remove a participant without deleting history');

    $editPage = web_request($base, '/admin/event_form.php?id=' . $httpEventId, $adminCookie);
    $editToken = web_csrf($editPage['body']);
    $reduced['csrf'] = $editToken;
    $allowedReduction = web_request($base, '/admin/event_form.php?id=' . $httpEventId, $adminCookie, $reduced);
    $capacityQuery->execute([$httpEventId]);
    web_check($allowedReduction['status'] === 302 && (int)$capacityQuery->fetchColumn() === 1,
        'organizer can reduce capacity to current active registration count');

    $csv = web_request($base, '/admin/participants.php?event_id=' . $httpEventId . '&export=csv', $adminCookie);
    web_check($csv['status'] === 200 && str_contains($csv['headers'], 'text/csv')
        && str_contains($csv['body'], 'Enrollment No'),
        'organizer can export participant CSV');

    $dashboard = web_request($base, '/admin/index.php', $adminCookie);
    $deleteToken = web_csrf($dashboard['body']);
    $deleteNoCsrf = web_request($base, '/admin/event_delete.php', $adminCookie, ['id' => $httpEventId]);
    $eventLookup->execute([$eventTitle]);
    web_check($deleteNoCsrf['status'] === 419 && (int)$eventLookup->fetchColumn() > 0,
        'admin destructive action rejects missing CSRF');
    $delete = web_request($base, '/admin/event_delete.php', $adminCookie, [
        'csrf' => $deleteToken, 'id' => $httpEventId,
    ]);
    $eventLookup->execute([$eventTitle]);
    $scheduleCount->execute([$httpEventId]);
    web_check($delete['status'] === 302 && (int)$eventLookup->fetchColumn() === 0
        && (int)$scheduleCount->fetchColumn() === 0,
        'organizer can delete an event and dependent records cascade');

    $uploadSource = file_get_contents($project . '/register.php')
        . file_get_contents($project . '/admin/event_form.php');
    web_check(!str_contains((string)$uploadSource, 'move_uploaded_file')
        && !str_contains((string)$uploadSource, '$_FILES'),
        'review confirms the application has no file-upload handler');

    echo 'TOTAL HTTP PASS=' . $passed . PHP_EOL;
} catch (Throwable $error) {
    fwrite(STDERR, 'FAIL HTTP stage=' . $stage . ' type=' . get_class($error) . ' message=' . $error->getMessage() . PHP_EOL);
    $failure = true;
} finally {
    if (is_resource($serverProcess)) {
        proc_terminate($serverProcess);
        proc_close($serverProcess);
    }
}

if (!empty($failure)) {
    exit(1);
}

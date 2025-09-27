<?php
declare(strict_types=1);

$httpsRequest = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') || (isset($_SERVER['SERVER_PORT']) && $_SERVER['SERVER_PORT'] === '443');
$cookieParams = [
    'lifetime' => 0,
    'path' => '/',
    'domain' => '',
    'secure' => $httpsRequest,
    'httponly' => true,
    'samesite' => 'Strict',
];

if (PHP_VERSION_ID >= 70300) {
    session_set_cookie_params($cookieParams);
} else {
    session_set_cookie_params(
        $cookieParams['lifetime'],
        $cookieParams['path'] . '; samesite=Strict',
        $cookieParams['domain'],
        $cookieParams['secure'],
        $cookieParams['httponly']
    );
}

ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure', $cookieParams['secure'] ? '1' : '0');
ini_set('session.cookie_samesite', 'Strict');

session_start();

define('APP_ROOT', __DIR__);
define('CONFIG_FILE', APP_ROOT . '/config.php');
define('SETUP_LOCK_FILE', APP_ROOT . '/.setup_lock');
define('LOG_DIRECTORY', APP_ROOT . '/logs');
define('LOG_FILE', LOG_DIRECTORY . '/app.log');
define('MAX_UPLOAD_FILES', 10);
define('MAX_UPLOAD_BYTES', 10 * 1024 * 1024); // 10 MB per file
define('ALLOWED_UPLOAD_EXTENSIONS', [
    'txt', 'pdf', 'png', 'jpg', 'jpeg', 'gif', 'csv', 'zip', 'tar', 'gz'
]);

header('X-Frame-Options: DENY');
header('X-Content-Type-Options: nosniff');
header('Referrer-Policy: same-origin');
header('X-Robots-Tag: noindex, nofollow');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com https://cdnjs.cloudflare.com; font-src 'self' https://fonts.gstatic.com https://cdnjs.cloudflare.com; img-src 'self' data:; connect-src 'self';");

if ($cookieParams['secure']) {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

if (!is_dir(LOG_DIRECTORY)) {
    @mkdir(LOG_DIRECTORY, 0750, true);
}

if (is_dir(LOG_DIRECTORY)) {
    @touch(LOG_FILE);
    @chmod(LOG_FILE, 0640);
}

function audit_log(string $event, array $context = []): void
{
    $payload = [
        'timestamp' => date('c'),
        'event' => $event,
        'ip' => $_SERVER['REMOTE_ADDR'] ?? 'cli',
        'context' => $context,
    ];

    $line = json_encode($payload, JSON_UNESCAPED_SLASHES) . PHP_EOL;
    @file_put_contents(LOG_FILE, $line, FILE_APPEND | LOCK_EX);
}

function safe_realpath(string $base, string $path): ?string
{
    if ($path === '' || $path === '/') {
        return $base;
    }

    $candidate = realpath($base . '/' . $path);
    if ($candidate === false) {
        audit_log('path_resolution_failed', ['requestedPath' => $path]);
        return null;
    }

    if (strpos($candidate, $base) !== 0) {
        audit_log('path_traversal_blocked', ['requestedPath' => $path]);
        return null;
    }

    return $candidate;
}

function issue_csrf_token(string $action): string
{
    if (!isset($_SESSION['csrf_tokens']) || !is_array($_SESSION['csrf_tokens'])) {
        $_SESSION['csrf_tokens'] = [];
    }

    $token = bin2hex(random_bytes(32));
    $_SESSION['csrf_tokens'][$action] = [
        'value' => $token,
        'issued' => time(),
    ];

    return $token;
}

function get_csrf_token(string $action): string
{
    if (isset($_SESSION['csrf_tokens'][$action])) {
        $data = $_SESSION['csrf_tokens'][$action];
        if (is_array($data) && isset($data['value'], $data['issued']) && (time() - $data['issued']) < 1800) {
            return $data['value'];
        }
    }

    return issue_csrf_token($action);
}

function validate_csrf_token(string $action, ?string $token): bool
{
    if (!isset($_SESSION['csrf_tokens'][$action])) {
        return false;
    }

    $data = $_SESSION['csrf_tokens'][$action];
    if (!is_array($data) || !isset($data['value'], $data['issued'])) {
        return false;
    }

    if ((time() - $data['issued']) >= 1800) {
        unset($_SESSION['csrf_tokens'][$action]);
        return false;
    }

    $isValid = hash_equals($data['value'], (string)$token);

    if ($isValid) {
        unset($_SESSION['csrf_tokens'][$action]);
    }

    return $isValid;
}

function normalize_upload_filename(string $filename): string
{
    $basename = basename($filename);
    // Remove control chars and spaces
    $sanitized = preg_replace('/[^A-Za-z0-9._-]/', '_', $basename);
    return $sanitized ?? 'upload';
}

// Handle file downloads
if (isset($_GET['download']) && isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true) {
    $downloadTarget = safe_realpath(APP_ROOT, $_GET['download']);

    if ($downloadTarget !== null && is_file($downloadTarget)) {
        if ($downloadTarget === CONFIG_FILE || $downloadTarget === SETUP_LOCK_FILE || strpos($downloadTarget, LOG_DIRECTORY) === 0 || $downloadTarget === __FILE__) {
            audit_log('file_download_denied_protected', ['path' => $_GET['download'] ?? '']);
            header('HTTP/1.0 403 Forbidden');
            exit('File not available for download.');
        }

        $filename = basename($downloadTarget);
        clearstatcache(true, $downloadTarget);

        if (!is_readable($downloadTarget)) {
            audit_log('file_download_not_readable', ['file' => $filename, 'path' => $downloadTarget]);
            header('HTTP/1.0 403 Forbidden');
            exit('File not readable.');
        }

        $filesize = @filesize($downloadTarget);
        $knownSize = $filesize !== false;

        $logContext = ['file' => $filename];
        if ($knownSize) {
            $logContext['bytes'] = $filesize;
        } else {
            audit_log('file_download_filesize_unknown', $logContext);
        }

        audit_log('file_download', $logContext);

        // Prepare response headers
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('Accept-Ranges: none');
        if ($knownSize) {
            header('Content-Length: ' . (string)$filesize);
        }

        @set_time_limit(0);
        if (function_exists('apache_setenv')) {
            @apache_setenv('no-gzip', '1');
        }
        if (function_exists('ini_set')) {
            @ini_set('zlib.output_compression', '0');
        }
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        $handle = @fopen($downloadTarget, 'rb');
        if ($handle !== false) {
            while (!feof($handle)) {
                $buffer = fread($handle, 65536);
                if ($buffer === false) {
                    $lastError = error_get_last();
                    audit_log('file_download_stream_error', ['file' => $filename, 'path' => $downloadTarget, 'error' => $lastError['message'] ?? 'unknown']);
                    break;
                }
                echo $buffer;
                if (function_exists('flush')) {
                    flush();
                }
            }
            fclose($handle);
            exit();
        }

        $lastError = error_get_last();
        audit_log('file_download_failed', ['file' => $filename, 'path' => $downloadTarget, 'reason' => 'fopen_failed', 'error' => $lastError['message'] ?? 'n/a']);
        header('HTTP/1.1 500 Internal Server Error');
        exit('Unable to read file.');
    }

    // File not found or access denied
    audit_log('file_download_denied', ['path' => $_GET['download'] ?? '']);
    header('HTTP/1.0 404 Not Found');
    exit('File not found or access denied.');
}

// --- CONFIGURATION ---

// Check if config file exists. If not, start setup or block reset depending on lock.
if (!file_exists(CONFIG_FILE)) {
    if (file_exists(SETUP_LOCK_FILE)) {
        audit_log('setup_blocked_missing_config', []);
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
            <meta charset="UTF-8">
            <title>Configuration Missing</title>
            <meta name="robots" content="noindex, nofollow">
            <meta name="viewport" content="width=device-width, initial-scale=1.0">
            <style>
                body { font-family: Arial, sans-serif; background: #1a1a2e; color: #e0e0e0; display: flex; align-items: center; justify-content: center; min-height: 100vh; margin: 0; }
                .container { max-width: 480px; padding: 30px; background: rgba(22, 33, 62, 0.8); border-radius: 12px; border: 1px solid rgba(233, 69, 96, 0.2); box-shadow: 0 8px 32px rgba(0,0,0,0.37); }
                h1 { color: #e94560; }
                a { color: #e94560; }
            </style>
        </head>
        <body>
            <div class="container">
                <h1>Configuration Missing</h1>
                <p>The application setup has already been completed, but the configuration file is missing. For security, automatic reconfiguration is disabled.</p>
                <p>Please restore <code>config.php</code> from backup or contact the system administrator.</p>
            </div>
        </body>
        </html>
        <?php
        exit();
    }

    if (isset($_POST['setup_password'], $_POST['confirm_password'])) {
        $password = (string)$_POST['setup_password'];
        $confirm_password = (string)$_POST['confirm_password'];

        if ($password === $confirm_password) {
            if (strlen($password) >= 8) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $config_content = "<?php\nreturn [\n    'password_hash' => '" . $password_hash . "',\n];\n";
                $writeResult = @file_put_contents(CONFIG_FILE, $config_content, LOCK_EX);
                if ($writeResult !== false) {
                    @chmod(CONFIG_FILE, 0640);
                    @file_put_contents(SETUP_LOCK_FILE, (string)time(), LOCK_EX);
                    audit_log('setup_completed', ['userAgent' => $_SERVER['HTTP_USER_AGENT'] ?? 'unknown']);
                    header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'));
                    exit();
                }

                $lastError = error_get_last();
                $writeContext = [
                    'configPath' => CONFIG_FILE,
                    'configExists' => file_exists(CONFIG_FILE),
                    'configPerms' => file_exists(CONFIG_FILE) ? substr(sprintf('%o', @fileperms(CONFIG_FILE)), -4) : null,
                    'dirWritable' => is_writable(APP_ROOT),
                    'dirPerms' => substr(sprintf('%o', @fileperms(APP_ROOT)), -4),
                    'diskFree' => @disk_free_space(APP_ROOT),
                    'lastError' => $lastError['message'] ?? null,
                ];
                $setup_error = 'Failed to write configuration file. Check directory permissions.';
                audit_log('setup_write_failed', $writeContext);
            } else {
                $setup_error = 'Password must be at least 8 characters long.';
                audit_log('setup_password_too_short', ['length' => strlen($password)]);
            }
        } else {
            $setup_error = 'Passwords do not match.';
            audit_log('setup_password_mismatch', []);
        }
    }

    // Display setup form
    ?>
    <!DOCTYPE html>
    <html lang="en">
    <head>
        <meta charset="UTF-8">
        <title>Setup Quantum File Explorer</title>
        <meta name="robots" content="noindex, nofollow">
        <meta name="viewport" content="width=device-width, initial-scale=1.0">
        <style>
            @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');
            :root {
                --background-color: #1a1a2e;
                --primary-color: #e94560;
                --text-color: #e0e0e0;
                --glass-bg: rgba(22, 33, 62, 0.6);
                --border-color: rgba(233, 69, 96, 0.2);
            }
            body {
                font-family: 'Inter', sans-serif;
                background-color: var(--background-color);
                color: var(--text-color);
                display: flex;
                align-items: center;
                justify-content: center;
                min-height: 100vh;
                margin: 0;
                padding: 20px;
                box-sizing: border-box;
            }
            .container {
                width: 100%;
                max-width: 450px;
                background: var(--glass-bg);
                padding: 30px;
                border-radius: 15px;
                border: 1px solid var(--border-color);
                backdrop-filter: blur(10px);
                box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
                text-align: center;
            }
            h1 { color: #fff; margin-bottom: 20px; }
            p { margin-bottom: 20px; }
            .setup-form input[type="password"] {
                width: 100%;
                padding: 12px;
                margin-bottom: 20px;
                border-radius: 8px;
                border: 1px solid var(--border-color);
                background: rgba(0,0,0,0.2);
                color: var(--text-color);
                box-sizing: border-box;
            }
            .setup-form input[type="submit"] {
                width: 100%;
                padding: 12px;
                border: none;
                border-radius: 8px;
                background: var(--primary-color);
                color: #fff;
                font-weight: 600;
                cursor: pointer;
                transition: background-color 0.3s;
            }
            .setup-form input[type="submit"]:hover { background: #d43d51; }
            .error-message { color: var(--primary-color); margin-bottom: 15px; }
        </style>
    </head>
    <body>
        <div class="container">
            <h1>Welcome to Quantum File Explorer</h1>
            <p>Please create a strong administrator password (minimum 8 characters).</p>
            <form method="post" class="setup-form">
                <?php if (isset($setup_error)): ?>
                    <p class="error-message"><?php echo htmlspecialchars($setup_error, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <input type="password" name="setup_password" placeholder="Enter New Password" required minlength="8">
                <input type="password" name="confirm_password" placeholder="Confirm Password" required minlength="8">
                <input type="submit" value="Create Password">
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Include the configuration file
if (file_exists(CONFIG_FILE)) {
    audit_log('config_pre_load', [
        'size' => @filesize(CONFIG_FILE),
        'readable' => is_readable(CONFIG_FILE),
        'mtime' => @filemtime(CONFIG_FILE),
        'lock_exists' => file_exists(SETUP_LOCK_FILE),
    ]);
}

$config = require CONFIG_FILE;
$password_hash = is_array($config) && isset($config['password_hash']) ? $config['password_hash'] : null;

if ($password_hash === null) {
    audit_log('config_invalid', [
        'configType' => gettype($config),
        'configKeys' => is_array($config) ? array_keys($config) : null,
        'fileSize' => @filesize(CONFIG_FILE),
    ]);
    header('HTTP/1.1 500 Internal Server Error');
    exit('Configuration is invalid.');
}

// Logout logic
if (isset($_GET['logout'])) {
    audit_log('user_logout', []);
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $params = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000, $params['path'], $params['domain'], $params['secure'], $params['httponly']);
    }
    session_destroy();
    header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'));
    exit();
}

// Brute-force protection state
if (!isset($_SESSION['auth_state']) || !is_array($_SESSION['auth_state'])) {
    $_SESSION['auth_state'] = [
        'attempts' => 0,
        'last_attempt' => 0,
        'lock_until' => 0,
    ];
}

$authState =& $_SESSION['auth_state'];

// Check if login form has been submitted
if (isset($_POST['password'])) {
    $csrfValid = validate_csrf_token('login', $_POST['csrf_token'] ?? null);
    if (!$csrfValid) {
        $login_error = 'Security token invalid. Please refresh and try again.';
        audit_log('login_csrf_failed', []);
    } else {
        $now = time();
        if ($authState['lock_until'] > $now) {
            $remaining = $authState['lock_until'] - $now;
            $minutes = ceil($remaining / 60);
            $login_error = 'Too many failed attempts. Try again in approximately ' . $minutes . ' minute(s).';
            audit_log('login_locked', ['remainingSeconds' => $remaining]);
        } else {
            if (password_verify((string)$_POST['password'], $password_hash)) {
                $_SESSION['loggedin'] = true;
                $authState = [
                    'attempts' => 0,
                    'last_attempt' => $now,
                    'lock_until' => 0,
                ];
                session_regenerate_id(true); // Prevent session fixation
                audit_log('login_success', []);
            } else {
                $authState['attempts']++;
                $authState['last_attempt'] = $now;
                $login_error = 'Invalid password!';
                audit_log('login_failed', ['attempts' => $authState['attempts']]);

                if ($authState['attempts'] >= 5) {
                    $authState['lock_until'] = $now + 900; // 15 minutes cooldown
                    audit_log('login_lock_applied', ['lockUntil' => $authState['lock_until']]);
                }
            }
        }
    }
}

$loginCsrfToken = get_csrf_token('login');
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>Quantum File Explorer</title>
    <meta name="robots" content="noindex, nofollow">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/5.15.4/css/all.min.css">
    <style>
        @import url('https://fonts.googleapis.com/css2?family=Inter:wght@400;600;700&display=swap');

        :root {
            --background-color: #1a1a2e;
            --primary-color: #e94560;
            --secondary-color: #16213e;
            --text-color: #e0e0e0;
            --glass-bg: rgba(22, 33, 62, 0.6);
            --border-color: rgba(233, 69, 96, 0.2);
        }

        body {
            font-family: 'Inter', sans-serif;
            background-color: var(--background-color);
            background-image: 
                radial-gradient(circle at 15% 50%, rgba(233, 69, 96, 0.15), transparent 30%),
                radial-gradient(circle at 85% 30%, rgba(52, 152, 219, 0.15), transparent 30%);
            color: var(--text-color);
            margin: 0;
            padding: 20px;
            min-height: 100vh;
            box-sizing: border-box;
            display: flex;
            align-items: center;
            justify-content: center;
        }

        .container {
            width: 100%;
            max-width: 1100px;
            margin: 20px auto;
            background: var(--glass-bg);
            padding: 30px;
            border-radius: 15px;
            border: 1px solid var(--border-color);
            backdrop-filter: blur(10px);
            -webkit-backdrop-filter: blur(10px);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.37);
        }

        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            text-align: center;
            margin-bottom: 30px;
        }

        .header h1 {
            color: #fff;
            font-weight: 700;
            font-size: 2.5em;
            letter-spacing: 1px;
            margin: 0;
            flex-grow: 1;
        }
        
        .logout-btn {
            background: var(--primary-color);
            color: #fff;
            padding: 10px 15px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 600;
            transition: background-color 0.3s;
        }
        .logout-btn:hover {
            background: #d43d51;
        }

        .action-grid {
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(280px, 1fr));
            gap: 20px;
            margin-bottom: 30px;
        }

        .action-card {
            background: rgba(0, 0, 0, 0.25);
            border: 1px solid var(--border-color);
            border-radius: 12px;
            padding: 20px;
            display: flex;
            flex-direction: column;
            gap: 16px;
            box-shadow: inset 0 0 0 1px rgba(255, 255, 255, 0.02);
        }

        .action-card h2 {
            margin: 0;
            font-size: 1.35em;
            font-weight: 600;
            color: #fff;
        }

        .card-header {
            display: flex;
            align-items: center;
            gap: 16px;
        }

        .card-icon {
            background: rgba(233, 69, 96, 0.15);
            color: var(--primary-color);
            width: 44px;
            height: 44px;
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 1.2em;
        }

        .card-subtitle {
            margin: 4px 0 0;
            font-size: 0.9em;
            color: rgba(255, 255, 255, 0.65);
        }

        .upload-form {
            display: grid;
            grid-template-columns: minmax(0, 1fr) auto;
            gap: 12px;
            align-items: stretch;
        }

        .upload-form input[type="file"] {
            width: 100%;
            padding: 12px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: rgba(0, 0, 0, 0.35);
            color: var(--text-color);
            box-sizing: border-box;
        }

        .upload-form input[type="file"]::file-selector-button,
        .upload-form input[type="file"]::-webkit-file-upload-button {
            background: var(--secondary-color);
            color: #fff;
            border: none;
            border-radius: 6px;
            padding: 10px 16px;
            margin-right: 12px;
            cursor: pointer;
            font-weight: 600;
            transition: background-color 0.3s ease;
        }

        .upload-form input[type="file"]::file-selector-button:hover,
        .upload-form input[type="file"]::-webkit-file-upload-button:hover {
            background: rgba(233, 69, 96, 0.35);
        }

        .form-actions {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .btn {
            display: inline-flex;
            align-items: center;
            justify-content: center;
            border: none;
            border-radius: 8px;
            padding: 12px 18px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.2s ease, box-shadow 0.2s ease, background-color 0.3s ease;
            text-align: center;
            text-decoration: none;
        }

        .btn-primary {
            background: var(--primary-color);
            color: #fff;
            box-shadow: 0 8px 20px rgba(233, 69, 96, 0.25);
        }

        .btn-primary:hover {
            background: #d43d51;
            box-shadow: 0 12px 24px rgba(233, 69, 96, 0.28);
            transform: translateY(-1px);
        }

        .btn-danger {
            background: rgba(233, 69, 96, 0.18);
            color: #fff;
            border: 1px solid rgba(233, 69, 96, 0.4);
        }

        .btn-danger:hover {
            background: rgba(233, 69, 96, 0.3);
            transform: translateY(-1px);
        }

        .alert-stack {
            display: flex;
            flex-direction: column;
            gap: 8px;
        }

        .alert {
            border-radius: 8px;
            padding: 12px 14px;
            background: rgba(0, 0, 0, 0.35);
            border: 1px solid transparent;
            font-size: 0.95em;
        }

        .alert-error {
            color: var(--primary-color);
            border-color: rgba(233, 69, 96, 0.35);
            background: rgba(233, 69, 96, 0.08);
        }

        .alert-success {
            color: #27ae60;
            border-color: rgba(39, 174, 96, 0.35);
            background: rgba(39, 174, 96, 0.08);
        }

        .breadcrumbs {
            background-color: rgba(0,0,0,0.2);
            padding: 10px 15px;
            border-radius: 8px;
            margin-bottom: 25px;
            font-size: 0.9em;
            word-wrap: break-word;
        }

        .breadcrumbs a {
            color: var(--primary-color);
            text-decoration: none;
        }

        #searchInput {
            width: 100%;
            padding: 12px 15px;
            margin-bottom: 25px;
            background-color: rgba(0,0,0,0.2);
            border: 1px solid var(--border-color);
            border-radius: 8px;
            box-sizing: border-box;
            color: var(--text-color);
            font-size: 1em;
        }

        #searchInput::placeholder {
            color: rgba(255,255,255,0.5);
        }

        table {
            width: 100%;
            border-collapse: collapse;
        }

        th, td {
            padding: 15px;
            text-align: left;
            border-bottom: 1px solid var(--border-color);
        }

        th {
            background-color: rgba(233, 69, 96, 0.1);
            color: var(--primary-color);
            text-transform: uppercase;
            letter-spacing: 0.08em;
            font-weight: 600;
            cursor: pointer;
        }

        tr {
            transition: background-color 0.3s ease, box-shadow 0.3s ease;
        }

        tr:hover {
            background-color: rgba(233, 69, 96, 0.05);
            box-shadow: inset 3px 0 0 var(--primary-color);
        }
        
        tr:last-child td {
            border-bottom: none;
        }

        a {
            text-decoration: none;
            color: var(--text-color);
            font-weight: 600;
        }

        a:hover {
            color: var(--primary-color);
        }

        .icon {
            margin-right: 15px;
            width: 20px;
            text-align: center;
        }
        
        .fa-folder { color: #f1c40f; }
        .fa-file-alt { color: #bdc3c7; }

        /* Login Form Styles */
        .login-container {
            text-align: center;
            max-width: 400px;
        }
        .login-container h1 {
            margin-bottom: 20px;
        }
        .login-form input[type="password"] {
            width: 100%;
            padding: 12px;
            margin-bottom: 20px;
            border-radius: 8px;
            border: 1px solid var(--border-color);
            background: rgba(0,0,0,0.2);
            color: var(--text-color);
            box-sizing: border-box;
        }
        .login-form input[type="submit"] {
            width: 100%;
            padding: 12px;
            border: none;
            border-radius: 8px;
            background: var(--primary-color);
            color: #fff;
            font-weight: 600;
            cursor: pointer;
            transition: background-color 0.3s;
        }
        .login-form input[type="submit"]:hover {
            background: #d43d51;
        }
        .error-message {
            color: var(--primary-color);
            margin-bottom: 15px;
        }

        @media (max-width: 768px) {
            .container { padding: 20px; }
            th, td { padding: 12px 8px; }
            .header h1 { font-size: 2em; }
        }

        @media (max-width: 640px) {
            .upload-form {
                grid-template-columns: 1fr;
            }

            .upload-form input[type="file"]::file-selector-button,
            .upload-form input[type="file"]::-webkit-file-upload-button {
                margin-right: 0;
                margin-bottom: 10px;
                width: 100%;
            }

            .btn {
                width: 100%;
            }
        }
    </style>
</head>
<body>

<?php if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] === true): ?>

<?php
    // Sanitize and manage the current path first
    $root_path = APP_ROOT;
    $current_path = $root_path;
    $relative_path = '';

    if (isset($_GET['path']) && $_GET['path'] !== '') {
        $resolved = safe_realpath($root_path, $_GET['path']);
        if ($resolved !== null && is_dir($resolved)) {
            if (strpos($resolved, LOG_DIRECTORY) === 0) {
                audit_log('directory_access_denied_logs', ['requested' => $_GET['path']]);
            } else {
                $current_path = $resolved;
            }
        } else {
            audit_log('directory_access_denied', ['requested' => $_GET['path']]);
        }
    }

    if (!is_dir($current_path)) {
        audit_log('directory_resolution_fallback', ['path' => $current_path]);
        $current_path = $root_path;
    }

    $relative_path = ltrim(str_replace($root_path, '', $current_path), '/');

    // Handle file upload (multiple files)
    $upload_message = '';
    if (isset($_FILES['upload_files'])) {
        if (!validate_csrf_token('upload', $_POST['csrf_token'] ?? null)) {
            $upload_message = '<div class="alert alert-error">Security token invalid. Please retry the upload.</div>';
            audit_log('upload_csrf_failed', ['path' => $relative_path]);
        } else {
            $messages = [];
            if (!is_writable($current_path)) {
                $messages[] = '<div class="alert alert-error">Upload directory is not writable: ' . htmlspecialchars($current_path, ENT_QUOTES, 'UTF-8') . '</div>';
                audit_log('upload_directory_not_writable', ['path' => $current_path]);
            } else {
                $names = $_FILES['upload_files']['name'] ?? [];
                $tmp_names = $_FILES['upload_files']['tmp_name'] ?? [];
                $errors = $_FILES['upload_files']['error'] ?? [];
                $sizes = $_FILES['upload_files']['size'] ?? [];

                $file_indices = [];
                foreach ((array)$names as $idx => $n) {
                    if ($n !== null && $n !== '') {
                        $file_indices[] = $idx;
                    }
                }

                $total = count($file_indices);
                if ($total > 0) {
                    if ($total > MAX_UPLOAD_FILES) {
                        $messages[] = '<div class="alert alert-error">You selected ' . (int)$total . ' files. Uploading the first ' . MAX_UPLOAD_FILES . ' only.</div>';
                    }

                    $processed = 0;
                    $finfo = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : null;

                    foreach ($file_indices as $i) {
                        if ($processed >= MAX_UPLOAD_FILES) {
                            break;
                        }

                        $originalName = (string)$names[$i];
                        $sanitizedName = normalize_upload_filename($originalName);
                        $extension = strtolower(pathinfo($sanitizedName, PATHINFO_EXTENSION));
                        $err = isset($errors[$i]) ? $errors[$i] : UPLOAD_ERR_NO_FILE;
                        $tmp = isset($tmp_names[$i]) ? $tmp_names[$i] : '';
                        $size = isset($sizes[$i]) ? (int)$sizes[$i] : 0;

                        if ($err === UPLOAD_ERR_NO_FILE || $sanitizedName === '') {
                            continue;
                        }

                        if ($err !== UPLOAD_ERR_OK) {
                            $msg = 'Upload error (' . (int)$err . ') for: ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8');
                            if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $msg = 'File too large: ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8'); }
                            elseif ($err === UPLOAD_ERR_PARTIAL) { $msg = 'Partial upload: ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8'); }
                            elseif ($err === UPLOAD_ERR_NO_TMP_DIR) { $msg = 'Missing temp folder for: ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8'); }
                            elseif ($err === UPLOAD_ERR_CANT_WRITE) { $msg = 'Failed to write file: ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8'); }
                            elseif ($err === UPLOAD_ERR_EXTENSION) { $msg = 'Upload blocked by PHP extension for: ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8'); }
                            $messages[] = '<div class="alert alert-error">' . $msg . '</div>';
                            audit_log('upload_error_code', ['file' => $originalName, 'error' => $err]);
                            $processed++;
                            continue;
                        }

                        if ($extension === '' || !in_array($extension, ALLOWED_UPLOAD_EXTENSIONS, true)) {
                            $messages[] = '<div class="alert alert-error">Blocked by policy (extension): ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') . '</div>';
                            audit_log('upload_extension_blocked', ['file' => $originalName, 'extension' => $extension]);
                            $processed++;
                            continue;
                        }

                        if ($size > MAX_UPLOAD_BYTES) {
                            $messages[] = '<div class="alert alert-error">File exceeds ' . number_format(MAX_UPLOAD_BYTES / (1024 * 1024), 2) . ' MB limit: ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') . '</div>';
                            audit_log('upload_size_blocked', ['file' => $originalName, 'size' => $size]);
                            $processed++;
                            continue;
                        }

                        if (!is_uploaded_file($tmp)) {
                            $messages[] = '<div class="alert alert-error">Upload validation failed for: ' . htmlspecialchars($originalName, ENT_QUOTES, 'UTF-8') . '</div>';
                            audit_log('upload_origin_invalid', ['file' => $originalName]);
                            $processed++;
                            continue;
                        }

                        $mime = null;
                        if ($finfo) {
                            $mime = finfo_file($finfo, $tmp) ?: null;
                        }

                        $target_file = $current_path . '/' . $sanitizedName;
                        if (file_exists($target_file)) {
                            $messages[] = '<div class="alert alert-error">File already exists: ' . htmlspecialchars($sanitizedName, ENT_QUOTES, 'UTF-8') . '</div>';
                            audit_log('upload_exists', ['file' => $sanitizedName]);
                        } elseif (move_uploaded_file($tmp, $target_file)) {
                            @chmod($target_file, 0640);
                            $messages[] = '<div class="alert alert-success">Uploaded: ' . htmlspecialchars($sanitizedName, ENT_QUOTES, 'UTF-8') . '</div>';
                            audit_log('upload_success', ['file' => $sanitizedName, 'size' => $size, 'mime' => $mime]);
                        } else {
                            $messages[] = '<div class="alert alert-error">Failed to upload: ' . htmlspecialchars($sanitizedName, ENT_QUOTES, 'UTF-8') . '</div>';
                            audit_log('upload_move_failed', ['file' => $sanitizedName]);
                        }
                        $processed++;
                    }

                    if ($finfo) {
                        finfo_close($finfo);
                    }
                }
            }

            if (!empty($messages)) {
                $upload_message = implode("\n", $messages);
            }
        }
    }

    // Handle delete all files
    $delete_message = '';
    if (isset($_POST['delete_all_files'])) {
        if (!validate_csrf_token('delete_all', $_POST['csrf_token'] ?? null)) {
            $delete_message = '<div class="alert alert-error">Security token invalid. Delete action blocked.</div>';
            audit_log('delete_csrf_failed', ['path' => $relative_path]);
        } else {
            $deleted_count = 0;
            $failed_count = 0;
            $files = scandir($current_path);
            foreach ($files as $file) {
                if ($file === '.' || $file === '..' || $file === basename(__FILE__) || $file === 'config.php' || $file === basename(SETUP_LOCK_FILE) || $file === basename(LOG_DIRECTORY)) {
                    continue;
                }

                $filePath = $current_path . '/' . $file;
                $realFilePath = realpath($filePath);
                if ($realFilePath === false || strpos($realFilePath, $current_path) !== 0 || !is_file($realFilePath)) {
                    continue;
                }

                if (@unlink($realFilePath)) {
                    $deleted_count++;
                } else {
                    $failed_count++;
                }
            }

            $deleteStatusClass = $failed_count > 0 ? 'alert alert-error' : 'alert alert-success';
            $delete_message = '<div class="' . $deleteStatusClass . '">Deleted ' . $deleted_count . ' file(s).' . ($failed_count > 0 ? ' Failed to delete ' . $failed_count . ' file(s).' : '') . '</div>';
            audit_log('delete_all_invoked', ['path' => $relative_path, 'deleted' => $deleted_count, 'failed' => $failed_count]);
        }
    }

    $uploadCsrfToken = get_csrf_token('upload');
    $deleteCsrfToken = get_csrf_token('delete_all');
?>

    <div class="container">
        <div class="header">
            <h1>Quantum File Explorer</h1>
            <a href="?logout=true" class="logout-btn">Logout</a>
        </div>

        <!-- File Actions -->
        <section class="action-grid" aria-label="File actions">
            <article class="action-card upload-card">
                <div class="card-header">
                    <span class="card-icon">
                        <i class="fas fa-cloud-upload-alt"></i>
                    </span>
                    <div>
                        <h2>Upload Files</h2>
                        <p class="card-subtitle">Select up to <?php echo MAX_UPLOAD_FILES; ?> files per upload.</p>
                    </div>
                </div>
                <form method="post" enctype="multipart/form-data" class="upload-form">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($uploadCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($_GET['path'])): ?>
                        <input type="hidden" name="current_path" value="<?php echo htmlspecialchars($_GET['path'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <input type="file" name="upload_files[]" multiple required>
                    <div class="form-actions">
                        <button type="submit" class="btn btn-primary">Upload Files (up to <?php echo MAX_UPLOAD_FILES; ?>)</button>
                    </div>
                </form>
                <?php if ($upload_message !== ''): ?>
                    <div class="alert-stack" role="status" aria-live="polite">
                        <?php echo $upload_message; ?>
                    </div>
                <?php endif; ?>
            </article>

            <article class="action-card delete-card">
                <div class="card-header">
                    <span class="card-icon">
                        <i class="fas fa-trash-alt"></i>
                    </span>
                    <div>
                        <h2>Delete All Files</h2>
                        <p class="card-subtitle">Remove every file in the current directory.</p>
                    </div>
                </div>
                <form method="post" class="delete-form" onsubmit="return confirm('Are you sure you want to delete all files in this directory?');">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($deleteCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
                    <?php if (isset($_GET['path'])): ?>
                        <input type="hidden" name="current_path" value="<?php echo htmlspecialchars($_GET['path'], ENT_QUOTES, 'UTF-8'); ?>">
                    <?php endif; ?>
                    <input type="hidden" name="delete_all_files" value="1">
                    <div class="form-actions">
                        <button type="submit" class="btn btn-danger">Delete All Files</button>
                    </div>
                </form>
                <?php if ($delete_message !== ''): ?>
                    <div class="alert-stack" role="status" aria-live="polite">
                        <?php echo $delete_message; ?>
                    </div>
                <?php endif; ?>
            </article>
        </section>

        <div class="breadcrumbs">
            <i class="fas fa-folder-open icon"></i>
            <a href="?path=">root</a> /
            <?php
            $path_parts = explode('/', $relative_path);
            $current_breadcrumb_path = '';
            foreach ($path_parts as $part) {
                if (!empty($part)) {
                    $current_breadcrumb_path .= $part . '/';
                    echo '<a href="?path=' . urlencode($current_breadcrumb_path) . '">' . htmlspecialchars($part) . '</a> / ';
                }
            }
            ?>
        </div>

        <input type="text" id="searchInput" onkeyup="searchTable()" placeholder="Search current directory...">
        
        <table id="fileTable">
            <thead>
                <tr>
                    <th onclick="sortTable(0)">Name</th>
                    <th onclick="sortTable(1)">Type</th>
                    <th onclick="sortTable(2)">Size</th>
                    <th onclick="sortTable(3)">Last Modified</th>
                </tr>
            </thead>
            <tbody>
                <?php
                function formatSizeUnits($bytes) {
                    if ($bytes >= 1073741824) { $bytes = number_format($bytes / 1073741824, 2) . ' GB'; }
                    elseif ($bytes >= 1048576) { $bytes = number_format($bytes / 1048576, 2) . ' MB'; }
                    elseif ($bytes >= 1024) { $bytes = number_format($bytes / 1024, 2) . ' KB'; }
                    elseif ($bytes > 1) { $bytes = $bytes . ' bytes'; }
                    elseif ($bytes == 1) { $bytes = $bytes . ' byte'; }
                    else { $bytes = '0 bytes'; }
                    return $bytes;
                }

                $files = scandir($current_path);
                $exclude = array_unique([
                    '.',
                    '..',
                    basename(__FILE__),
                    'config.php',
                    basename(SETUP_LOCK_FILE),
                    basename(LOG_DIRECTORY),
                ]);

                foreach ($files as $file) {
                    if (in_array($file, $exclude, true)) {
                        continue;
                    }

                    $filePath = $current_path . '/' . $file;
                    $isDir = is_dir($filePath);
                    $icon = $isDir ? 'fas fa-folder' : 'fas fa-file-alt';
                    $size = $isDir ? -1 : @filesize($filePath);
                    $modified = @filemtime($filePath);
                    $modifiedSort = $modified !== false ? $modified : 0;
                    $modifiedDisplay = $modified !== false ? date('Y-m-d H:i:s', $modified) : 'Unknown';

                    $link_path = ltrim($relative_path . '/' . $file, '/');
                    $link = $isDir ? '?path=' . urlencode($link_path) : '?download=' . urlencode($link_path);

                    echo "<tr>";
                    echo "<td><i class='icon " . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . "'></i><a href=\"" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "\">" . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . "</a></td>";
                    echo "<td>" . ($isDir ? "Directory" : "File") . "</td>";
                    $sizeSortValue = $isDir ? -1 : (int)$size;
                    echo "<td data-sort='" . htmlspecialchars((string)$sizeSortValue, ENT_QUOTES, 'UTF-8') . "'>" . ($isDir ? "-" : formatSizeUnits($sizeSortValue)) . "</td>";
                    echo "<td data-sort='" . htmlspecialchars((string)$modifiedSort, ENT_QUOTES, 'UTF-8') . "'>" . htmlspecialchars($modifiedDisplay, ENT_QUOTES, 'UTF-8') . "</td>";
                    echo "</tr>";
                }
                ?>
            </tbody>
        </table>
    </div>

<?php else: ?>

    <div class="container login-container">
        <h1>Authentication Required</h1>
        <form method="post" class="login-form">
            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($loginCsrfToken, ENT_QUOTES, 'UTF-8'); ?>">
            <?php if (isset($login_error)): ?>
                <p class="error-message"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <input type="password" name="password" placeholder="Enter Password" required minlength="8">
            <input type="submit" value="Login" <?php if (isset($authState['lock_until']) && $authState['lock_until'] > time()) echo 'disabled'; ?>>
        </form>
    </div>

<?php endif; ?>

    <script>
    function searchTable() {
        var input, filter, table, tr, td, i, txtValue;
        input = document.getElementById("searchInput");
        filter = input.value.toUpperCase();
        table = document.getElementById("fileTable");
        tr = table.getElementsByTagName("tr");
        for (i = 1; i < tr.length; i++) {
            td = tr[i].getElementsByTagName("td")[0];
            if (td) {
                txtValue = td.textContent || td.innerText;
                if (txtValue.toUpperCase().indexOf(filter) > -1) {
                    tr[i].style.display = "";
                } else {
                    tr[i].style.display = "none";
                }
            }
        }
    }

    function sortTable(n) {
        var table, rows, switching, i, x, y, shouldSwitch, dir, switchcount = 0;
        table = document.getElementById("fileTable");
        switching = true;
        dir = "asc"; 
        while (switching) {
            switching = false;
            rows = table.rows;
            for (i = 1; i < (rows.length - 1); i++) {
                shouldSwitch = false;
                x = rows[i].getElementsByTagName("TD")[n];
                y = rows[i + 1].getElementsByTagName("TD")[n];
                
                var xContent = x.dataset.sort ? x.dataset.sort : x.innerHTML.toLowerCase();
                var yContent = y.dataset.sort ? y.dataset.sort : y.innerHTML.toLowerCase();

                if (n === 2 || n === 3) { // Size or Date column
                    xContent = parseFloat(xContent);
                    yContent = parseFloat(yContent);
                }

                if (dir == "asc") {
                    if (xContent > yContent) { shouldSwitch = true; break; }
                } else if (dir == "desc") {
                    if (xContent < yContent) { shouldSwitch = true; break; }
                }
            }
            if (shouldSwitch) {
                rows[i].parentNode.insertBefore(rows[i + 1], rows[i]);
                switching = true;
                switchcount ++;
            } else {
                if (switchcount == 0 && dir == "asc") {
                    dir = "desc";
                    switching = true;
                }
            }
        }
    }
    </script>
</body>
</html>

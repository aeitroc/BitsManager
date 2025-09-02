<?php
session_start();

// Handle file downloads
if (isset($_GET['download']) && isset($_SESSION['loggedin']) && $_SESSION['loggedin'] == true) {
    $root_path = realpath(getcwd());
    $download_file = realpath($root_path . '/' . $_GET['download']);
    
    // Security check: Ensure the file is within the root directory
    if ($download_file && strpos($download_file, $root_path) === 0 && is_file($download_file)) {
        $filename = basename($download_file);
        $filesize = filesize($download_file);
        
        // Set headers to force download
        header('Content-Type: application/octet-stream');
        header('Content-Disposition: attachment; filename="' . $filename . '"');
        header('Content-Length: ' . $filesize);
        header('Cache-Control: no-cache, must-revalidate');
        header('Pragma: no-cache');
        
        // Output file content
        readfile($download_file);
        exit();
    } else {
        // File not found or access denied
        header('HTTP/1.0 404 Not Found');
        exit('File not found or access denied.');
    }
}

// --- CONFIGURATION ---
$config_file = 'config.php'; 

// Check if config file exists. If not, start setup.
if (!file_exists($config_file)) {
    if (isset($_POST['setup_password']) && isset($_POST['confirm_password'])) {
        $password = $_POST['setup_password'];
        $confirm_password = $_POST['confirm_password'];

        if ($password === $confirm_password) {
            if (strlen($password) >= 8) {
                $password_hash = password_hash($password, PASSWORD_DEFAULT);
                $config_content = "<?php\n\n\$password_hash = '" . $password_hash . "';\n";
                file_put_contents($config_file, $config_content);
                header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'));
                exit();
            } else {
                $setup_error = 'Password must be at least 8 characters long.';
            }
        } else {
            $setup_error = 'Passwords do not match.';
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
            <p>Please create a password to secure your file explorer.</p>
            <form method="post" class="setup-form">
                <?php if (isset($setup_error)): ?>
                    <p class="error-message"><?php echo htmlspecialchars($setup_error, ENT_QUOTES, 'UTF-8'); ?></p>
                <?php endif; ?>
                <input type="password" name="setup_password" placeholder="Enter New Password" required>
                <input type="password" name="confirm_password" placeholder="Confirm Password" required>
                <input type="submit" value="Create Password">
            </form>
        </div>
    </body>
    </html>
    <?php
    exit();
}

// Include the configuration file
require_once($config_file);

// Logout logic
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . htmlspecialchars($_SERVER['PHP_SELF'], ENT_QUOTES, 'UTF-8'));
    exit();
}

// Brute-force protection
if (!isset($_SESSION['login_attempts'])) {
    $_SESSION['login_attempts'] = 0;
}

// Check if login form has been submitted
if (isset($_POST['password'])) {
    if ($_SESSION['login_attempts'] < 5) {
        if (password_verify($_POST['password'], $password_hash)) {
            $_SESSION['loggedin'] = true;
            $_SESSION['login_attempts'] = 0; // Reset on success
            session_regenerate_id(true); // Prevent session fixation
        } else {
            $_SESSION['login_attempts']++;
            $login_error = 'Invalid password!';
        }
    } else {
        $login_error = 'Too many failed login attempts. Please try again later.';
    }
}
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
    </style>
</head>
<body>

<?php if (isset($_SESSION['loggedin']) && $_SESSION['loggedin'] == true): ?>

<?php
    // Sanitize and manage the current path first
    $root_path = realpath(getcwd());
    $current_path = isset($_GET['path']) ? realpath($root_path . '/' . $_GET['path']) : $root_path;

    // Security check: Ensure the path is within the root directory
    if (strpos($current_path, $root_path) !== 0) {
        $current_path = $root_path;
    }

    // Handle file upload (multiple files up to 10)
    $upload_message = '';
    if (isset($_FILES['upload_files'])) {
        $messages = [];
        if (!is_writable($current_path)) {
            $messages[] = '<div style="color: #e94560; margin-bottom: 15px;">Upload directory is not writable: ' . htmlspecialchars($current_path) . '</div>';
        } else {
            // Normalize the files array
            $names = $_FILES['upload_files']['name'];
            $tmp_names = $_FILES['upload_files']['tmp_name'];
            $errors = $_FILES['upload_files']['error'];

            // Filter out empty slots
            $file_indices = [];
            foreach ((array)$names as $idx => $n) {
                if ($n !== null && $n !== '') { $file_indices[] = $idx; }
            }

            $total = count($file_indices);
            if ($total === 0) {
                // No file selected – keep message empty
            } else {
                $limit = 10;
                if ($total > $limit) {
                    $messages[] = '<div style="color: #e94560; margin-bottom: 10px;">You selected ' . (int)$total . ' files. Uploading the first ' . $limit . ' only.</div>';
                }

                $processed = 0;
                foreach ($file_indices as $i) {
                    if ($processed >= $limit) { break; }

                    $name = basename($names[$i]);
                    $err = isset($errors[$i]) ? $errors[$i] : UPLOAD_ERR_NO_FILE;
                    $tmp = isset($tmp_names[$i]) ? $tmp_names[$i] : '';

                    if ($err === UPLOAD_ERR_NO_FILE || $name === '') {
                        continue;
                    }

                    // Map common upload errors to readable messages
                    if ($err !== UPLOAD_ERR_OK) {
                        $msg = 'Upload error (' . (int)$err . ') for: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
                        if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) { $msg = 'File too large: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); }
                        elseif ($err === UPLOAD_ERR_PARTIAL) { $msg = 'Partial upload: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); }
                        elseif ($err === UPLOAD_ERR_NO_TMP_DIR) { $msg = 'Missing temp folder for: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); }
                        elseif ($err === UPLOAD_ERR_CANT_WRITE) { $msg = 'Failed to write file: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); }
                        elseif ($err === UPLOAD_ERR_EXTENSION) { $msg = 'Upload blocked by extension for: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8'); }
                        $messages[] = '<div style="color: #e94560; margin-bottom: 6px;">' . $msg . '</div>';
                        $processed++;
                        continue;
                    }

                    $target_file = $current_path . '/' . $name;
                    if (file_exists($target_file)) {
                        $messages[] = '<div style="color: #e94560; margin-bottom: 6px;">File already exists: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
                    } elseif (move_uploaded_file($tmp, $target_file)) {
                        $messages[] = '<div style="color: #27ae60; margin-bottom: 6px;">Uploaded: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
                    } else {
                        $messages[] = '<div style="color: #e94560; margin-bottom: 6px;">Failed to upload: ' . htmlspecialchars($name, ENT_QUOTES, 'UTF-8') . '</div>';
                    }
                    $processed++;
                }
            }
        }

        // Combine messages for display
        if (!empty($messages)) {
            $upload_message = implode("\n", $messages);
        }
    }

    // Handle delete all files
    $delete_message = '';
    if (isset($_POST['delete_all_files'])) {
        $deleted_count = 0;
        $files = scandir($current_path);
        foreach ($files as $file) {
            $filePath = $current_path . '/' . $file;
            if ($file !== "." && $file !== ".." && $file !== basename(__FILE__) && $file !== 'config.php' && is_file($filePath)) {
                if (unlink($filePath)) {
                    $deleted_count++;
                }
            }
        }
        $delete_message = '<div style="color: #e94560; margin-bottom: 15px;">Deleted ' . $deleted_count . ' files.</div>';
    }

    $relative_path = ltrim(str_replace($root_path, '', $current_path), '/');
?>

    <div class="container">
        <div class="header">
            <h1>Quantum File Explorer</h1>
            <a href="?logout=true" class="logout-btn">Logout</a>
        </div>

        <!-- File Upload Form -->
        <form method="post" enctype="multipart/form-data" style="margin-bottom: 20px;">
            <?php if (isset($_GET['path'])): ?>
                <input type="hidden" name="current_path" value="<?php echo htmlspecialchars($_GET['path']); ?>">
            <?php endif; ?>
            <input type="file" name="upload_files[]" multiple required>
            <input type="submit" value="Upload Files (up to 10)" style="background: var(--primary-color); color: #fff; border: none; border-radius: 8px; padding: 8px 16px; margin-left: 10px; cursor: pointer;">
        </form>

        <!-- Upload Message -->
        <?php echo $upload_message; ?>

        <!-- Delete Message -->
        <?php echo $delete_message; ?>

        <!-- Delete All Files Button -->
        <form method="post" onsubmit="return confirm('Are you sure you want to delete all files in this directory?');" style="margin-bottom: 20px;">
            <?php if (isset($_GET['path'])): ?>
                <input type="hidden" name="current_path" value="<?php echo htmlspecialchars($_GET['path']); ?>">
            <?php endif; ?>
            <input type="hidden" name="delete_all_files" value="1">
            <input type="submit" value="Delete All Files" style="background: #e94560; color: #fff; border: none; border-radius: 8px; padding: 8px 16px; cursor: pointer;">
        </form>

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

                foreach($files as $file) {
                    if ($file !== "." && $file !== ".." && $file !== basename(__FILE__) && $file !== 'config.php') {
                        $filePath = $current_path . '/' . $file;
                        $isDir = is_dir($filePath);
                        $icon = $isDir ? 'fas fa-folder' : 'fas fa-file-alt';
                        $size = $isDir ? -1 : filesize($filePath);
                        $modified = filemtime($filePath);
                        
                        $link_path = ltrim($relative_path . '/' . $file, '/');
                        $link = $isDir ? '?path=' . urlencode($link_path) : '?download=' . urlencode($link_path);

                        echo "<tr>";
                        echo "<td><i class='icon " . htmlspecialchars($icon, ENT_QUOTES, 'UTF-8') . "'></i><a href=\"" . htmlspecialchars($link, ENT_QUOTES, 'UTF-8') . "\">" . htmlspecialchars($file, ENT_QUOTES, 'UTF-8') . "</a></td>";
                        echo "<td>" . ($isDir ? "Directory" : "File") . "</td>";
                        echo "<td data-sort='" . htmlspecialchars($size, ENT_QUOTES, 'UTF-8') . "'>" . ($isDir ? "-" : formatSizeUnits($size)) . "</td>";
                        echo "<td data-sort='" . htmlspecialchars($modified, ENT_QUOTES, 'UTF-8') . "'>" . date("Y-m-d H:i:s", $modified) . "</td>";
                        echo "</tr>";
                    }
                }
                ?>
            </tbody>
        </table>
    </div>

<?php else: ?>

    <div class="container login-container">
        <h1>Authentication Required</h1>
        <form method="post" class="login-form">
            <?php if (isset($login_error)): ?>
                <p class="error-message"><?php echo htmlspecialchars($login_error, ENT_QUOTES, 'UTF-8'); ?></p>
            <?php endif; ?>
            <input type="password" name="password" placeholder="Enter Password" required>
            <input type="submit" value="Login" <?php if (isset($_SESSION['login_attempts']) && $_SESSION['login_attempts'] >= 5) echo 'disabled'; ?>>
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


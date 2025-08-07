<?php
/**
 * Secure PHP File Browser
 * A modern, secure file browser with Bootstrap 5 styling
 */

// Security: Define the allowed file root directory
define('FILE_ROOT', __DIR__ . '/files');

// Security: Ensure the files directory exists
if (!is_dir(FILE_ROOT)) {
    mkdir(FILE_ROOT, 0755, true);
}

/**
 * Validates and sanitizes the requested path
 * @param string $path The requested path
 * @return string|false The validated path or false if invalid
 */
function validatePath($path) {
    // Remove any null bytes and normalize path separators
    $path = str_replace("\0", '', $path);
    $path = str_replace('\\', '/', $path);
    
    // Remove any leading slash to make it relative
    $path = ltrim($path, '/');
    
    // Construct the full path
    $fullPath = FILE_ROOT . '/' . $path;
    
    // Get the real path (resolves .. and . components)
    $realPath = realpath($fullPath);
    
    // Security check: ensure the path is within FILE_ROOT
    if ($realPath === false || strpos($realPath, realpath(FILE_ROOT)) !== 0) {
        return false;
    }
    
    return $realPath;
}

/**
 * Formats file size in human readable format
 * @param int $size File size in bytes
 * @return string Formatted size
 */
function formatFileSize($size) {
    $units = array('B', 'KB', 'MB', 'GB', 'TB');
    $unit = 0;
    while ($size >= 1024 && $unit < count($units) - 1) {
        $size /= 1024;
        $unit++;
    }
    return round($size, 2) . ' ' . $units[$unit];
}

/**
 * Gets the appropriate Bootstrap icon class for a file
 * @param string $filename The filename
 * @param bool $isDir Whether it's a directory
 * @return string The Bootstrap icon class
 */
function getFileIcon($filename, $isDir = false) {
    if ($isDir) {
        return 'bi-folder-fill';
    }
    
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $iconMap = [
        // Images
        'jpg' => 'bi-file-earmark-image',
        'jpeg' => 'bi-file-earmark-image',
        'png' => 'bi-file-earmark-image',
        'gif' => 'bi-file-earmark-image',
        'bmp' => 'bi-file-earmark-image',
        'svg' => 'bi-file-earmark-image',
        'webp' => 'bi-file-earmark-image',
        
        // Documents
        'pdf' => 'bi-file-earmark-pdf',
        'doc' => 'bi-file-earmark-word',
        'docx' => 'bi-file-earmark-word',
        'xls' => 'bi-file-earmark-excel',
        'xlsx' => 'bi-file-earmark-excel',
        'ppt' => 'bi-file-earmark-ppt',
        'pptx' => 'bi-file-earmark-ppt',
        'txt' => 'bi-file-earmark-text',
        'rtf' => 'bi-file-earmark-text',
        
        // Archives
        'zip' => 'bi-file-earmark-zip',
        'rar' => 'bi-file-earmark-zip',
        '7z' => 'bi-file-earmark-zip',
        'tar' => 'bi-file-earmark-zip',
        'gz' => 'bi-file-earmark-zip',
        
        // Code files
        'php' => 'bi-file-earmark-code',
        'html' => 'bi-file-earmark-code',
        'css' => 'bi-file-earmark-code',
        'js' => 'bi-file-earmark-code',
        'json' => 'bi-file-earmark-code',
        'xml' => 'bi-file-earmark-code',
        'py' => 'bi-file-earmark-code',
        'java' => 'bi-file-earmark-code',
        'cpp' => 'bi-file-earmark-code',
        'c' => 'bi-file-earmark-code',
        
        // Audio
        'mp3' => 'bi-file-earmark-music',
        'wav' => 'bi-file-earmark-music',
        'flac' => 'bi-file-earmark-music',
        'aac' => 'bi-file-earmark-music',
        
        // Video
        'mp4' => 'bi-file-earmark-play',
        'avi' => 'bi-file-earmark-play',
        'mkv' => 'bi-file-earmark-play',
        'mov' => 'bi-file-earmark-play',
        'wmv' => 'bi-file-earmark-play',
    ];
    
    return isset($iconMap[$ext]) ? $iconMap[$ext] : 'bi-file-earmark';
}

/**
 * Generates breadcrumb navigation
 * @param string $currentPath The current path relative to FILE_ROOT
 * @return string HTML for breadcrumb navigation
 */
function generateBreadcrumbs($currentPath) {
    $breadcrumbs = '<nav aria-label="breadcrumb"><ol class="breadcrumb">';
    $breadcrumbs .= '<li class="breadcrumb-item"><a href="?"><i class="bi bi-house-fill"></i> Home</a></li>';
    
    if (!empty($currentPath) && $currentPath !== '/') {
        $parts = explode('/', trim($currentPath, '/'));
        $pathSoFar = '';
        
        foreach ($parts as $part) {
            if (empty($part)) continue;
            $pathSoFar .= '/' . $part;
            $breadcrumbs .= '<li class="breadcrumb-item"><a href="?path=' . urlencode($pathSoFar) . '">' . htmlspecialchars($part) . '</a></li>';
        }
    }
    
    $breadcrumbs .= '</ol></nav>';
    return $breadcrumbs;
}

// Get the requested path from URL parameter
$requestedPath = isset($_GET['path']) ? $_GET['path'] : '';

// Validate the path
$currentDir = validatePath($requestedPath);
if ($currentDir === false || !is_dir($currentDir)) {
    $currentDir = FILE_ROOT;
    $requestedPath = '';
}

// Calculate relative path for display
$relativePath = str_replace(realpath(FILE_ROOT), '', $currentDir);
$relativePath = trim($relativePath, '/\\');

// Scan directory and process files
$files = [];
$directories = [];

if ($handle = opendir($currentDir)) {
    while (false !== ($entry = readdir($handle))) {
        if ($entry == '.' || $entry == '..') continue;
        
        $entryPath = $currentDir . '/' . $entry;
        $isDir = is_dir($entryPath);
        
        $item = [
            'name' => $entry,
            'path' => ($relativePath ? $relativePath . '/' : '') . $entry,
            'is_dir' => $isDir,
            'size' => $isDir ? 0 : filesize($entryPath),
            'modified' => filemtime($entryPath),
            'icon' => getFileIcon($entry, $isDir)
        ];
        
        if ($isDir) {
            $directories[] = $item;
        } else {
            $files[] = $item;
        }
    }
    closedir($handle);
}

// Sort directories and files separately, then combine
usort($directories, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
usort($files, function($a, $b) { return strcasecmp($a['name'], $b['name']); });
$allItems = array_merge($directories, $files);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>File Browser</title>
    <!-- Bootstrap 5 CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <!-- Bootstrap Icons -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.10.0/font/bootstrap-icons.css" rel="stylesheet">
    <style>
        .file-icon {
            font-size: 1.2rem;
            margin-right: 0.5rem;
        }
        .folder-icon {
            color: #ffc107;
        }
        .file-row:hover {
            background-color: #f8f9fa;
        }
        .file-name {
            text-decoration: none;
            color: #0d6efd;
        }
        .file-name:hover {
            color: #0a58ca;
            text-decoration: underline;
        }
        .folder-name {
            text-decoration: none;
            color: #198754;
            font-weight: 500;
        }
        .folder-name:hover {
            color: #146c43;
            text-decoration: underline;
        }
        .search-box {
            max-width: 300px;
        }
        .table-responsive {
            border-radius: 0.375rem;
            overflow: hidden;
            box-shadow: 0 0.125rem 0.25rem rgba(0, 0, 0, 0.075);
        }
    </style>
</head>
<body class="bg-light">
    <div class="container-fluid py-4">
        <div class="row">
            <div class="col-12">
                <!-- Header -->
                <div class="d-flex justify-content-between align-items-center mb-4">
                    <h1 class="h3 mb-0">
                        <i class="bi bi-folder2-open text-primary"></i>
                        File Browser
                    </h1>
                    <!-- Search Box -->
                    <div class="search-box">
                        <input type="text" class="form-control" id="searchInput" placeholder="Search files...">
                    </div>
                </div>

                <!-- Breadcrumbs -->
                <?php echo generateBreadcrumbs('/' . $relativePath); ?>

                <!-- Files Table -->
                <div class="card">
                    <div class="card-body p-0">
                        <div class="table-responsive">
                            <table class="table table-hover mb-0">
                                <thead class="table-light">
                                    <tr>
                                        <th style="width: 50px;"></th>
                                        <th>Name</th>
                                        <th style="width: 120px;">Size</th>
                                        <th style="width: 180px;">Modified</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php if (empty($allItems)): ?>
                                        <tr>
                                            <td colspan="4" class="text-center text-muted py-4">
                                                <i class="bi bi-folder-x"></i>
                                                This folder is empty
                                            </td>
                                        </tr>
                                    <?php else: ?>
                                        <?php foreach ($allItems as $item): ?>
                                            <tr class="file-row">
                                                <td class="text-center">
                                                    <i class="<?php echo $item['icon']; ?> file-icon <?php echo $item['is_dir'] ? 'folder-icon' : ''; ?>"></i>
                                                </td>
                                                <td>
                                                    <?php if ($item['is_dir']): ?>
                                                        <a href="?path=<?php echo urlencode('/' . $item['path']); ?>" class="folder-name">
                                                            <?php echo htmlspecialchars($item['name']); ?>
                                                        </a>
                                                    <?php else: ?>
                                                        <a href="download.php?file=<?php echo urlencode('/' . $item['path']); ?>" class="file-name">
                                                            <?php echo htmlspecialchars($item['name']); ?>
                                                        </a>
                                                    <?php endif; ?>
                                                </td>
                                                <td class="text-muted">
                                                    <?php echo $item['is_dir'] ? '—' : formatFileSize($item['size']); ?>
                                                </td>
                                                <td class="text-muted">
                                                    <?php echo date('M j, Y g:i A', $item['modified']); ?>
                                                </td>
                                            </tr>
                                        <?php endforeach; ?>
                                    <?php endif; ?>
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <!-- Footer -->
                <div class="mt-4 text-center text-muted">
                    <small>
                        <?php echo count($directories); ?> folder(s), <?php echo count($files); ?> file(s)
                    </small>
                </div>
            </div>
        </div>
    </div>

    <!-- Bootstrap 5 JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Search functionality -->
    <script>
        document.getElementById('searchInput').addEventListener('input', function(e) {
            const searchTerm = e.target.value.toLowerCase();
            const rows = document.querySelectorAll('.file-row');
            
            rows.forEach(row => {
                const fileName = row.querySelector('td:nth-child(2)').textContent.toLowerCase();
                if (fileName.includes(searchTerm)) {
                    row.style.display = '';
                } else {
                    row.style.display = 'none';
                }
            });
        });
    </script>
</body>
</html>

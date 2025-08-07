<?php
/**
 * Secure File Download Handler
 * This script handles all file downloads with proper security validation
 */

// Security: Define the allowed file root directory
define('FILE_ROOT', __DIR__ . '/files');

/**
 * Validates and sanitizes the requested file path
 * @param string $file The requested file path
 * @return string|false The validated file path or false if invalid
 */
function validateFilePath($file) {
    // Remove any null bytes and normalize path separators
    $file = str_replace("\0", '', $file);
    $file = str_replace('\\', '/', $file);
    
    // Remove any leading slash to make it relative
    $file = ltrim($file, '/');
    
    // Construct the full path
    $fullPath = FILE_ROOT . '/' . $file;
    
    // Get the real path (resolves .. and . components)
    $realPath = realpath($fullPath);
    
    // Security check: ensure the path is within FILE_ROOT and is a file
    if ($realPath === false || 
        strpos($realPath, realpath(FILE_ROOT)) !== 0 || 
        !is_file($realPath)) {
        return false;
    }
    
    return $realPath;
}

/**
 * Gets the MIME type for a file based on its extension
 * @param string $filename The filename
 * @return string The MIME type
 */
function getMimeType($filename) {
    $ext = strtolower(pathinfo($filename, PATHINFO_EXTENSION));
    
    $mimeTypes = [
        // Images
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'gif' => 'image/gif',
        'bmp' => 'image/bmp',
        'svg' => 'image/svg+xml',
        'webp' => 'image/webp',
        
        // Documents
        'pdf' => 'application/pdf',
        'doc' => 'application/msword',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'xls' => 'application/vnd.ms-excel',
        'xlsx' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'ppt' => 'application/vnd.ms-powerpoint',
        'pptx' => 'application/vnd.openxmlformats-officedocument.presentationml.presentation',
        'txt' => 'text/plain',
        'rtf' => 'application/rtf',
        
        // Archives
        'zip' => 'application/zip',
        'rar' => 'application/x-rar-compressed',
        '7z' => 'application/x-7z-compressed',
        'tar' => 'application/x-tar',
        'gz' => 'application/gzip',
        
        // Code files
        'php' => 'text/x-php',
        'html' => 'text/html',
        'css' => 'text/css',
        'js' => 'application/javascript',
        'json' => 'application/json',
        'xml' => 'application/xml',
        'py' => 'text/x-python',
        
        // Audio
        'mp3' => 'audio/mpeg',
        'wav' => 'audio/wav',
        'flac' => 'audio/flac',
        'aac' => 'audio/aac',
        
        // Video
        'mp4' => 'video/mp4',
        'avi' => 'video/x-msvideo',
        'mkv' => 'video/x-matroska',
        'mov' => 'video/quicktime',
        'wmv' => 'video/x-ms-wmv',
    ];
    
    return isset($mimeTypes[$ext]) ? $mimeTypes[$ext] : 'application/octet-stream';
}

/**
 * Safely outputs a file name for Content-Disposition header
 * @param string $filename The filename
 * @return string The safe filename
 */
function safenameForDownload($filename) {
    // Remove or replace unsafe characters
    $filename = preg_replace('/[^\w\s\.\-_()]/', '', $filename);
    return trim($filename);
}

// Check if file parameter is provided
if (!isset($_GET['file']) || empty($_GET['file'])) {
    http_response_code(400);
    die('Error: No file specified');
}

// Validate the requested file path
$filePath = validateFilePath($_GET['file']);

if ($filePath === false) {
    http_response_code(404);
    die('Error: File not found or access denied');
}

// Get file information
$fileName = basename($filePath);
$fileSize = filesize($filePath);
$mimeType = getMimeType($fileName);
$safeFileName = safenameForDownload($fileName);

// Security: Additional check to ensure file exists and is readable
if (!is_readable($filePath)) {
    http_response_code(403);
    die('Error: File is not readable');
}

// Prevent caching
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Set security headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');

// Set download headers
header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . $safeFileName . '"');
header('Content-Transfer-Encoding: binary');
header('Content-Length: ' . $fileSize);

// Clear any previous output
if (ob_get_level()) {
    ob_end_clean();
}

// Output the file efficiently
if ($fileSize > 8192) {
    // For larger files, use readfile for better memory usage
    readfile($filePath);
} else {
    // For smaller files, read into memory first
    echo file_get_contents($filePath);
}

// Ensure no additional output
exit;
?>

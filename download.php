<?php
// ============================================================
// download.php — Streams a stored file to the browser as a
//               download, after verifying ownership.
//
// URL parameter: ?id=<integer>
//
// Security:
//   - Requires the user to be logged in.
//   - Fetches the row by id AND username — users can only
//     download their own files.
//   - The file path comes entirely from the database, never
//     from any user-supplied URL parameter.
//   - basename() is applied to the stored filename to prevent
//     path traversal attacks (e.g. ../../etc/passwd).
//   - The resolved path is verified to be inside uploads/.
// ============================================================

require_once 'config.php';
require_once 'database.php';
require_login();

$username = $_SESSION['username'];
$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid request: missing or bad file ID.');
}

// Fetch the record — returns null if not found or owned by another user.
$record = db_get_upload_by_id($id, $username);

if ($record === null) {
    http_response_code(404);
    exit('File record not found or you do not have permission to download it.');
}

// Build the absolute path ONLY from the database value.
// basename() strips any directory components from stored_name,
// ensuring we never traverse outside the uploads/ directory.
$safeStoredName = basename($record['stored_name']);

// Extra safety: reject if stored name contains a directory separator
// (should never happen with our generator, but be defensive).
if ($safeStoredName === '' || strpos($safeStoredName, '/') !== false || strpos($safeStoredName, '\\') !== false) {
    http_response_code(400);
    exit('Invalid stored filename.');
}

$uploadsDir       = rtrim(__DIR__ . '/uploads', '/\\') . DIRECTORY_SEPARATOR;
$absoluteFilePath = $uploadsDir . $safeStoredName;

// Verify the resolved path is actually inside uploads/ (defence in depth)
$realUploads = realpath(__DIR__ . '/uploads');
$realFile    = realpath($absoluteFilePath);

if ($realFile === false || $realUploads === false) {
    http_response_code(404);
    exit('The file is no longer available on disk.');
}

// Ensure the file is inside the uploads directory
if (strpos($realFile, $realUploads . DIRECTORY_SEPARATOR) !== 0
    && $realFile !== $realUploads) {
    http_response_code(403);
    exit('Access denied.');
}

if (!file_exists($realFile) || !is_file($realFile)) {
    http_response_code(404);
    exit('The original file is no longer available on disk.');
}

// Determine MIME type from the original file extension
$ext      = strtolower(pathinfo($record['original_name'], PATHINFO_EXTENSION));
$mimeType = get_mime_type($ext);

// Stream the file to the browser as an attachment (triggers Save As dialog)
// Use the original filename in Content-Disposition so the user sees the
// correct name in their download manager, not the UUID-prefixed stored name.
header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . rawurlencode($record['original_name']) . '"');
header('Content-Length: ' . filesize($realFile));
header('Cache-Control: no-cache, no-store, must-revalidate');
header('Pragma: no-cache');
header('Expires: 0');

// Flush any output buffering before streaming to avoid memory issues
if (ob_get_level()) {
    ob_end_clean();
}

readfile($realFile);
exit;

<?php
// ============================================================
// download.php — Streams a stored file to the browser as a
//               download, after verifying ownership.
//
// URL parameter: ?id=<integer>
//
// Security:
//   - Requires the user to be logged in.
//   - Fetches the row by id AND username so a user can only
//     download their own files.
//   - The file path comes from the database, never from the URL.
//   - Uses basename() to prevent any path-traversal tricks.
// ============================================================

require_once 'config.php';
require_once 'database.php';
require_login();

$username = $_SESSION['username'];
$id       = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    http_response_code(400);
    exit('Invalid request.');
}

// Fetch record — null if id not found or owned by a different user
$record = db_get_upload_by_id($id, $username);

if ($record === null) {
    http_response_code(404);
    exit('File record not found.');
}

// Build the absolute path using the value stored in the database.
// basename() on stored_name ensures we never traverse outside uploads/.
$safeStoredName   = basename($record['stored_name']);
$absoluteFilePath = __DIR__ . '/uploads/' . $safeStoredName;

if (!file_exists($absoluteFilePath)) {
    http_response_code(404);
    exit('The original file is no longer available on disk.');
}

// Resolve the correct MIME type for the Content-Type header
$ext      = strtolower(pathinfo($record['original_name'], PATHINFO_EXTENSION));
$mimeType = get_mime_type($ext);

// Stream the file to the browser as an attachment (triggers Save As dialog)
header('Content-Description: File Transfer');
header('Content-Type: ' . $mimeType);
header('Content-Disposition: attachment; filename="' . addslashes($record['original_name']) . '"');
header('Content-Length: ' . filesize($absoluteFilePath));
header('Cache-Control: no-cache');

// Flush any output buffering to avoid memory issues with large files
if (ob_get_level()) {
    ob_end_clean();
}

readfile($absoluteFilePath);
exit;

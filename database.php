<?php
// ============================================================
// database.php — SQLite database connection and helper functions
//
// WHY SQLite:
//   - No separate database server needed — just a single file.
//   - pdo_sqlite is already enabled in this XAMPP installation.
//   - Perfect for a local-only project with one user at a time.
//
// DATABASE FILE LOCATION:
//   C:\xampp\htdocs\loginsample\data\uploads.db
//   Created automatically on first page load — no setup needed.
//
// TABLE: uploads
//   Stores one row per file upload, including the extracted text.
// ============================================================

// Path to the SQLite database file.
// __DIR__ = the directory this file lives in (loginsample/).
define('DB_PATH', __DIR__ . '/data/uploads.db');

// Path to the folder where uploaded files are physically stored.
define('UPLOADS_DIR', __DIR__ . '/uploads/');

// 35 MB limit in bytes — enforced in both PHP and JavaScript.
define('MAX_UPLOAD_BYTES', 35 * 1024 * 1024);

// ---------------------------------------------------------------
// get_db() — returns a shared PDO connection to the SQLite file.
//
// The connection is cached in a static variable so we only open
// the database file once per request, not once per function call.
// ---------------------------------------------------------------
function get_db(): PDO
{
    static $pdo = null;

    if ($pdo === null) {
        // DSN format for SQLite: "sqlite:/full/path/to/file.db"
        $pdo = new PDO('sqlite:' . DB_PATH);

        // Throw exceptions on any database error instead of
        // returning false and silently failing.
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        // Return rows as associative arrays (column name => value).
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        // Create the uploads table if it does not exist yet.
        // This runs every request but is instant after the first time.
        init_db($pdo);
    }

    return $pdo;
}

// ---------------------------------------------------------------
// init_db($pdo) — creates the uploads table the first time.
// ---------------------------------------------------------------
function init_db(PDO $pdo): void
{
    $pdo->exec("
        CREATE TABLE IF NOT EXISTS uploads (
            id              INTEGER PRIMARY KEY AUTOINCREMENT,
            username        TEXT    NOT NULL,
            original_name   TEXT    NOT NULL,
            stored_name     TEXT    NOT NULL,
            file_type       TEXT    NOT NULL,
            file_size       INTEGER NOT NULL,
            file_path       TEXT    NOT NULL,
            extracted_text  TEXT    NOT NULL DEFAULT '',
            uploaded_at     TEXT    NOT NULL
        )
    ");
    // Column notes:
    //   id            — auto-incrementing primary key
    //   username      — value from $_SESSION['username'] at upload time
    //   original_name — filename the user chose, e.g. "notes.pdf"
    //   stored_name   — UUID-based filename we save to disk, e.g. "a3f2...notes.pdf"
    //   file_type     — extension without dot, e.g. "pdf", "png", "txt"
    //   file_size     — size in bytes (integer)
    //   file_path     — relative path under loginsample/, e.g. "uploads/a3f2...pdf"
    //   extracted_text — the full text returned by the Azure Function
    //   uploaded_at   — ISO 8601 datetime string, e.g. "2026-08-12 18:30:00"
}

// ---------------------------------------------------------------
// db_insert_upload(array $data): int
//
// Inserts one upload record and returns the new row's id.
// $data must contain all required keys (see below).
// ---------------------------------------------------------------
function db_insert_upload(array $data): int
{
    $pdo  = get_db();
    $stmt = $pdo->prepare("
        INSERT INTO uploads
            (username, original_name, stored_name, file_type, file_size,
             file_path, extracted_text, uploaded_at)
        VALUES
            (:username, :original_name, :stored_name, :file_type, :file_size,
             :file_path, :extracted_text, :uploaded_at)
    ");

    $stmt->execute([
        ':username'       => $data['username'],
        ':original_name'  => $data['original_name'],
        ':stored_name'    => $data['stored_name'],
        ':file_type'      => $data['file_type'],
        ':file_size'      => (int) $data['file_size'],
        ':file_path'      => $data['file_path'],
        ':extracted_text' => $data['extracted_text'],
        ':uploaded_at'    => $data['uploaded_at'],
    ]);

    return (int) $pdo->lastInsertId();
}

// ---------------------------------------------------------------
// db_get_uploads_for_user(string $username): array
//
// Returns all upload rows for the given user, newest first.
// ---------------------------------------------------------------
function db_get_uploads_for_user(string $username): array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare("
        SELECT * FROM uploads
        WHERE  username = :username
        ORDER  BY id DESC
    ");
    $stmt->execute([':username' => $username]);
    return $stmt->fetchAll();
}

// ---------------------------------------------------------------
// db_get_upload_by_id(int $id, string $username): array|null
//
// Returns a single upload row, or null if not found.
// The username check ensures users can only view their own files.
// ---------------------------------------------------------------
function db_get_upload_by_id(int $id, string $username): ?array
{
    $pdo  = get_db();
    $stmt = $pdo->prepare("
        SELECT * FROM uploads
        WHERE  id = :id AND username = :username
        LIMIT  1
    ");
    $stmt->execute([':id' => $id, ':username' => $username]);
    $row = $stmt->fetch();
    return $row !== false ? $row : null;
}

// ---------------------------------------------------------------
// format_file_size(int $bytes): string
//
// Converts a byte count to a human-readable string.
// Examples: 512 → "512 B", 2048 → "2.0 KB", 5242880 → "5.0 MB"
// ---------------------------------------------------------------
function format_file_size(int $bytes): string
{
    if ($bytes < 1024)       return $bytes . ' B';
    if ($bytes < 1048576)    return round($bytes / 1024, 1) . ' KB';
    return round($bytes / 1048576, 1) . ' MB';
}

// ---------------------------------------------------------------
// generate_stored_filename(string $originalName): string
//
// Builds a collision-proof filename for storing on disk.
// Format: {hex-uuid}-{sanitised-original}
// Example: "a3f2c1d0e4b5...6789-notes.pdf"
//
// The original name is sanitised to keep only safe characters
// so it can never be used for path traversal.
// ---------------------------------------------------------------
function generate_stored_filename(string $originalName): string
{
    // Generate a random 16-byte token and hex-encode it (32 chars).
    $uuid = bin2hex(random_bytes(16));

    // Keep only letters, digits, dots, dashes, underscores from the
    // original name to strip any dangerous characters.
    $safe = preg_replace('/[^a-zA-Z0-9._-]/', '_', basename($originalName));

    return $uuid . '-' . $safe;
}

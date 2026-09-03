<?php
// ============================================================
// upload-details.php — View metadata and extracted text for
//                      a single upload record
//
// URL parameter: ?id=<integer>
//
// Data source: local SQLite database only (no external API).
//
// Security:
//   db_get_upload_by_id() filters by BOTH id AND username,
//   so users can never view another user's records.
// ============================================================

require_once 'config.php';
require_once 'database.php';
require_login();

$username = $_SESSION['username'];

// (int) cast converts anything non-numeric to 0,
// which will not match any real row.
$id = isset($_GET['id']) ? (int) $_GET['id'] : 0;

if ($id <= 0) {
    header('Location: upload-history.php');
    exit;
}

// Returns null if id not found or belongs to another user
$record = db_get_upload_by_id($id, $username);

if ($record === null) {
    header('Location: upload-history.php');
    exit;
}

// Check if the physical file still exists on disk
$absoluteFilePath = __DIR__ . '/' . $record['file_path'];
$fileExists       = file_exists($absoluteFilePath);

// Format the upload date nicely for display
$uploadedAt = $record['uploaded_at'];
$dateDisplay = $uploadedAt;
$ts = strtotime($uploadedAt);
if ($ts !== false) {
    $dateDisplay = date('F j, Y \a\t g:i A', $ts);
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Details — LoginSample</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <div class="navbar">
        <span class="brand">🔐 LoginSample</span>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="upload-file.php">File Upload</a>
            <a href="upload-history.php" class="active">History</a>
            <a href="audio.php">Audio</a>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="page-wrapper">
        <div class="card">
            <h1>🔍 Upload Details</h1>

            <!-- File metadata table -->
            <table class="details-table">
                <tr>
                    <th>File Name</th>
                    <td><?= h($record['original_name']) ?></td>
                </tr>
                <tr>
                    <th>File Type</th>
                    <td>
                        <span class="badge badge-<?= strtolower(h($record['file_type'])) ?>">
                            <?= h($record['file_type']) ?>
                        </span>
                    </td>
                </tr>
                <tr>
                    <th>File Size</th>
                    <td><?= h(format_file_size((int)$record['file_size'])) ?></td>
                </tr>
                <tr>
                    <th>Uploaded By</th>
                    <td><?= h($record['username']) ?></td>
                </tr>
                <tr>
                    <th>Uploaded At</th>
                    <td><?= h($dateDisplay) ?></td>
                </tr>
                <tr>
                    <th>Stored As</th>
                    <td class="text-muted" style="font-size:0.82rem;word-break:break-all">
                        <?= h($record['stored_name']) ?>
                    </td>
                </tr>
            </table>

            <!-- Download button — only if the file still exists on disk -->
            <?php if ($fileExists): ?>
                <div class="mt-2">
                    <!--
                        download.php re-validates ownership before streaming the file.
                        We never expose the raw filesystem path in the URL.
                    -->
                    <a href="download.php?id=<?= (int)$record['id'] ?>"
                       class="btn btn-secondary">
                        ⬇ Download Original File
                    </a>
                </div>
            <?php else: ?>
                <div class="alert alert-warning mt-2">
                    The original file is no longer available on disk.
                    The extracted text below is still available from the database.
                </div>
            <?php endif; ?>

            <!-- Extracted text -->
            <div class="result-box mt-3">
                <h2>Extracted Content</h2>
                <?php if (trim($record['extracted_text']) !== ''): ?>
                    <textarea readonly><?= h($record['extracted_text']) ?></textarea>
                <?php else: ?>
                    <p class="text-muted" style="padding:.5rem 0">
                        No extracted text was saved for this upload.
                        <?php if (in_array(strtolower($record['file_type']), ['jpg','jpeg','png'])): ?>
                            Image OCR requires Tesseract to be installed on the server.
                        <?php endif; ?>
                    </p>
                <?php endif; ?>
            </div>

            <!-- Navigation -->
            <div class="mt-2">
                <a href="upload-history.php" class="btn btn-secondary">
                    ← Back to History
                </a>
                <a href="upload-file.php" class="btn btn-primary" style="margin-left:.5rem">
                    + Upload Another File
                </a>
            </div>

        </div>
    </div>

</body>
</html>

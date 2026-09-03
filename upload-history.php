<?php
// ============================================================
// upload-history.php — Shows all uploads for the logged-in user
//
// Data comes entirely from the local SQLite database (data/uploads.db).
// No external API or Azure Function is used.
//
// Displays: File Name | Type | Size | Uploaded At | Actions
// Sorted:   Newest first (ORDER BY id DESC in db helper)
// Filtered: Only rows belonging to $_SESSION['username']
// ============================================================

require_once 'config.php';
require_once 'database.php';
require_login();

$username = $_SESSION['username'];

// Fetch all uploads for this user — newest first
$uploads = db_get_uploads_for_user($username);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload History — LoginSample</title>
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

    <div class="page-wrapper" style="max-width:960px">
        <div class="card">
            <h1>📋 Upload History</h1>

            <?php if (empty($uploads)): ?>
                <!-- No uploads yet -->
                <div class="alert alert-info">
                    No uploads yet. <a href="upload-file.php">Upload your first file</a>
                    to see it here.
                </div>

            <?php else: ?>
                <p class="text-muted" style="margin-bottom:1rem">
                    Showing <strong><?= count($uploads) ?></strong>
                    upload<?= count($uploads) !== 1 ? 's' : '' ?> for
                    <strong><?= h($username) ?></strong> — newest first.
                </p>

                <div class="table-wrapper">
                    <table class="history-table">
                        <thead>
                            <tr>
                                <th>#</th>
                                <th>File Name</th>
                                <th>Type</th>
                                <th>Size</th>
                                <th>Uploaded At</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                        <?php foreach ($uploads as $i => $row): ?>
                            <tr>
                                <td class="td-num"><?= $i + 1 ?></td>

                                <td class="td-name" title="<?= h($row['original_name']) ?>">
                                    <?= h($row['original_name']) ?>
                                </td>

                                <td>
                                    <span class="badge badge-<?= strtolower(h($row['file_type'])) ?>">
                                        <?= h($row['file_type']) ?>
                                    </span>
                                </td>

                                <td><?= h(format_file_size((int)$row['file_size'])) ?></td>

                                <td><?= h($row['uploaded_at']) ?></td>

                                <td>
                                    <a href="upload-details.php?id=<?= (int)$row['id'] ?>"
                                       class="btn btn-primary btn-sm">
                                        View
                                    </a>
                                    &nbsp;
                                    <a href="download.php?id=<?= (int)$row['id'] ?>"
                                       class="btn btn-secondary btn-sm">
                                        ⬇
                                    </a>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

            <?php endif; ?>

            <div class="mt-2">
                <a href="upload-file.php" class="btn btn-primary">+ Upload New File</a>
                <a href="dashboard.php" class="btn btn-secondary" style="margin-left:.5rem">
                    Back to Dashboard
                </a>
            </div>

        </div>
    </div>

</body>
</html>

<?php
// ============================================================
// dashboard.php — Main dashboard shown after login
//
// Shows a welcome message and three navigation cards:
//   - File / Image Upload  → upload-file.php
//   - Upload History       → upload-history.php  (NEW)
//   - Audio Transcription  → audio.php
// ============================================================

require_once 'config.php';
require_once 'database.php';   // needed for db_get_uploads_for_user / format_file_size

require_login();

$username = $_SESSION['username'];

// Show a quick count of how many uploads this user has made
$uploads      = db_get_uploads_for_user($username);
$uploadCount  = count($uploads);
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard — LoginSample</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <div class="navbar">
        <span class="brand">🔐 LoginSample</span>
        <nav>
            <a href="dashboard.php" class="active">Dashboard</a>
            <a href="upload-file.php">File Upload</a>
            <a href="upload-history.php">History</a>
            <a href="audio.php">Audio</a>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="page-wrapper">
        <div class="card">
            <h1>Welcome, <?= h($username) ?>!</h1>
            <p class="text-muted">You are logged in. Choose a feature below to get started.</p>

            <!-- Three feature cards — uses dashboard-grid-3 (2-column grid) -->
            <div class="dashboard-grid-3">

                <!-- Card 1: File / Image Upload -->
                <div class="dash-card">
                    <div class="dash-icon">📄</div>
                    <h3>File / Image Upload</h3>
                    <p>
                        Upload a PDF, text file, or image.<br>
                        The backend extracts and displays the content.
                    </p>
                    <a href="upload-file.php">Open</a>
                </div>

                <!-- Card 2: Upload History (NEW) -->
                <div class="dash-card">
                    <div class="dash-icon">📋</div>
                    <h3>Upload History</h3>
                    <p>
                        View all previously uploaded files.<br>
                        <?php if ($uploadCount > 0): ?>
                            You have <strong><?= $uploadCount ?></strong>
                            upload<?= $uploadCount !== 1 ? 's' : '' ?> saved.
                        <?php else: ?>
                            No uploads yet.
                        <?php endif; ?>
                    </p>
                    <a href="upload-history.php">Open</a>
                </div>

                <!-- Card 3: Audio Transcription -->
                <div class="dash-card">
                    <div class="dash-icon">🎙️</div>
                    <h3>Audio Transcription</h3>
                    <p>
                        Upload an MP3 or WAV file.<br>
                        Whisper converts speech to text locally.
                    </p>
                    <a href="audio.php">Open</a>
                </div>

            </div>
        </div>
    </div>

</body>
</html>

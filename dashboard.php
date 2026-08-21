<?php
// ============================================================
// dashboard.php — Main dashboard shown after login
//
// Shows a welcome message and two navigation cards:
//   - File / Image Upload  → upload-file.php
//   - Audio Transcription  → audio.php
// ============================================================

require_once 'config.php';

// require_login() checks $_SESSION['username'].
// If not set it redirects to login.php and exits.
require_login();

$username = $_SESSION['username'];
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

    <!-- Top navigation bar -->
    <div class="navbar">
        <span class="brand">🔐 LoginSample</span>
        <nav>
            <a href="dashboard.php" class="active">Dashboard</a>
            <a href="upload-file.php">File Upload</a>
            <a href="audio.php">Audio</a>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="page-wrapper">
        <div class="card">
            <h1>Welcome, <?= h($username) ?>!</h1>
            <p class="text-muted">You are logged in. Choose a feature below to get started.</p>

            <!-- Two feature cards side by side -->
            <div class="dashboard-grid">

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

                <!-- Card 2: Audio Transcription -->
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

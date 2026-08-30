<?php
// ============================================================
// audio.php — Audio upload and speech-to-text transcription
//
// Supported formats: .mp3  .wav
//
// Flow:
//   1. User picks an audio file and clicks "Upload & Transcribe"
//   2. PHP receives the file in $_FILES['file']
//   3. call_api_multipart() POSTs it to /api/transcribe-audio
//   4. The Azure Function writes the file to a temp path,
//      runs whisper_transcribe.py as a subprocess, and returns:
//      { success, fileName, transcript, message }
//   5. PHP displays the transcript in a textarea
//
// NOTE: Whisper runs on CPU and can take several minutes for
// longer audio files. The curl timeout is set to 10 minutes
// (600 seconds) to match the Function's own 10-minute timeout.
// The page shows a "please wait" message during processing.
// ============================================================

require_once 'config.php';
require_login();

const ALLOWED_AUDIO_EXTS = ['mp3', 'wav'];
const MAX_AUDIO_BYTES     = 50 * 1024 * 1024; // 50 MB — matches WPF client

$error      = '';
$success    = '';
$transcript = '';
$fileName   = '';

// ---- Handle POST (form submission) -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select an audio file before clicking Upload.';

    } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload error (code ' . $_FILES['file']['error'] . '). Please try again.';

    } else {
        $tmpPath  = $_FILES['file']['tmp_name'];
        $origName = $_FILES['file']['name'];
        $fileSize = $_FILES['file']['size'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ALLOWED_AUDIO_EXTS, true)) {
            $error = 'Unsupported audio format ".' . h($ext) . '". '
                   . 'Please upload an .mp3 or .wav file.';

        } elseif ($fileSize === 0) {
            $error = 'The selected audio file is empty.';

        } elseif ($fileSize > MAX_AUDIO_BYTES) {
            $error = 'The file is too large. Maximum size is 50 MB.';

        } else {
            $mimeType = get_mime_type($ext);

            // Timeout = 600 seconds (10 minutes).
            // Whisper can take several minutes for a long audio file on CPU.
            // This matches the WaitForExitAsync timeout inside the Azure Function.
            $result = call_api_multipart(
                '/transcribe-audio',
                $tmpPath,
                $origName,
                $mimeType,
                600
            );

            $fileName = $result['fileName'] ?? $origName;

            if (!empty($result['success']) && $result['success'] === true) {
                $transcript = $result['transcript'] ?? '';
                $success    = 'Transcription completed for "' . h($fileName) . '".';
            } else {
                $error = $result['message'] ?? 'The backend could not transcribe this audio file.';
            }
        }
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Audio Transcription — LoginSample</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <!-- Top navigation bar -->
    <div class="navbar">
        <span class="brand">🔐 LoginSample</span>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="upload-file.php">File Upload</a>
            <a href="upload-history.php">History</a>
            <a href="audio.php" class="active">Audio</a>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="page-wrapper">
        <div class="card">
            <h1>🎙️ Audio Transcription</h1>

            <div class="alert alert-info">
                Transcription runs <strong>locally</strong> using OpenAI Whisper on CPU.
                A short clip takes ~30 seconds; a 4-minute song may take several minutes.
                Please wait after clicking Upload — do not close the tab.
            </div>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <form method="post" action="audio.php"
                  enctype="multipart/form-data" id="audioForm">

                <div class="form-group">
                    <label for="file">Select an audio file</label>
                    <input
                        type="file"
                        id="file"
                        name="file"
                        accept=".mp3,.wav"
                        required>
                    <small>Supported: .mp3 · .wav — Max 50 MB</small>
                </div>

                <div class="mt-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        Upload &amp; Transcribe
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary" style="margin-left:.5rem">
                        Back
                    </a>
                </div>

                <!-- Spinner with a more descriptive message for audio -->
                <div class="spinner" id="spinner">
                    ⏳ Transcribing audio with Whisper — this may take a few minutes…
                </div>

            </form>

            <!-- Transcript result area -->
            <?php if ($transcript !== ''): ?>
                <div class="result-box">
                    <h2>Transcript</h2>
                    <textarea readonly><?= h($transcript) ?></textarea>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        // Disable the button and show spinner while Whisper is running.
        // Without this the user might click again thinking nothing happened.
        document.getElementById('audioForm').addEventListener('submit', function () {
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('submitBtn').textContent = 'Transcribing…';
            document.getElementById('spinner').classList.add('visible');
        });
    </script>

</body>
</html>

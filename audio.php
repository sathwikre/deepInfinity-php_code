<?php
// ============================================================
// audio.php — Audio upload and local speech-to-text transcription
//
// Supported formats: .mp3  .wav
// Max file size:      50 MB
//
// This page is SELF-CONTAINED — it does NOT call any Azure
// Function or external API.
//
// Transcription strategy (in order of availability):
//   1. System Tesseract — not applicable to audio
//   2. Local Whisper via Python CLI:
//      whisper <file> --model tiny --output_format txt
//      Requires: Python 3.x + openai-whisper installed,
//                ffmpeg on PATH (for MP3 decoding)
//   3. If Whisper is not available → show a clear message
//      with installation instructions. The audio page still
//      works — it just cannot transcribe without Whisper.
// ============================================================

require_once 'config.php';
require_login();

const ALLOWED_AUDIO_EXTS = ['mp3', 'wav'];
const MAX_AUDIO_BYTES     = 50 * 1024 * 1024; // 50 MB

$error      = '';
$success    = '';
$transcript = '';
$fileName   = '';
$whisperAvailable = false;

// ---- Detect whether Whisper CLI is available ---------------
if (function_exists('exec')) {
    // Try "whisper --version" — Whisper doesn't have a --version flag
    // but calling it with no args returns a usage/error message with exit 0
    // on some versions. More reliably: check if the command exists.
    $testCmds = ['whisper --help', 'python -m whisper --help', 'py -m whisper --help'];
    foreach ($testCmds as $cmd) {
        exec($cmd . ' 2>&1', $testOut, $testRet);
        if ($testRet === 0 || (isset($testOut[0]) && stripos($testOut[0], 'whisper') !== false)) {
            $whisperAvailable = true;
            break;
        }
    }
}

// ---- Handle POST (form submission) -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select an audio file before clicking Upload.';

    } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload error (code ' . (int)$_FILES['file']['error'] . '). Please try again.';

    } else {
        $tmpPath  = $_FILES['file']['tmp_name'];
        $origName = $_FILES['file']['name'];
        $fileSize = (int) $_FILES['file']['size'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ALLOWED_AUDIO_EXTS, true)) {
            $error = 'Unsupported audio format ".' . h($ext) . '". '
                   . 'Please upload an .mp3 or .wav file.';

        } elseif ($fileSize === 0) {
            $error = 'The selected audio file is empty.';

        } elseif ($fileSize > MAX_AUDIO_BYTES) {
            $error = 'File too large. Maximum size for audio is 50 MB. '
                   . 'Your file is ' . round($fileSize / (1024 * 1024), 1) . ' MB.';

        } elseif (!$whisperAvailable) {
            $error = 'Whisper is not installed or not available on this server. '
                   . 'See the instructions below to set it up.';

        } else {
            $fileName = $origName;

            // Save the audio file to a temp location with the correct extension
            // so Whisper can identify the format.
            $tmpAudio = sys_get_temp_dir() . DIRECTORY_SEPARATOR
                      . 'audio_' . bin2hex(random_bytes(8)) . '.' . $ext;

            if (!move_uploaded_file($tmpPath, $tmpAudio)) {
                $error = 'Failed to save the audio file for processing. '
                       . 'Check temp directory permissions.';
            } else {
                // Output directory for Whisper transcript files
                $outDir = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'whisper_' . bin2hex(random_bytes(4));
                mkdir($outDir, 0755);

                // Run Whisper CLI — produces a .txt file in $outDir
                $cmd = sprintf(
                    'whisper %s --model tiny --output_format txt --output_dir %s 2>&1',
                    escapeshellarg($tmpAudio),
                    escapeshellarg($outDir)
                );

                $timeout = 600; // 10 minutes — Whisper on CPU can be slow
                $startTime = time();

                exec($cmd, $cmdOut, $cmdRet);

                // Clean up temp audio file
                @unlink($tmpAudio);

                if ($cmdRet !== 0) {
                    $error = 'Whisper transcription failed. '
                           . 'Make sure ffmpeg is on your PATH for MP3 files. '
                           . 'Error output: ' . h(implode(' ', array_slice($cmdOut, 0, 3)));
                } else {
                    // Find the .txt output file Whisper created
                    $txtFiles = glob($outDir . DIRECTORY_SEPARATOR . '*.txt');
                    $transcriptText = '';

                    if (!empty($txtFiles)) {
                        $transcriptText = file_get_contents($txtFiles[0]);
                        // Clean up output files
                        foreach ($txtFiles as $f) { @unlink($f); }
                    }
                    @rmdir($outDir);

                    if ($transcriptText !== '' && $transcriptText !== false) {
                        $transcript = trim($transcriptText);
                        $success    = 'Transcription completed for "' . h($fileName) . '".';
                    } else {
                        $error = 'Whisper ran but produced no output. '
                               . 'The audio may be silent or in an unsupported format.';
                    }
                }
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

            <?php if ($whisperAvailable): ?>
                <div class="alert alert-info">
                    Whisper is available. Transcription runs
                    <strong>locally</strong> — no internet connection needed.
                    A short clip takes ~30 seconds; longer files may take a few minutes.
                    Please wait after clicking Upload.
                </div>
            <?php else: ?>
                <div class="alert alert-warning">
                    <strong>Whisper is not installed.</strong>
                    The audio form is shown below but transcription will not work
                    until Whisper is set up. See the setup instructions at the
                    bottom of this page.
                </div>
            <?php endif; ?>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= $error ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?= h($success) ?></div>
            <?php endif; ?>

            <form method="post" action="audio.php"
                  enctype="multipart/form-data" id="audioForm">

                <div class="form-group">
                    <label for="file">Select an audio file</label>
                    <input type="file" id="file" name="file"
                           accept=".mp3,.wav" required>
                    <small>Supported: .mp3 &middot; .wav &nbsp;|&nbsp; Max: 50 MB</small>
                </div>

                <div class="mt-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn"
                            <?= !$whisperAvailable ? 'disabled title="Whisper is not installed"' : '' ?>>
                        Upload &amp; Transcribe
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary" style="margin-left:.5rem">
                        Back
                    </a>
                </div>

                <div class="spinner" id="spinner">
                    ⏳ Transcribing with Whisper — this may take a few minutes…
                </div>

            </form>

            <?php if ($transcript !== ''): ?>
                <div class="result-box">
                    <h2>Transcript</h2>
                    <textarea readonly><?= h($transcript) ?></textarea>
                </div>
            <?php endif; ?>

            <!-- ---- Setup instructions (shown when Whisper is missing) ---- -->
            <?php if (!$whisperAvailable): ?>
            <div style="margin-top:2rem; padding:1rem 1.25rem; background:#f8faff;
                        border:1px solid #dbeafe; border-radius:8px;">
                <h2 style="margin-bottom:.75rem">How to Install Whisper</h2>
                <ol style="line-height:1.9; font-size:.9rem; padding-left:1.2rem">
                    <li>
                        Install Python 3.x if you haven't already:<br>
                        <a href="https://www.python.org/downloads/" target="_blank">
                            https://www.python.org/downloads/
                        </a>
                    </li>
                    <li>
                        Install the Whisper library:<br>
                        <code style="background:#e5e7eb;padding:.1rem .4rem;border-radius:3px">
                            pip install openai-whisper
                        </code>
                    </li>
                    <li>
                        Install FFmpeg (required for MP3 decoding) and add it to your PATH:<br>
                        <a href="https://ffmpeg.org/download.html" target="_blank">
                            https://ffmpeg.org/download.html
                        </a>
                    </li>
                    <li>Restart Apache after installing Whisper and FFmpeg.</li>
                    <li>
                        Verify by running in a command prompt:<br>
                        <code style="background:#e5e7eb;padding:.1rem .4rem;border-radius:3px">
                            whisper --help
                        </code>
                    </li>
                </ol>
            </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        document.getElementById('audioForm').addEventListener('submit', function () {
            const btn = document.getElementById('submitBtn');
            btn.disabled = true;
            btn.textContent = 'Transcribing…';
            document.getElementById('spinner').classList.add('visible');
        });
    </script>

</body>
</html>

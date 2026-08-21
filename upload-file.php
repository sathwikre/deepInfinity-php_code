<?php
// ============================================================
// upload-file.php — File / Image upload and content extraction
//
// Supported formats: .txt  .pdf  .jpg  .jpeg  .png
//
// Flow:
//   1. User picks a file and clicks "Upload & Extract"
//   2. PHP receives the file in $_FILES['file']
//   3. PHP saves it to a temp path (PHP does this automatically)
//   4. call_api_multipart() POSTs it to /api/read-file as
//      multipart/form-data — same format the WPF app used
//   5. The Azure Function extracts text (PdfPig / Tesseract OCR)
//      and returns JSON: { success, fileName, content, message }
//   6. PHP displays the extracted content in a textarea
// ============================================================

require_once 'config.php';
require_login();

// Allowed extensions and their max size (25 MB, matching WPF client)
const ALLOWED_FILE_EXTS = ['txt', 'pdf', 'jpg', 'jpeg', 'png'];
const MAX_FILE_BYTES     = 25 * 1024 * 1024; // 25 MB

$error   = '';   // error message to show in red
$success = '';   // success message to show in green
$content = '';   // extracted text returned from the backend
$fileName = '';  // original file name

// ---- Handle POST (form submission) -------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // Check that a file was actually attached
    if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select a file before clicking Upload.';

    } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        // PHP upload error codes (2 = too large, 3 = partial, etc.)
        $error = 'Upload error (code ' . $_FILES['file']['error'] . '). Please try again.';

    } else {
        $tmpPath  = $_FILES['file']['tmp_name'];   // where PHP saved it
        $origName = $_FILES['file']['name'];        // original filename
        $fileSize = $_FILES['file']['size'];

        // Get the extension (lowercase) to validate and pick MIME type
        $ext = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        if (!in_array($ext, ALLOWED_FILE_EXTS, true)) {
            $error = 'Unsupported file type ".' . h($ext) . '". '
                   . 'Please upload a .txt, .pdf, .jpg, .jpeg, or .png file.';

        } elseif ($fileSize === 0) {
            $error = 'The selected file is empty.';

        } elseif ($fileSize > MAX_FILE_BYTES) {
            $error = 'The file is too large. Maximum size is 25 MB.';

        } else {
            // Everything looks good — forward to the Azure Function.
            // get_mime_type() is defined in config.php.
            $mimeType = get_mime_type($ext);

            // call_api_multipart() posts the temp file to /api/read-file
            // with a 30-second timeout (text/PDF/image extraction is fast).
            $result = call_api_multipart(
                '/read-file',
                $tmpPath,
                $origName,
                $mimeType,
                30
            );

            $fileName = $result['fileName'] ?? $origName;

            if (!empty($result['success']) && $result['success'] === true) {
                $content = $result['content'] ?? '';
                $success = 'Content extracted successfully from "' . h($fileName) . '".';
            } else {
                $error = $result['message'] ?? 'The backend could not extract content from this file.';
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
    <title>File Upload — LoginSample</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <!-- Top navigation bar -->
    <div class="navbar">
        <span class="brand">🔐 LoginSample</span>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="upload-file.php" class="active">File Upload</a>
            <a href="audio.php">Audio</a>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="page-wrapper">
        <div class="card">
            <h1>📄 File / Image Upload</h1>

            <!-- Error message -->
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <!-- Success message -->
            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <!--
                enctype="multipart/form-data" is REQUIRED for file uploads.
                Without it the file bytes are never sent to PHP.
            -->
            <form method="post" action="upload-file.php"
                  enctype="multipart/form-data" id="uploadForm">

                <div class="form-group">
                    <label for="file">Select a file</label>
                    <input
                        type="file"
                        id="file"
                        name="file"
                        accept=".txt,.pdf,.jpg,.jpeg,.png"
                        required>
                    <small>Supported: .txt · .pdf · .jpg · .jpeg · .png — Max 25 MB</small>
                </div>

                <div class="mt-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        Upload &amp; Extract
                    </button>
                    <a href="dashboard.php" class="btn btn-secondary" style="margin-left:.5rem">
                        Back
                    </a>
                </div>

                <!-- Simple spinner shown while the request is in flight -->
                <div class="spinner" id="spinner">
                    ⏳ Extracting content, please wait…
                </div>

            </form>

            <!-- Result area — only shown when we have content to display -->
            <?php if ($content !== ''): ?>
                <div class="result-box">
                    <h2>Extracted Content</h2>
                    <!--
                        We use a <textarea> so the user can scroll and select text.
                        h() escapes the content to prevent XSS.
                    -->
                    <textarea readonly><?= h($content) ?></textarea>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
        // Show the spinner and disable the button while the form is submitting,
        // so the user knows something is happening.
        document.getElementById('uploadForm').addEventListener('submit', function () {
            document.getElementById('submitBtn').disabled = true;
            document.getElementById('spinner').classList.add('visible');
        });
    </script>

</body>
</html>

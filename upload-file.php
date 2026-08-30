<?php
// ============================================================
// upload-file.php — File / Image upload, text extraction,
//                   persistent storage and history recording
//
// Supported formats : .txt  .pdf  .jpg  .jpeg  .png
// Max file size      : 35 MB  (enforced in JS AND PHP)
//
// Flow:
//   1. User picks a file and clicks "Upload & Extract"
//   2. JS validates size before the form submits (frontend)
//   3. PHP validates size again on arrival (backend)
//   4. PHP copies file from PHP temp dir → uploads/ with a
//      unique UUID-based filename so originals never overwrite
//   5. PHP forwards the file to the Azure Function /api/read-file
//      which extracts text using PdfPig / Tesseract OCR
//   6. PHP saves a record to SQLite (metadata + extracted text)
//   7. PHP displays the extracted text
//   8. If DB save fails, PHP shows an explicit warning — it never
//      silently claims the record was saved when it was not
// ============================================================

require_once 'config.php';
require_once 'database.php';
require_login();

// MAX_UPLOAD_BYTES (35 MB) and UPLOADS_DIR are defined in database.php
$ALLOWED_EXTS = ['txt', 'pdf', 'jpg', 'jpeg', 'png'];

$error   = '';      // shown in red alert
$warning = '';      // shown in yellow — extraction ok but DB save failed
$success = '';      // shown in green
$content = '';      // extracted text from the backend
$savedId = null;    // DB row id on successful save

// ---- Handle POST -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Step 1: check PHP received the file cleanly ----------
    if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select a file before clicking Upload.';

    } elseif ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE ||
              $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
        $error = 'File size cannot exceed 35 MB.';

    } elseif ($_FILES['file']['error'] !== UPLOAD_ERR_OK) {
        $error = 'Upload error (code ' . (int)$_FILES['file']['error'] . '). Please try again.';

    } else {
        $tmpPath  = $_FILES['file']['tmp_name'];
        $origName = $_FILES['file']['name'];
        $fileSize = (int) $_FILES['file']['size'];
        $ext      = strtolower(pathinfo($origName, PATHINFO_EXTENSION));

        // --- Step 2: backend validations ----------------------
        if (!in_array($ext, $ALLOWED_EXTS, true)) {
            $error = 'Unsupported file type ".' . h($ext) . '". '
                   . 'Allowed: .txt, .pdf, .jpg, .jpeg, .png.';

        } elseif ($fileSize === 0) {
            $error = 'The selected file is empty.';

        } elseif ($fileSize > MAX_UPLOAD_BYTES) {
            // Backend re-check — catches any bypass of the JS check
            $error = 'File size cannot exceed 35 MB. '
                   . 'Your file is ' . format_file_size($fileSize) . '.';

        } else {
            // --- Step 3: save to uploads/ with a unique name ---
            $storedName = generate_stored_filename($origName);
            $storedPath = UPLOADS_DIR . $storedName;   // absolute path
            $relPath    = 'uploads/' . $storedName;    // relative, stored in DB

            if (!move_uploaded_file($tmpPath, $storedPath)) {
                $error = 'Failed to save the uploaded file to disk. '
                       . 'Check that the uploads/ directory is writable.';
            } else {
                // --- Step 4: call the Azure Function ----------
                $mimeType = get_mime_type($ext);
                $result   = call_api_multipart(
                    '/read-file',
                    $storedPath,   // read from the saved copy
                    $origName,
                    $mimeType,
                    30
                );

                $returnedName = $result['fileName'] ?? $origName;
                $extractOk    = !empty($result['success']) && $result['success'] === true;

                if ($extractOk) {
                    $content = $result['content'] ?? '';
                }

                // --- Step 5: save record to SQLite ------------
                // We save the record regardless of extraction success
                // so every upload attempt appears in history.
                $dbError = '';
                try {
                    $savedId = db_insert_upload([
                        'username'       => $_SESSION['username'],
                        'original_name'  => $origName,
                        'stored_name'    => $storedName,
                        'file_type'      => strtoupper($ext),
                        'file_size'      => $fileSize,
                        'file_path'      => $relPath,
                        'extracted_text' => $extractOk ? $content : '',
                        'uploaded_at'    => date('Y-m-d H:i:s'),
                    ]);
                } catch (Exception $e) {
                    $dbError = $e->getMessage();
                }

                // --- Step 6: set user-facing messages ---------
                if (!$extractOk) {
                    // Extraction failed
                    $error = $result['message']
                           ?? 'The backend could not extract content from this file.';
                } elseif ($dbError !== '') {
                    // Extraction succeeded but DB save failed — show warning
                    $warning = 'Text was extracted successfully, but the upload '
                             . 'could not be saved to history. Database error: '
                             . h($dbError);
                    $success = 'Content extracted from "' . h($origName) . '".';
                } else {
                    $success = 'Content extracted and saved to history. '
                             . '<a href="upload-details.php?id=' . $savedId . '">View record</a>';
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
    <title>File Upload — LoginSample</title>
    <link rel="stylesheet" href="css/style.css">
</head>
<body>

    <div class="navbar">
        <span class="brand">🔐 LoginSample</span>
        <nav>
            <a href="dashboard.php">Dashboard</a>
            <a href="upload-file.php" class="active">File Upload</a>
            <a href="upload-history.php">History</a>
            <a href="audio.php">Audio</a>
            <a href="logout.php">Logout</a>
        </nav>
    </div>

    <div class="page-wrapper">
        <div class="card">
            <h1>📄 File / Image Upload</h1>

            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= h($error) ?></div>
            <?php endif; ?>

            <?php if ($warning !== ''): ?>
                <div class="alert alert-warning"><?= $warning ?></div>
            <?php endif; ?>

            <?php if ($success !== ''): ?>
                <div class="alert alert-success"><?= $success ?></div>
            <?php endif; ?>

            <form method="post" action="upload-file.php"
                  enctype="multipart/form-data" id="uploadForm">

                <div class="form-group">
                    <label for="file">Select a file</label>
                    <input type="file" id="file" name="file"
                           accept=".txt,.pdf,.jpg,.jpeg,.png" required>
                    <small>
                        Supported: .txt · .pdf · .jpg · .jpeg · .png
                        &nbsp;|&nbsp; Max size: <strong>35 MB</strong>
                    </small>
                </div>

                <div class="mt-2">
                    <button type="submit" class="btn btn-primary" id="submitBtn">
                        Upload &amp; Extract
                    </button>
                    <a href="upload-history.php" class="btn btn-secondary"
                       style="margin-left:.5rem">View History</a>
                </div>

                <div class="spinner" id="spinner">
                    ⏳ Extracting content, please wait…
                </div>

            </form>

            <?php if ($content !== ''): ?>
                <div class="result-box">
                    <h2>Extracted Content</h2>
                    <textarea readonly><?= h($content) ?></textarea>
                </div>
            <?php endif; ?>

        </div>
    </div>

    <script>
    // ---- Frontend file size validation (35 MB = 35 * 1024 * 1024) ----
    // This runs before the form submits so the user gets instant feedback.
    // The PHP backend re-validates the same limit — never trust only JS.
    const MAX_BYTES = <?= MAX_UPLOAD_BYTES ?>;

    document.getElementById('uploadForm').addEventListener('submit', function (e) {
        const fileInput = document.getElementById('file');

        if (fileInput.files.length > 0) {
            const fileSize = fileInput.files[0].size;

            if (fileSize > MAX_BYTES) {
                e.preventDefault(); // stop the form from submitting
                alert('File size cannot exceed 35 MB.\n'
                    + 'Your file is '
                    + (fileSize / (1024 * 1024)).toFixed(1)
                    + ' MB.');
                return;
            }
        }

        // File is within limit — show spinner and disable button
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('spinner').classList.add('visible');
    });
    </script>

</body>
</html>

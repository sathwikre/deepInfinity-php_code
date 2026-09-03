<?php
// ============================================================
// upload-file.php — File / Image upload, text extraction,
//                   persistent storage and history recording
//
// This page is SELF-CONTAINED — no Azure Function or external
// API is called. All processing happens in PHP locally.
//
// Supported formats : .txt  .pdf  .jpg  .jpeg  .png
// Max file size      : 35 MB  (enforced in JS AND PHP backend)
//
// Flow:
//   1. User picks a file and clicks "Upload & Extract"
//   2. JS validates size before form submits (frontend check)
//   3. PHP re-validates size on the server (backend check)
//   4. PHP copies file from PHP temp dir → uploads/ with a
//      unique UUID-based filename (no overwrites possible)
//   5. PHP extracts text locally via extract_text_local()
//      defined in config.php:
//        .txt  → file_get_contents()
//        .pdf  → raw PDF stream parser
//        .jpg/.jpeg/.png → image metadata + Tesseract if available
//   6. PHP saves metadata + extracted text to SQLite
//   7. PHP displays the extracted text on the page
//   8. File appears in Upload History immediately
// ============================================================

require_once 'config.php';
require_once 'database.php';
require_login();

// MAX_UPLOAD_BYTES (35 MB) and UPLOADS_DIR defined in database.php
$ALLOWED_EXTS = ['txt', 'pdf', 'jpg', 'jpeg', 'png'];

$error   = '';   // shown in red
$warning = '';   // shown in amber — file saved but DB failed
$success = '';   // shown in green
$content = '';   // extracted text to display
$savedId = null; // DB row id on successful save

// ---- Handle POST -------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {

    // --- Step 1: check PHP received the file cleanly ----------
    if (empty($_FILES['file']) || $_FILES['file']['error'] === UPLOAD_ERR_NO_FILE) {
        $error = 'Please select a file before clicking Upload.';

    } elseif ($_FILES['file']['error'] === UPLOAD_ERR_INI_SIZE ||
              $_FILES['file']['error'] === UPLOAD_ERR_FORM_SIZE) {
        $error = 'File exceeds the server upload limit. Maximum allowed size is 35 MB.';

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
            $error = 'The selected file is empty (0 bytes).';

        } elseif ($fileSize > MAX_UPLOAD_BYTES) {
            // Server-side re-check — catches any bypass of the JS check
            $error = 'File size cannot exceed 35 MB. '
                   . 'Your file is ' . format_file_size($fileSize) . '.';

        } else {
            // --- Step 3: save to uploads/ with a unique name --
            // generate_stored_filename() in database.php creates:
            //   {32-hex-chars}-{sanitised-original-name}
            // so two files named "notes.pdf" get different names.
            $storedName = generate_stored_filename($origName);
            $storedPath = UPLOADS_DIR . $storedName;  // absolute path on disk
            $relPath    = 'uploads/' . $storedName;   // relative path stored in DB

            if (!is_dir(UPLOADS_DIR)) {
                mkdir(UPLOADS_DIR, 0755, true);
            }

            if (!move_uploaded_file($tmpPath, $storedPath)) {
                $error = 'Failed to save the uploaded file. '
                       . 'Check that the uploads/ directory is writable by Apache.';
            } else {
                // --- Step 4: extract text locally -------------
                // extract_text_local() is defined in config.php.
                // No external API call — everything runs in PHP.
                $result    = extract_text_local($storedPath, $ext);
                $extractOk = !empty($result['success']) && $result['success'] === true;

                if ($extractOk) {
                    $content = $result['content'] ?? '';
                }

                // --- Step 5: save record to SQLite ------------
                // Always save — even if extraction produced no text —
                // so every upload appears in history.
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

                // --- Step 6: user-facing messages -------------
                if (!$extractOk) {
                    // Extraction reported failure (unlikely with local extractor,
                    // but handle it cleanly anyway)
                    $extractMsg = $result['message'] ?? 'Could not extract text from this file.';
                    if ($dbError === '') {
                        // File saved and DB record created even without text
                        $warning = h($extractMsg)
                                 . ' The file was saved and appears in history.';
                        $success = 'File uploaded. '
                                 . '<a href="upload-details.php?id=' . $savedId . '">View record</a>';
                    } else {
                        $error = h($extractMsg);
                    }
                } elseif ($dbError !== '') {
                    // Text extracted but DB save failed — warn explicitly
                    $warning = 'Text was extracted successfully but the upload could not be '
                             . 'saved to history. Database error: ' . h($dbError);
                    $success = 'Content extracted from "' . h($origName) . '".';
                } else {
                    $success = 'File uploaded and content extracted. '
                             . '<a href="upload-details.php?id=' . $savedId . '">View record</a> &nbsp;|&nbsp; '
                             . '<a href="upload-history.php">Upload History</a>';
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

            <!-- php.ini note: upload_max_filesize and post_max_size must be >= 35M -->
            <?php if ($error !== ''): ?>
                <div class="alert alert-error"><?= $error ?></div>
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
                        Supported: .txt &middot; .pdf &middot; .jpg &middot; .jpeg &middot; .png
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
                    ⏳ Processing file, please wait…
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
    // ---- Frontend file-size guard (35 MB = 35 * 1024 * 1024 bytes) ----
    // Gives instant feedback before the browser even starts uploading.
    // PHP re-validates the same limit server-side — JS can be bypassed
    // but PHP cannot.
    const MAX_BYTES = <?= MAX_UPLOAD_BYTES ?>;

    document.getElementById('uploadForm').addEventListener('submit', function (e) {
        const fileInput = document.getElementById('file');

        if (fileInput.files.length > 0) {
            const size = fileInput.files[0].size;
            if (size > MAX_BYTES) {
                e.preventDefault();
                alert(
                    'File size cannot exceed 35 MB.\n' +
                    'Your file is ' + (size / (1024 * 1024)).toFixed(1) + ' MB.'
                );
                return;
            }
        }

        // Within limit — show spinner and disable button to prevent double-submit
        document.getElementById('submitBtn').disabled = true;
        document.getElementById('submitBtn').textContent = 'Uploading…';
        document.getElementById('spinner').classList.add('visible');
    });
    </script>

</body>
</html>

<?php
// ============================================================
// config.php — shared configuration and helper functions
//
// This PHP application is SELF-CONTAINED.
// It does NOT depend on any external API, Azure Function,
// C# project, or .NET backend.
//
// All processing (login, text extraction, file storage,
// database) happens entirely within this PHP project.
// ============================================================

// ------------------------------------------------------------
// Local credentials
// Simple hardcoded credentials for the single-user app.
// To add more users in the future, replace this with a
// database-backed users table.
// ------------------------------------------------------------
define('LOCAL_USERNAME', 'admin');
define('LOCAL_PASSWORD', '1234');

// ------------------------------------------------------------
// Session helper
// Call require_login() at the top of any protected page.
// ------------------------------------------------------------
function require_login(): void
{
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }
    if (empty($_SESSION['username'])) {
        header('Location: login.php');
        exit;
    }
}

// ------------------------------------------------------------
// local_check_login(string $username, string $password): array
//
// Validates credentials locally — no external API needed.
// Returns ['success' => true/false, 'message' => '...']
// ------------------------------------------------------------
function local_check_login(string $username, string $password): array
{
    if ($username === LOCAL_USERNAME && $password === LOCAL_PASSWORD) {
        return ['success' => true, 'message' => 'Login Successful'];
    }
    return ['success' => false, 'message' => 'Invalid username or password.'];
}

// ------------------------------------------------------------
// extract_text_local(string $filePath, string $ext): array
//
// Extracts text from an uploaded file entirely in PHP —
// no external API or backend needed.
//
// Supported:
//   .txt  — reads the file directly
//   .pdf  — extracts raw text streams from the PDF binary
//   .jpg / .jpeg / .png — uses PHP GD + OCR note
//
// Returns:
//   ['success' => true,  'content' => '...']
//   ['success' => false, 'message' => '...']
// ------------------------------------------------------------
function extract_text_local(string $filePath, string $ext): array
{
    $ext = strtolower($ext);

    switch ($ext) {

        // ---- Plain text: just read the file ----------------
        case 'txt':
            $text = file_get_contents($filePath);
            if ($text === false) {
                return ['success' => false, 'message' => 'Could not read the text file.'];
            }
            return ['success' => true, 'content' => $text];

        // ---- PDF: extract text streams from the binary -----
        case 'pdf':
            $content = extract_text_from_pdf($filePath);
            return ['success' => true, 'content' => $content];

        // ---- Images: store a note (no OCR library bundled) -
        case 'jpg':
        case 'jpeg':
        case 'png':
            $content = extract_text_from_image($filePath, $ext);
            return ['success' => true, 'content' => $content];

        default:
            return ['success' => false, 'message' => 'Unsupported file type for extraction.'];
    }
}

// ------------------------------------------------------------
// extract_text_from_pdf(string $filePath): string
//
// Parses the raw PDF binary to pull out readable text streams.
// This covers the vast majority of machine-generated PDFs
// (reports, invoices, documents created by Word/LibreOffice).
//
// Scanned PDFs (pure images inside a PDF) will return a
// placeholder message — full OCR requires an external library.
// ------------------------------------------------------------
function extract_text_from_pdf(string $filePath): string
{
    $raw = file_get_contents($filePath);
    if ($raw === false || $raw === '') {
        return '[Could not read PDF file]';
    }

    $text = '';

    // ---- Strategy 1: extract BT...ET text blocks -----------
    // In PDF format, text drawing operators are wrapped in
    // BT (Begin Text) ... ET (End Text) blocks.
    // Tj and TJ are the two text-showing operators.
    preg_match_all('/BT\s*(.*?)\s*ET/s', $raw, $btBlocks);
    if (!empty($btBlocks[1])) {
        foreach ($btBlocks[1] as $block) {
            // Match string literals: (text) Tj  or [(text)] TJ
            preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $block, $tj);
            foreach ($tj[1] as $t) {
                $text .= pdf_decode_string($t) . ' ';
            }
            preg_match_all('/\(([^)]*)\)/s', $block, $array_tj);
            if (!empty($array_tj[1])) {
                // Only add if there's a TJ operator nearby
                if (strpos($block, 'TJ') !== false || strpos($block, 'Tj') !== false) {
                    foreach ($array_tj[1] as $t) {
                        $piece = pdf_decode_string($t);
                        if (trim($piece) !== '') {
                            $text .= $piece . ' ';
                        }
                    }
                }
            }
        }
    }

    // ---- Strategy 2: extract /Contents stream objects ------
    // Decompress zlib-compressed content streams if zlib is available.
    if (function_exists('gzuncompress') || function_exists('gzinflate')) {
        preg_match_all('/stream\r?\n(.*?)\r?\nendstream/s', $raw, $streams);
        foreach ($streams[1] as $stream) {
            $decoded = @gzuncompress($stream);
            if ($decoded === false) {
                $decoded = @gzinflate(substr($stream, 2));
            }
            if ($decoded !== false && $decoded !== '') {
                preg_match_all('/\(((?:[^()\\\\]|\\\\.)*)\)\s*Tj/s', $decoded, $m);
                foreach ($m[1] as $t) {
                    $text .= pdf_decode_string($t) . ' ';
                }
            }
        }
    }

    // Clean up the extracted text
    $text = preg_replace('/\s+/', ' ', $text);
    $text = trim($text);

    if ($text === '') {
        return '[No extractable text found in this PDF. '
             . 'It may be a scanned document (image-based PDF). '
             . 'The file has been saved and can still be downloaded.]';
    }

    return $text;
}

// ------------------------------------------------------------
// pdf_decode_string(string $raw): string
//
// Decodes PDF escape sequences inside a string literal.
// e.g. \n → newline, \( → (, octal \123 → chr(0123)
// ------------------------------------------------------------
function pdf_decode_string(string $raw): string
{
    $result = '';
    $len    = strlen($raw);
    $i      = 0;

    while ($i < $len) {
        if ($raw[$i] === '\\' && ($i + 1) < $len) {
            $next = $raw[$i + 1];
            switch ($next) {
                case 'n':  $result .= "\n"; $i += 2; break;
                case 'r':  $result .= "\r"; $i += 2; break;
                case 't':  $result .= "\t"; $i += 2; break;
                case '(':  $result .= '(';  $i += 2; break;
                case ')':  $result .= ')';  $i += 2; break;
                case '\\': $result .= '\\'; $i += 2; break;
                default:
                    // Octal escape \ddd
                    if (ctype_digit($next)) {
                        $oct = '';
                        for ($j = 1; $j <= 3 && ($i + $j) < $len && ctype_digit($raw[$i + $j]); $j++) {
                            $oct .= $raw[$i + $j];
                        }
                        $result .= chr(octdec($oct));
                        $i += 1 + strlen($oct);
                    } else {
                        $result .= $next;
                        $i += 2;
                    }
                    break;
            }
        } else {
            $result .= $raw[$i];
            $i++;
        }
    }

    // Filter out non-printable characters (keep newlines/tabs)
    $result = preg_replace('/[^\x09\x0A\x0D\x20-\x7E\xA0-\xFF]/', '', $result);
    return $result;
}

// ------------------------------------------------------------
// extract_text_from_image(string $filePath, string $ext): string
//
// For images we note the image dimensions and metadata.
// Full OCR would require Tesseract as a system command or
// an external PHP extension — neither is bundled here.
// The file is still saved and downloadable.
// ------------------------------------------------------------
function extract_text_from_image(string $filePath, string $ext): string
{
    $info = '';

    if (function_exists('getimagesize')) {
        $size = @getimagesize($filePath);
        if ($size !== false) {
            $info = sprintf(
                "[Image file: %s | Dimensions: %d × %d px | MIME: %s]\n\n",
                strtoupper($ext),
                $size[0],
                $size[1],
                $size['mime'] ?? 'unknown'
            );
        }
    }

    // Attempt system Tesseract if available
    $tesseractAvailable = false;
    if (function_exists('exec')) {
        exec('tesseract --version 2>&1', $out, $ret);
        $tesseractAvailable = ($ret === 0);
    }

    if ($tesseractAvailable) {
        // Create a temp output file
        $tmpBase = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ocr_' . uniqid();
        $cmd     = sprintf(
            'tesseract %s %s -l eng 2>&1',
            escapeshellarg($filePath),
            escapeshellarg($tmpBase)
        );
        exec($cmd, $ocrOut, $ocrRet);
        $txtFile = $tmpBase . '.txt';
        if ($ocrRet === 0 && file_exists($txtFile)) {
            $ocrText = file_get_contents($txtFile);
            @unlink($txtFile);
            if ($ocrText !== false && trim($ocrText) !== '') {
                return $info . $ocrText;
            }
        }
    }

    return $info
         . '[Text extraction for images requires Tesseract OCR to be installed and on your system PATH. '
         . 'The image file has been saved successfully and can be downloaded from Upload History. '
         . 'Install Tesseract from https://github.com/tesseract-ocr/tesseract and restart Apache to enable OCR.]';
}

// ------------------------------------------------------------
// get_mime_type($extension)
//
// Maps a file extension to the correct MIME type string.
// ------------------------------------------------------------
function get_mime_type(string $extension): string
{
    return match (strtolower($extension)) {
        'pdf'         => 'application/pdf',
        'txt'         => 'text/plain',
        'jpg', 'jpeg' => 'image/jpeg',
        'png'         => 'image/png',
        'wav'         => 'audio/wav',
        'mp3'         => 'audio/mpeg',
        default       => 'application/octet-stream',
    };
}

// ------------------------------------------------------------
// h($string)
//
// Shorthand for htmlspecialchars() — always use this before
// printing any user-supplied text into HTML to prevent XSS.
// ------------------------------------------------------------
function h(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

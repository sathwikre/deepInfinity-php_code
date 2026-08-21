<?php
// ============================================================
// config.php — shared configuration and helper functions
// Include this file at the top of every page.
// ============================================================

// ------------------------------------------------------------
// Backend URL
// This is the Azure Function running locally.
// Change only this line if you run the Function on a
// different port or machine.
// ------------------------------------------------------------
define('API_BASE_URL', 'http://localhost:7071/api');

// ------------------------------------------------------------
// Session helper
// Call require_login() at the top of any protected page.
// It checks that the user has logged in; if not, it sends
// them back to login.php.
// ------------------------------------------------------------
function require_login(): void
{
    // Start the session if it hasn't been started yet.
    if (session_status() === PHP_SESSION_NONE) {
        session_start();
    }

    // If the 'username' key is missing from the session,
    // the user is not logged in — redirect them.
    if (empty($_SESSION['username'])) {
        header('Location: login.php');
        exit;
    }
}

// ------------------------------------------------------------
// call_api_json($endpoint, $jsonBody)
//
// Sends a POST request with a JSON body to the Azure Function.
// Used only by login.php.
//
// Returns an associative array decoded from the JSON response,
// or ['success' => false, 'message' => '...'] on failure.
// ------------------------------------------------------------
function call_api_json(string $endpoint, array $data): array
{
    $url  = API_BASE_URL . $endpoint;
    $body = json_encode($data);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     $body);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        15);   // 15 second timeout for login
    curl_setopt($ch, CURLOPT_HTTPHEADER,     [
        'Content-Type: application/json',
        'Content-Length: ' . strlen($body),
    ]);

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    // If curl itself failed (e.g. function host not running)
    if ($response === false) {
        return [
            'success' => false,
            'message' => 'Could not reach the backend. Is the Azure Function running? Error: ' . $error,
        ];
    }

    $decoded = json_decode($response, true);

    // If the response was not valid JSON
    if ($decoded === null) {
        return [
            'success' => false,
            'message' => 'The backend returned an unexpected response.',
        ];
    }

    return $decoded;
}

// ------------------------------------------------------------
// call_api_multipart($endpoint, $filePath, $fileName, $mimeType)
//
// Sends a POST request with multipart/form-data to the
// Azure Function. Used by upload-file.php and audio.php.
//
// $filePath  — full path to the temp file PHP saved
// $fileName  — original name of the file the user chose
// $mimeType  — MIME type string, e.g. "application/pdf"
// $timeout   — curl timeout in seconds (default 30, audio uses 600)
//
// Returns decoded JSON array or error array.
// ------------------------------------------------------------
function call_api_multipart(
    string $endpoint,
    string $filePath,
    string $fileName,
    string $mimeType,
    int    $timeout = 30
): array {
    $url = API_BASE_URL . $endpoint;

    // CURLFile tells curl to attach the file as a multipart part.
    // The field name 'file' matches what the Azure Function expects.
    $cfile = new CURLFile($filePath, $mimeType, $fileName);

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_POST,           true);
    curl_setopt($ch, CURLOPT_POSTFIELDS,     ['file' => $cfile]);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT,        $timeout);
    // Do NOT set Content-Type manually — curl sets it automatically
    // with the correct boundary when POSTFIELDS is an array.

    $response = curl_exec($ch);
    $error    = curl_error($ch);
    curl_close($ch);

    if ($response === false) {
        return [
            'success' => false,
            'message' => 'Could not reach the backend. Is the Azure Function running? Error: ' . $error,
        ];
    }

    $decoded = json_decode($response, true);

    if ($decoded === null) {
        return [
            'success' => false,
            'message' => 'The backend returned an unexpected response.',
        ];
    }

    return $decoded;
}

// ------------------------------------------------------------
// get_mime_type($extension)
//
// Maps a file extension to the correct MIME type string that
// the Azure Function expects in the Content-Type header of
// the multipart part.
// ------------------------------------------------------------
function get_mime_type(string $extension): string
{
    return match (strtolower($extension)) {
        'pdf'        => 'application/pdf',
        'txt'        => 'text/plain',
        'jpg', 'jpeg'=> 'image/jpeg',
        'png'        => 'image/png',
        'wav'        => 'audio/wav',
        'mp3'        => 'audio/mpeg',
        default      => 'application/octet-stream',
    };
}

// ------------------------------------------------------------
// h($string)
//
// Shorthand for htmlspecialchars() — always use this before
// printing any user-supplied or server-supplied text into HTML
// to prevent XSS.
// ------------------------------------------------------------
function h(string $string): string
{
    return htmlspecialchars($string, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

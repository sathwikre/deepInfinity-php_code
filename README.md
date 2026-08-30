# LoginSample — PHP + .NET Web Application

A local-only web application with a **PHP frontend** (served by Apache/XAMPP) and a **.NET Isolated Azure Functions backend**. No cloud deployment is required — everything runs on your machine.

---

## Table of Contents

1. [Project Overview](#1-project-overview)
2. [Technologies Used](#2-technologies-used)
3. [Architecture](#3-architecture)
4. [Project Folder Structure](#4-project-folder-structure)
5. [Prerequisites](#5-prerequisites)
6. [Installation and Setup](#6-installation-and-setup)
7. [Configuration](#7-configuration)
8. [How to Run Locally](#8-how-to-run-locally)
9. [Features](#9-features)
10. [API Endpoints](#10-api-endpoints)
11. [Database](#11-database)
12. [Step-by-Step Testing](#12-step-by-step-testing)
13. [Important Notes and Limitations](#13-important-notes-and-limitations)

---

## 1. Project Overview

LoginSample is an internship project that allows a user to:

- Log in with a username and password
- Upload a file (PDF, image, or text) and extract its text content
- View the full history of uploaded files including extracted text
- Upload an audio file and have it transcribed to text locally using OpenAI Whisper

All processing happens locally. No data is sent to any cloud service.

---

## 2. Technologies Used

| Layer | Technology |
|---|---|
| Web frontend | PHP 8.0 served by Apache (XAMPP) |
| Styles | Plain CSS (no framework) |
| Frontend validation | Vanilla JavaScript |
| Backend API | C# / .NET 8 Isolated Azure Functions v4 |
| PDF extraction | PdfPig (NuGet) |
| OCR for images | Tesseract 5 (NuGet) |
| Audio transcription | OpenAI Whisper (Python 3.11, local) |
| Audio decoding | FFmpeg (local, on PATH) |
| Database | SQLite via PHP PDO (`pdo_sqlite` extension) |
| File storage | Local filesystem (`uploads/` directory) |

---

## 3. Architecture

```
Browser
  │
  │  HTTP (form POST or page load)
  ▼
Apache / XAMPP  — PHP pages at localhost/loginsample/
  │
  │  cURL HTTP POST (JSON or multipart/form-data)
  ▼
Azure Function host — localhost:7071
  ├── POST /api/login            ← JSON { username, password }
  ├── POST /api/read-file        ← multipart, field name "file"
  └── POST /api/transcribe-audio ← multipart, field name "file"
                                        │
                                        └── Python subprocess
                                            whisper_transcribe.py
                                            (audio → transcript)

PHP also writes directly to:
  ├── SQLite database  → data/uploads.db   (upload history)
  └── Local filesystem → uploads/          (stored files)
```

**How PHP and .NET communicate:**
PHP uses cURL (`call_api_json` for login, `call_api_multipart` for files) to POST to the Azure Function. The Function returns a JSON response. PHP reads the JSON and either renders the result or saves it to SQLite. The Azure Function has no knowledge of PHP or SQLite — it only receives HTTP requests and returns JSON.

---

## 4. Project Folder Structure

```
C:\xampp\htdocs\loginsample\        ← PHP web root (served by Apache)
│
├── config.php                      ← API base URL, cURL helpers, session guard
├── database.php                    ← SQLite connection, table setup, DB helpers
├── login.php                       ← Login form → calls /api/login
├── dashboard.php                   ← Dashboard with 3 feature cards
├── upload-file.php                 ← File upload, extraction, DB save
├── upload-history.php              ← List all uploads for logged-in user
├── upload-details.php              ← View metadata + extracted text for one upload
├── download.php                    ← Safely stream stored file to browser
├── audio.php                       ← Audio upload → calls /api/transcribe-audio
├── logout.php                      ← Destroys session, redirects to login
│
├── css/
│   └── style.css                   ← Shared stylesheet for all pages
│
├── data/
│   └── uploads.db                  ← SQLite database (auto-created on first use)
│
└── uploads/                        ← Stored uploaded files (UUID-prefixed names)
    ├── a3f2c1d0...-notes.pdf
    ├── 7b8e9f2a...-photo.png
    └── ...

c:\deep\.net\LoginSample\           ← .NET solution root
│
├── LoginFunction\                  ← Azure Functions backend
│   ├── Functions\
│   │   ├── LoginFunction.cs        ← POST /api/login
│   │   ├── ReadFileFunction.cs     ← POST /api/read-file
│   │   └── TranscribeAudioFunction.cs  ← POST /api/transcribe-audio
│   ├── Helpers\
│   │   └── MultipartFormHelper.cs  ← Parses multipart/form-data
│   ├── Models\
│   │   ├── LoginRequest.cs / LoginResponse.cs
│   │   ├── FileReadResponse.cs
│   │   └── AudioTranscriptionResponse.cs
│   ├── whisper_transcribe.py       ← Python script for Whisper transcription
│   ├── Program.cs
│   ├── host.json
│   ├── local.settings.json         ← Local config (Whisper model, Python path)
│   └── LoginFunction.csproj
│
└── LoginClient\                    ← Original WPF desktop app (still functional)
    └── ...
```

---

## 5. Prerequisites

Install all of the following before running the project.

### 5.1 XAMPP (PHP + Apache)
- Already installed at `C:\xampp`
- PHP 8.0.30, Apache included
- The `pdo_sqlite` extension must be enabled (it is enabled by default in XAMPP 8.x)

### 5.2 .NET 8 SDK
```
https://dotnet.microsoft.com/download
```

### 5.3 Azure Functions Core Tools v4
```powershell
npm install -g azure-functions-core-tools@4 --unsafe-perm true
```

### 5.4 Python 3.11
Required for audio transcription only.
```powershell
# Install the Whisper library for Python 3.11
py -3.11 -m pip install openai-whisper
```
On first transcription Whisper downloads the model weights (~75 MB for `tiny`) to `~/.cache/whisper`.

### 5.5 FFmpeg
Whisper uses FFmpeg to decode MP3 files. FFmpeg must be on your system `PATH`.
- Download the **essentials** build from: https://www.gyan.dev/ffmpeg/builds/
- Extract and add the `bin` folder to your system `PATH`
- Verify: `ffmpeg -version`

---

## 6. Installation and Setup

### 6.1 PHP application
The PHP files are already in place at `C:\xampp\htdocs\loginsample\`.

The `data/` and `uploads/` directories must exist and be writable by Apache:
```powershell
New-Item -ItemType Directory -Force "C:\xampp\htdocs\loginsample\data"
New-Item -ItemType Directory -Force "C:\xampp\htdocs\loginsample\uploads"
```
The SQLite database file (`data/uploads.db`) is **created automatically** the first time any page that includes `database.php` is loaded. No manual database setup is needed.

### 6.2 .NET Function
```powershell
cd c:\deep\.net\LoginSample
dotnet build
```
This also copies `whisper_transcribe.py` to the function output directory.

---

## 7. Configuration

### PHP — `config.php`
```php
define('API_BASE_URL', 'http://localhost:7071/api');
```
Change this only if the Azure Function runs on a different port.

### PHP — `database.php`
```php
define('DB_PATH',      __DIR__ . '/data/uploads.db');  // SQLite file location
define('UPLOADS_DIR',  __DIR__ . '/uploads/');          // stored files location
define('MAX_UPLOAD_BYTES', 35 * 1024 * 1024);           // 35 MB limit
```

### .NET — `LoginFunction/local.settings.json`
```json
{
  "Values": {
    "WHISPER_PYTHON_EXE": "py",
    "WHISPER_MODEL": "tiny"
  }
}
```

| Setting | Default | Description |
|---|---|---|
| `WHISPER_PYTHON_EXE` | `py` | Python launcher. Set to a full path if `py` is not on PATH |
| `WHISPER_MODEL` | `tiny` | Whisper model: `tiny` · `base` · `small` · `medium` · `large` |

### php.ini upload limits
`C:\xampp\php\php.ini` is already configured with:
```
upload_max_filesize = 64M
post_max_size       = 64M
```
These are larger than the application's own 35 MB limit, so no changes are needed.

---

## 8. How to Run Locally

You need **two things running** at the same time.

### Step 1 — Build the .NET project
```powershell
cd c:\deep\.net\LoginSample
dotnet build
```

### Step 2 — Start the Azure Function backend
```powershell
cd c:\deep\.net\LoginSample\LoginFunction
func start
```
Wait until you see all three endpoints:
```
Functions:
    Login:            [POST] http://localhost:7071/api/login
    ReadFile:         [POST] http://localhost:7071/api/read-file
    TranscribeAudio:  [POST] http://localhost:7071/api/transcribe-audio
```
Leave this terminal open.

### Step 3 — Start Apache

**Option A — XAMPP Control Panel (recommended):**
1. Open `C:\xampp\xampp-control.exe`
2. Click **Start** next to **Apache**
3. The row turns green — Apache is running on port 80

**Option B — Command line:**
```powershell
C:\xampp\apache\bin\httpd.exe
```

Verify Apache is running: open http://localhost — you should see the XAMPP welcome page.

### Step 4 — Open the application
```
http://localhost/loginsample/login.php
```
Login with `admin` / `1234`.

### If Apache won't start (port 80 conflict)
```powershell
netstat -ano | findstr :80
```
If port 80 is in use, open `C:\xampp\apache\conf\httpd.conf`, change `Listen 80` to `Listen 8080`, restart Apache, then use `http://localhost:8080/loginsample/login.php`.

---

## 9. Features

### Login
- Username: `admin`, Password: `1234`
- Credentials are validated by the Azure Function
- Session is stored in a PHP cookie — protected pages redirect to login if session is missing
- Logout destroys the session completely

### File / Image Upload
- Supported formats: `.txt`, `.pdf`, `.jpg`, `.jpeg`, `.png`
- Maximum file size: **35 MB** (enforced by JavaScript before upload AND by PHP on the server)
- The file is saved permanently to `uploads/` with a UUID-based unique filename
- Text is extracted by the Azure Function (PdfPig for PDF, Tesseract OCR for images, StreamReader for TXT)
- The extracted text and file metadata are saved to the SQLite database
- If extraction succeeds but the database save fails, an explicit warning is shown — the system never silently claims a record was saved when it was not

### Upload History
- Every successful upload appears in the history list
- History is user-specific — only uploads made by the logged-in user are shown
- Uploads are sorted newest first
- Each row shows: file name, type badge, size, upload date/time, and a View button

### Upload Details
- Shows full metadata: file name, type, size, uploaded by, date/time, stored filename
- Displays the full extracted text saved in the database
- Provides a download button to retrieve the original file if it still exists on disk
- The download goes through `download.php` which re-validates ownership before streaming

### Audio Transcription
- Supported formats: `.mp3`, `.wav`
- Maximum file size: 50 MB
- Transcription is performed locally by OpenAI Whisper (no internet required)
- Audio transcription is not saved to history (file uploads only)

---

## 10. API Endpoints

All endpoints are served by the Azure Function at `http://localhost:7071`.

### POST /api/login
```
Content-Type: application/json
Body: { "username": "admin", "password": "1234" }
```
```json
{ "success": true,  "message": "Login Successful"    }
{ "success": false, "message": "Invalid Credentials" }
```

### POST /api/read-file
```
Content-Type: multipart/form-data
Field name:   file
Accepted:     .txt  .pdf  .jpg  .jpeg  .png
```
```json
{ "success": true,  "fileName": "doc.pdf", "content": "Extracted text...", "message": "" }
{ "success": false, "fileName": "doc.pdf", "content": "",                  "message": "Reason for failure" }
```

### POST /api/transcribe-audio
```
Content-Type: multipart/form-data
Field name:   file
Accepted:     .mp3  .wav
```
```json
{ "success": true,  "fileName": "audio.mp3", "transcript": "Transcribed text...", "message": "" }
{ "success": false, "fileName": "audio.mp3", "transcript": "",                    "message": "Reason for failure" }
```

---

## 11. Database

### Technology
**SQLite** via PHP's `PDO` (`pdo_sqlite` extension).

**Why SQLite:** No separate database server is needed. The entire database is a single file. `pdo_sqlite` is already enabled in this XAMPP installation. It is the simplest option for a local-only single-user project.

### Location
```
C:\xampp\htdocs\loginsample\data\uploads.db
```
Created automatically on the first page load. No manual setup required.

### Table: `uploads`

| Column | Type | Description |
|---|---|---|
| `id` | INTEGER PK AUTOINCREMENT | Unique row identifier |
| `username` | TEXT | Value of `$_SESSION['username']` at upload time |
| `original_name` | TEXT | Filename the user chose, e.g. `notes.pdf` |
| `stored_name` | TEXT | UUID-prefixed filename saved to disk, e.g. `a3f2c1d0...-notes.pdf` |
| `file_type` | TEXT | Extension in uppercase, e.g. `PDF`, `PNG`, `TXT` |
| `file_size` | INTEGER | File size in bytes |
| `file_path` | TEXT | Relative path from app root, e.g. `uploads/a3f2c1d0...-notes.pdf` |
| `extracted_text` | TEXT | Full text returned by the Azure Function |
| `uploaded_at` | TEXT | ISO 8601 datetime string, e.g. `2026-08-12 18:30:00` |

### Example record
```
id:             1
username:       admin
original_name:  notes.pdf
stored_name:    a3f2c1d0e4b56789...-notes.pdf
file_type:      PDF
file_size:      5242880
file_path:      uploads/a3f2c1d0e4b56789...-notes.pdf
extracted_text: The extracted content goes here...
uploaded_at:    2026-08-12 18:30:00
```

### User association
The current login system uses a single hardcoded user (`admin`). The `username` column stores the session username for every upload. If real user accounts are added later, each user will automatically see only their own history — the `WHERE username = :username` filter is already in place in every query.

---

## 12. Step-by-Step Testing

Make sure both Apache and the Azure Function are running before testing.

### Test 1 — Login with valid credentials
1. Open http://localhost/loginsample/login.php
2. Enter `admin` / `1234` → click **Login**
3. ✅ Expected: redirected to dashboard showing 3 cards

### Test 2 — Login with invalid credentials
1. Enter `admin` / `wrongpassword` → click **Login**
2. ✅ Expected: red alert "Invalid Credentials", stays on login page

### Test 3 — Upload a valid file under 35 MB
1. Click **File Upload** on the dashboard
2. Choose any `.txt`, `.pdf`, or image file under 35 MB
3. Click **Upload & Extract**
4. ✅ Expected:
   - Extracted text shown on page
   - Green success message with "View record" link
   - Dashboard History card shows updated count

### Test 4 — Verify upload persists (restart test)
1. Complete Test 3
2. Stop Apache (close the terminal or XAMPP Control Panel)
3. Restart Apache
4. Navigate to http://localhost/loginsample/upload-history.php
5. ✅ Expected: the upload from Test 3 still appears in the list

### Test 5 — View upload details
1. On the history page click **View** next to any upload
2. ✅ Expected: file name, type, size, upload date, and extracted text are displayed
3. Click **Download Original File**
4. ✅ Expected: browser downloads the original file

### Test 6 — Reject a file over 35 MB (JavaScript)
1. On the upload page choose any file larger than 35 MB
2. Click **Upload & Extract**
3. ✅ Expected: browser alert saying "File size cannot exceed 35 MB" — form does NOT submit

### Test 7 — Reject a file over 35 MB (backend bypass simulation)
1. Temporarily disable JavaScript in your browser (DevTools → Settings → Disable JavaScript)
2. Choose a file larger than 35 MB → click Upload
3. ✅ Expected: PHP returns a red alert "File size cannot exceed 35 MB"
4. Re-enable JavaScript

### Test 8 — Upload multiple files with the same original name
1. Upload `notes.pdf`
2. Upload `notes.pdf` again
3. Open Upload History
4. ✅ Expected: two separate rows, each with a different stored filename (UUID prefix differs)
5. Both files download correctly and independently

### Test 9 — Session protection
1. Log out (click Logout in the navbar)
2. Navigate directly to http://localhost/loginsample/dashboard.php
3. ✅ Expected: redirected to login.php

### Test 10 — Audio transcription (existing feature)
1. Log in → click **Audio Transcription**
2. Choose a short `.mp3` or `.wav` file
3. Click **Upload & Transcribe** — wait for Whisper to finish
4. ✅ Expected: transcript text displayed on page

---

## 13. Important Notes and Limitations

### File size limit
The 35 MB limit is enforced in **two places**:
- **JavaScript** (`upload-file.php`) — runs before the form submits, gives instant feedback
- **PHP** (`upload-file.php`) — re-validates on the server, cannot be bypassed by disabling JS

The `php.ini` limits (`upload_max_filesize = 64M`, `post_max_size = 64M`) are intentionally larger than 35 MB so PHP itself never silently truncates a file before the application can validate it.

### File storage
Uploaded files are stored at:
```
C:\xampp\htdocs\loginsample\uploads\
```
Each file is saved with a unique name: `{32-character hex token}-{sanitised original name}`. The original name is never used directly as the stored filename, preventing overwrite collisions and path-traversal issues.

### Database storage
The SQLite file is at:
```
C:\xampp\htdocs\loginsample\data\uploads.db
```
It is created automatically. To reset all history, delete this file. Uploaded files in `uploads/` are not deleted automatically — remove them manually if needed.

### Audio transcription and history
Audio transcriptions are **not saved to the upload history database**. Only file uploads (PDF, image, text) are tracked. Audio transcription is a separate feature that displays the result on-screen only.

### Single user
The application uses a single hardcoded account (`admin` / `1234`). The database stores the `username` column on every upload row so that per-user filtering is already in place. Adding real user accounts in the future requires only adding a users table and a registration/password flow — the history queries will work unchanged.

### Whisper model download
The first audio transcription triggers an automatic download of the Whisper model weights to `~/.cache/whisper`. This requires an internet connection the first time only. Subsequent transcriptions are fully offline.

### AzureWebJobsStorage warnings
The Azure Function log shows `AzureWebJobsStorage` unhealthy warnings. These are safe to ignore — all three functions are HTTP-only triggers and do not use Azure Storage.

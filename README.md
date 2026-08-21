# WPF + PHP Web + Local Azure Functions — Login Sample

A local-only application with **two frontends** sharing one .NET backend:

| Frontend | Technology | Location |
|---|---|---|
| Desktop app | WPF / C# (.NET 8) | `LoginClient/` |
| Web app | PHP 8 + Apache (XAMPP) | `C:\xampp\htdocs\loginsample\` |
| Backend API | .NET Isolated Azure Functions v4 | `LoginFunction/` |

No Azure account or cloud deployment needed — everything runs on your machine.

---

## Features

| Feature | Description |
|---|---|
| Login | Validates credentials (`admin` / `1234`) via the Azure Function |
| File / Image Upload | Extracts text from `.txt`, `.pdf`, `.jpg`, `.jpeg`, `.png` (OCR via Tesseract for images) |
| Audio Transcription | Transcribes `.mp3` and `.wav` using local OpenAI Whisper — no cloud API |

---

## What was built and changed

### Original project
The original project was a **WPF desktop application** (`LoginClient`) talking to a local **Azure Function** backend (`LoginFunction`). It had:
- A login window
- A dashboard with two features: file/image reader and audio transcription
- The Azure Function handled all processing: login validation, PDF/OCR text extraction, and Whisper-based audio transcription

### Changes made to the existing .NET project

| File | What changed |
|---|---|
| `LoginFunction/Functions/TranscribeAudioFunction.cs` | Replaced broken `System.Speech` recognizer with a Python Whisper subprocess. Fixed temp file leak in `ConvertToWaveStream`. Added 10-minute timeout with process kill on overflow. |
| `LoginFunction/LoginFunction.csproj` | Removed `NAudio`, `System.Speech`, `Vosk` packages (no longer needed). Added `whisper_transcribe.py` as a `CopyToOutputDirectory` asset. |
| `LoginFunction/local.settings.json` | Added `WHISPER_PYTHON_EXE` and `WHISPER_MODEL` settings for configurable Python path and model size. |
| `LoginFunction/whisper_transcribe.py` | **New file.** Python script that loads the Whisper model and transcribes audio. Redirects all Whisper progress output to stderr so only the clean transcript reaches stdout for C# to read. |
| `LoginClient/Pages/AudioTranscriptionPage.xaml.cs` | Replaced `TranscribeAudioLocal()` (broken Windows Speech fallback) with a call to `_apiService.TranscribeAudioAsync()`. Removed unused `System.Speech` and `NAudio` imports. |
| `LoginClient/LoginClient.csproj` | Removed unused `NAudio` and `System.Speech` package references. |
| `C:\xampp\php\php.ini` | Raised `upload_max_filesize` and `post_max_size` from 40 MB to **64 MB** to support audio file uploads up to 50 MB. |

### New files added — PHP web frontend

All PHP files live at `C:\xampp\htdocs\loginsample\`. They call the **same Azure Function endpoints** as the WPF app — no backend code was duplicated.

| File | Purpose |
|---|---|
| `config.php` | Shared config: `API_BASE_URL`, cURL helpers, session guard, XSS-safe `h()` function |
| `login.php` | Login form → POSTs JSON to `/api/login` → sets PHP session on success |
| `dashboard.php` | Welcome page shown after login with navigation cards |
| `upload-file.php` | File/image picker → POSTs multipart to `/api/read-file` → shows extracted text |
| `audio.php` | Audio file picker → POSTs multipart to `/api/transcribe-audio` → shows transcript |
| `logout.php` | Destroys PHP session and redirects to login |
| `css/style.css` | Shared stylesheet: navbar, cards, forms, buttons, alerts, result boxes |

---

## Project layout

```
LoginSample/
├── LoginSample.slnx
│
├── LoginClient/                        # WPF desktop app (net8.0-windows)
│   ├── Models/
│   ├── Pages/
│   │   ├── DashboardPage.xaml/.cs
│   │   ├── FileReaderPage.xaml/.cs
│   │   └── AudioTranscriptionPage.xaml/.cs  ← fixed (was calling broken local Speech API)
│   ├── Services/ApiService.cs
│   ├── MainWindow.xaml/.cs
│   └── DashboardWindow.xaml/.cs
│
├── LoginFunction/                      # Azure Function backend (net8.0)
│   ├── Functions/
│   │   ├── LoginFunction.cs            # POST /api/login
│   │   ├── ReadFileFunction.cs         # POST /api/read-file
│   │   └── TranscribeAudioFunction.cs  # POST /api/transcribe-audio  ← rewritten
│   ├── Helpers/MultipartFormHelper.cs
│   ├── Models/
│   ├── whisper_transcribe.py           # NEW — Python Whisper script
│   ├── Program.cs
│   ├── host.json
│   └── local.settings.json             # updated — added Whisper settings
│
└── README.md

C:\xampp\htdocs\loginsample\            # NEW — PHP web frontend
    ├── config.php
    ├── login.php
    ├── dashboard.php
    ├── upload-file.php
    ├── audio.php
    ├── logout.php
    └── css/style.css
```

---

## Architecture — how PHP and .NET communicate

```
Browser (PHP page in Apache)
      │
      │  HTTP POST via cURL (JSON or multipart/form-data)
      ▼
Azure Function  localhost:7071
      │
      ├─ /api/login            ← JSON body  { username, password }
      ├─ /api/read-file        ← multipart  file field "file"
      └─ /api/transcribe-audio ← multipart  file field "file"
                                             │
                                             └─ spawns Python subprocess
                                                py -3.11 whisper_transcribe.py
```

PHP uses **cURL** (`call_api_json` and `call_api_multipart` in `config.php`) to POST to the Azure Function. The Function processes the request and returns JSON. PHP reads the JSON and renders the result on the page.

The WPF app does the exact same thing using `HttpClient` in `ApiService.cs`. Both frontends hit **identical endpoints** — the backend has no knowledge of which frontend is calling it.

---

## Prerequisites

### 1. .NET 8 SDK
Download from https://dotnet.microsoft.com/download

### 2. Azure Functions Core Tools v4
```powershell
npm install -g azure-functions-core-tools@4 --unsafe-perm true
```

### 3. XAMPP (PHP + Apache)
Already installed at `C:\xampp`. Includes PHP 8.0 and Apache.

### 4. Python 3.11 (for audio transcription)
```powershell
py -3.11 -m pip install openai-whisper
```
The first transcription downloads model weights (~75 MB for `tiny`) to `~/.cache/whisper` automatically.

### 5. FFmpeg (for audio transcription)
Whisper uses FFmpeg internally to decode MP3. Must be on your system `PATH`.
- Download: https://www.gyan.dev/ffmpeg/builds/ — get the **essentials** build
- Extract and add the `bin` folder to your `PATH`
- Verify: `ffmpeg -version`

---

## Running the application

You need **two things running** at the same time: the Azure Function and Apache.

### Step 1 — Build the .NET project
```powershell
cd c:\deep\.net\LoginSample
dotnet build
```
This also copies `whisper_transcribe.py` to the function output directory.

### Step 2 — Start the Azure Function backend

Open a terminal and run:
```powershell
cd c:\deep\.net\LoginSample\LoginFunction
func start
```
Wait until you see all three endpoints listed:
```
Functions:
    Login:            [POST] http://localhost:7071/api/login
    ReadFile:         [POST] http://localhost:7071/api/read-file
    TranscribeAudio:  [POST] http://localhost:7071/api/transcribe-audio
```
Leave this terminal open. Do not close it.

### Step 3 — Start Apache

**Option A — XAMPP Control Panel (recommended):**
1. Open: `C:\xampp\xampp-control.exe`
2. Click **Start** next to **Apache**
3. The row turns green — Apache is running on port 80

**Option B — Command line:**
```powershell
C:\xampp\apache\bin\httpd.exe
```
Leave this terminal open.

**Verify Apache is working** — open http://localhost in your browser. You should see the XAMPP welcome page.

### Step 4 — Open the PHP web app

Open your browser and go to:
```
http://localhost/loginsample/login.php
```
Login with `admin` / `1234`.

### Running the WPF desktop app (optional)
The WPF app is the original desktop version — it connects to the same backend.
```powershell
cd c:\deep\.net\LoginSample\LoginClient
dotnet run
```

---

## If Apache won't start (port 80 conflict)

Port 80 may already be used by IIS or another service. Check what is using it:
```powershell
netstat -ano | findstr :80
```

If something is blocking port 80, change Apache's port:
1. Open `C:\xampp\apache\conf\httpd.conf`
2. Find `Listen 80` and change it to `Listen 8080`
3. Restart Apache
4. Access the app at `http://localhost:8080/loginsample/login.php` instead

---

## Login credentials

| Username | Password |
|---|---|
| `admin` | `1234` |

---

## API endpoints

All endpoints are served by the Azure Function at `http://localhost:7071`.

### POST /api/login
```
Content-Type: application/json

{ "username": "admin", "password": "1234" }
```
Success: `{ "success": true, "message": "Login Successful" }`
Failure: `{ "success": false, "message": "Invalid Credentials" }`

### POST /api/read-file
```
Content-Type: multipart/form-data
Field name:   file
Accepted:     .txt  .pdf  .jpg  .jpeg  .png
Max size:     25 MB
```
Success: `{ "success": true, "fileName": "doc.pdf", "content": "Extracted text...", "message": "" }`
Failure: `{ "success": false, "fileName": "doc.pdf", "content": "", "message": "Reason" }`

### POST /api/transcribe-audio
```
Content-Type: multipart/form-data
Field name:   file
Accepted:     .mp3  .wav
Max size:     50 MB
```
Success: `{ "success": true, "fileName": "audio.mp3", "transcript": "Transcribed text...", "message": "" }`
Failure: `{ "success": false, "fileName": "audio.mp3", "transcript": "", "message": "Reason" }`

---

## How login works

1. User fills in the form on `login.php` and clicks Login
2. Browser sends a `POST` request to `login.php` with `username` and `password`
3. PHP reads `$_POST['username']` and `$_POST['password']`
4. `call_api_json('/login', [...])` in `config.php` sends a cURL POST with a JSON body to `localhost:7071/api/login`
5. The Azure Function (`LoginFunction.cs`) checks: username = `admin`, password = `1234`
6. Returns `{ "success": true/false, "message": "..." }`
7. If `success === true` → PHP stores `$_SESSION['username']` and redirects to `dashboard.php`
8. If `success === false` → PHP re-renders `login.php` with the error message in red
9. Every protected page calls `require_login()` at the top — if `$_SESSION['username']` is empty, the user is sent back to `login.php`
10. `logout.php` clears `$_SESSION`, destroys the session cookie, and redirects to `login.php`

---

## How file / image extraction works

1. User picks a file on `upload-file.php` and clicks **Upload & Extract**
2. PHP validates the extension (`.txt`, `.pdf`, `.jpg`, `.jpeg`, `.png`) and file size (≤ 25 MB)
3. `call_api_multipart('/read-file', ...)` uses PHP's `CURLFile` to POST the file to `localhost:7071/api/read-file`
4. The Azure Function (`ReadFileFunction.cs`) parses the multipart body via `MultipartFormHelper`, then:
   - `.txt` → reads with `StreamReader` (UTF-8)
   - `.pdf` → extracts text page by page using PdfPig
   - `.jpg` / `.jpeg` / `.png` → runs Tesseract OCR using the English language model in `tessdata/`
5. Returns `{ "success": true/false, "fileName": "...", "content": "...", "message": "..." }`
6. PHP displays the `content` in a scrollable read-only `<textarea>`

---

## How audio transcription works

1. User picks an `.mp3` or `.wav` file on `audio.php` and clicks **Upload & Transcribe**
2. The button is immediately disabled and changes to "Transcribing…" so the user knows to wait
3. PHP validates extension and size (≤ 50 MB), then calls `call_api_multipart` with a **600-second cURL timeout** (10 minutes)
4. The Azure Function (`TranscribeAudioFunction.cs`) writes the uploaded bytes to a temp file, then runs:
   ```
   py -3.11 whisper_transcribe.py <temp_file_path> tiny
   ```
5. `whisper_transcribe.py` redirects `sys.stdout` to `sys.stderr` while the model loads and transcribes, so only the clean transcript text reaches stdout
6. C# reads stdout, deletes the temp file, and returns `{ "success": true/false, "fileName": "...", "transcript": "...", "message": "..." }`
7. PHP displays the `transcript` in a scrollable `<textarea>`

---

## Audio transcription configuration

Edit `LoginFunction/local.settings.json` to change these settings:

| Setting | Default | Description |
|---|---|---|
| `WHISPER_PYTHON_EXE` | `py` | Python launcher. Change to a full path if `py` is not on your PATH |
| `WHISPER_MODEL` | `tiny` | Whisper model size — see table below |

| Model | Download size | Speed on CPU | Accuracy |
|---|---|---|---|
| `tiny` | ~75 MB | ~10 min per hour of audio | Good for clear speech |
| `base` | ~140 MB | ~25 min per hour of audio | Better |
| `small` | ~460 MB | ~60 min per hour of audio | High |
| `medium` | ~1.5 GB | ~120 min per hour of audio | Very high |

---

## NuGet packages (LoginFunction)

| Package | Version | Purpose |
|---|---|---|
| `Microsoft.Azure.Functions.Worker` | 2.1.0 | Isolated worker runtime |
| `Microsoft.Azure.Functions.Worker.Extensions.Http` | 3.3.0 | HTTP trigger binding |
| `Microsoft.Azure.Functions.Worker.Sdk` | 2.0.5 | Build SDK / source generator |
| `Microsoft.AspNetCore.WebUtilities` | 8.0.0 | `MultipartReader` for file uploads |
| `Microsoft.Net.Http.Headers` | 8.0.0 | Content-Type / boundary parsing |
| `PdfPig` | 0.1.15 | PDF text extraction |
| `Tesseract` | 5.2.0 | OCR for image files |

Removed packages (were unused or replaced):

| Package | Reason removed |
|---|---|
| `NAudio` | Audio resampling no longer needed — Whisper/FFmpeg handles it |
| `System.Speech` | Replaced with Whisper — was producing "no audio input" error |
| `Vosk` | Was imported but never called — dead code |

---

## Step-by-step test procedure

### Test 1 — Login (valid credentials)
1. Open http://localhost/loginsample/login.php
2. Enter `admin` / `1234` → click **Login**
3. ✅ Expected: redirected to `dashboard.php`

### Test 2 — Login (invalid credentials)
1. On `login.php` enter `admin` / `wrongpassword` → click **Login**
2. ✅ Expected: red alert "Invalid Credentials", stays on login page

### Test 3 — Session protection
1. While logged out, navigate directly to http://localhost/loginsample/dashboard.php
2. ✅ Expected: redirected to `login.php`

### Test 4 — File upload (PDF or TXT)
1. Log in → click **File Upload** on the dashboard
2. Choose any `.txt` or `.pdf` file → click **Upload & Extract**
3. ✅ Expected: extracted text appears in the result box

### Test 5 — Image upload (OCR)
1. On the File Upload page, choose a `.jpg` or `.png` containing printed text
2. ✅ Expected: Tesseract returns the text from the image

### Test 6 — Audio transcription
1. Log in → click **Audio Transcription**
2. Choose a short `.mp3` or `.wav` (under 1 minute for a quick test)
3. Click **Upload & Transcribe** — button changes to "Transcribing…"
4. Wait 30 seconds to a few minutes
5. ✅ Expected: transcript appears in the result box

### Test 7 — Logout
1. Click **Logout** in the navbar
2. ✅ Expected: redirected to `login.php`, session is gone

---

## Troubleshooting

| Symptom | Fix |
|---|---|
| `Could not reach the backend` on any PHP page | The Azure Function is not running. Open a terminal and run `func start` inside `LoginFunction/` |
| `func: command not found` | Install Azure Functions Core Tools: `npm install -g azure-functions-core-tools@4` |
| Apache won't start | Open XAMPP Control Panel — check if port 80 is in use. Change to port 8080 in `httpd.conf` if needed |
| Browser shows PHP source code instead of a page | Apache is not running — start it via XAMPP Control Panel |
| `http://localhost` shows nothing | Apache is not running, or port was changed — use `http://localhost:8080` if you changed the port |
| Upload fails with "file too large" | `php.ini` is already set to 64 MB. If you changed it, restart Apache for the change to take effect |
| `whisper_transcribe.py was not found` | Run `dotnet build` inside `LoginFunction/` — the script must be copied to the output directory |
| `No module named 'whisper'` | Run `py -3.11 -m pip install openai-whisper` |
| `ffmpeg is not recognized` | Add FFmpeg `bin` folder to your system PATH, then restart the terminal |
| Empty transcript on valid audio | Audio may contain no speech, or try a more accurate model: set `WHISPER_MODEL=base` in `local.settings.json` |
| Transcription takes a very long time | Normal for long audio on CPU. The `tiny` model processes roughly 4 minutes of audio in 3–8 minutes |
| `AzureWebJobsStorage` unhealthy warnings in the func log | Safe to ignore — these are HTTP-only functions and do not use Azure Storage |
| WPF app audio transcription shows "no audio input" error | This was the original bug — it is fixed. Rebuild with `dotnet build` and restart `func start` |

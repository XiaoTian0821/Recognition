# AI + AR Smart Object Recognition System

A modern web application that uses your smartphone camera to identify objects in real-time using AI Vision. Point your camera at any object, scan it, and get instant product information with an augmented-reality-style overlay.

---

## 1. Project Overview

This application turns any smartphone browser into an **AI-powered object scanner**. It uses:

- **Browser Camera API** — Live camera feed with rear-camera preference
- **AI Vision APIs** — Google Gemini or Agnes AI to identify objects
- **AR Overlay** — Bounding box + product information card over the live camera
- **Scan History** — Optional MySQL database to save past recognitions

No app installation required — works directly in the browser.

---

## 2. Features

| Feature | Description |
|---|---|
| Live Camera Feed | Full-screen camera preview, prefers rear camera on mobile |
| One-Tap Scan | Capture and send image to AI for identification |
| AI Object Recognition | Identifies objects, reads labels, brands, model numbers |
| AR Bounding Box | Visual rectangle around the detected object |
| Product Info Card | Collapsible card with manufacturer, specs, confidence |
| Dual AI Provider | Gemini + Agnes with Auto fallback |
| Scan History | MySQL-powered history with search and pagination |
| Settings Page | Configure providers, test connections, generate QR code |
| Mobile-First UI | Dark glassmorphism design, works on all devices |
| Secure API Keys | Keys stored server-side, never exposed to browser |

---

## 3. Technology Stack

- **Backend:** PHP 8.3+ (pure PHP, no framework)
- **Database:** MySQL 8+ (optional — scan history)
- **Frontend:** HTML5, CSS3, Vanilla JavaScript (ES6+)
- **Styling:** Bootstrap 5 (CDN), custom CSS
- **Icons:** Font Awesome (CDN)
- **Fonts:** Inter (Google Fonts CDN)
- **AI APIs:** Google Gemini Vision, Agnes Vision

---

## 4. Requirements

- PHP 8.3 or higher
- MySQL 8+ (optional, for scan history)
- `curl` and `gd` PHP extensions enabled
- Web server with PHP support (Apache/nginx)
- **HTTPS** required for camera access on most browsers
- Modern browser (Chrome, Safari, Edge, Firefox)

---

## 5. XAMPP Installation

### Step 1 — Copy the project

Copy the entire `ai-ar-object-recognition/` folder into your XAMPP `htdocs` directory:

```
C:\xampp\htdocs\ai-ar-object-recognition\
```

### Step 2 — Start Apache and MySQL

Open XAMPP Control Panel and start **Apache** and **MySQL**.

### Step 3 — Create the database (optional)

Open phpMyAdmin (`http://localhost/phpmyadmin`) and run the SQL from `database/database.sql`:

```sql
CREATE DATABASE ai_ar_recognition CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;
```

Or import the SQL file directly.

### Step 4 — Configure

1. Open `config/config.php` (it already exists as a stub)
2. Open `config/config.example.php` to see all available options
3. Fill in your AI API keys in `config/config.php`

### Step 5 — Access the application

Open your browser and go to:

```
http://localhost/ai-ar-object-recognition/
```

> **Note:** Camera access on localhost works without HTTPS. For smartphone testing, see section 7.

---

## 6. cPanel / Shared Hosting Installation

### Step 1 — Upload files

Upload the entire `ai-ar-object-recognition/` folder to your `public_html/` (or a subdirectory):

```
public_html/ai-ar/
```

### Step 2 — Create database

In cPanel, create a MySQL database and import `database/database.sql`.

### Step 3 — Update database config

Edit `config/config.php` and update the database connection settings:

```php
'database' => [
    'host'     => 'localhost',
    'dbname'   => 'your_db_name',
    'username' => 'your_db_user',
    'password' => 'your_db_password',
],
```

### Step 4 — Configure AI providers

Add your API keys in `config/config.php`.

### Step 5 — Set permissions

Ensure `config/config.php` is writable by the web server (chmod 666 temporarily while saving settings, then 644 after).

### Step 6 — Access the application

```
https://yourdomain.com/ai-ar/
```

---

## 7. HTTPS Requirement

Browser camera APIs **require a secure context** (HTTPS or localhost).

### For smartphone testing:

**Option A — Local tunnel (recommended for development):**
Use a tool like [ngrok](https://ngrok.com/) to create a temporary HTTPS URL:

```bash
ngrok http 80
```

Then access the scanner from your phone using the ngrok URL.

**Option B — Deploy to a hosting provider with HTTPS:**
Upload to any hosting with SSL (most cPanel hosts provide this automatically).

**Option C — Use a local network URL with HTTPS:**
Some browsers allow `http://` on local network addresses. Try `http://192.168.x.x/ai-ar-object-recognition/`.

---

## 8. AI API Configuration

### Google Gemini

1. Go to [Google AI Studio](https://aistudio.google.com/app/apikey)
2. Create a free API key
3. In the app, go to **Settings** → **Google Gemini** → paste your key

The default model is `gemini-3.6-flash`. The free tier has generous limits.

### Agnes Vision

1. Obtain an API key from Agnes AI
2. In the app, go to **Settings** → **Agnes Vision** → paste your key

### Manual Configuration (config.php)

You can also edit `config/config.php` directly:

```php
'gemini' => [
    'api_key' => 'AIzaSy...',
     'model' => 'gemini-3.6-flash',
],
'agnes' => [
    'api_key' => 'your-agnes-key',
    'model' => 'agnes-vision-v1',
],
```

> **Security:** Never commit `config/config.php` to version control. Add it to `.gitignore`.

---

## 9. Database Setup

The scan history feature requires MySQL. To set up:

```sql
-- Create the database
CREATE DATABASE ai_ar_recognition CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;

-- Create the table (from database/database.sql)
USE ai_ar_recognition;

CREATE TABLE scan_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    object_label VARCHAR(200)   NOT NULL DEFAULT '',
    product_name VARCHAR(300)   NOT NULL DEFAULT '',
    manufacturer VARCHAR(200)   NOT NULL DEFAULT '',
    specification TEXT,
    description TEXT,
    confidence  DECIMAL(5, 2)   NOT NULL DEFAULT 0.00,
    provider    VARCHAR(50)     NOT NULL DEFAULT 'unknown',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_created_at (created_at DESC)
);
```

Disable history by setting `'enable_history' => false` in `config.php`.

---

## 10. Camera Permission

When you first open the app, the browser will ask for camera permission.

| Permission | Required? | Notes |
|---|---|---|
| Camera | **Yes** | Required to take photos |
| Microphone | No | Not requested (audio disabled) |
| Location | No | Not used |

If permission is denied, you can usually re-allow it in your browser settings. On iOS Safari, camera permissions may persist across sessions.

---

## 11. Testing

### Basic test flow:

1. Open the app on your phone or desktop
2. Allow camera access
3. Point camera at an object (laptop, bottle, book, phone, etc.)
4. Tap **SCAN**
5. Wait for AI identification (5–15 seconds)
6. View the AR bounding box and product info card
7. Tap **Scan Another Object** to scan again

### Testing the settings page:

1. Navigate to `/settings.php`
2. Enter your Gemini API key
3. Click **Test Connection** — should show "✓ Connected"
4. Click **Save Settings**
5. Return to the scanner and test a real scan

---

## 12. Troubleshooting

| Problem | Solution |
|---|---|
| Camera not working | Ensure HTTPS or localhost. Check browser permission settings. |
| "Camera permission denied" | Allow camera in browser settings and refresh. |
| "AI provider is not configured" | Go to Settings and add an API key. |
| "Connection failed" | Check your API key is valid. Test connection in Settings. |
| Slow recognition | Reduce image size or use a faster available Gemini model. |
| History not saving | Check MySQL is running and config.php has correct database credentials. |
| "Failed to write configuration" | Make `config/config.php` writable (`chmod 666`). |
| QR code not showing | Check internet connection (uses external QR API). |
| Bounding box misaligned | This is normal — AI provides approximate coordinates. |

---

## 13. Security Notes

- **API keys are stored server-side only** — never in JavaScript or HTML
- **No raw PHP errors are shown** to users — all errors are sanitised
- **Configuration files are protected** by `.htaccess` (deny direct access)
- **AI output is validated** — never trust raw API responses
- **Image uploads are validated** — MIME type, size, and Base64 encoding checks
- **No user accounts** — the app is designed for personal/single-user use
- **Add `config/config.php` to `.gitignore`** before committing to any repository

---

## 14. Project Folder Structure

```
ai-ar-object-recognition/
│
├── index.php                 # Main scanner page
├── settings.php              # Configuration page
├── history.php               # Scan history viewer
├── README.md                 # This file
│
├── api/
│   ├── recognize.php         # AI recognition endpoint
│   ├── settings.php          # Settings API endpoint
│   └── history.php           # History API endpoint
│
├── config/
│   ├── config.example.php    # Configuration template (safe to commit)
│   └── config.php            # Active configuration (DO NOT commit)
│
├── includes/
│   ├── AppConfig.php         # Configuration loader
│   ├── AIProvider.php        # Provider interface
│   ├── GeminiVision.php      # Google Gemini integration
│   ├── AgnesVision.php       # Agnes AI integration
│   ├── ObjectRecognizer.php  # Recognition orchestrator
│   ├── ImageValidator.php    # Image validation & compression
│   ├── ProductResult.php     # Result data object
│   └── History.php           # Database history manager
│
├── assets/
│   ├── css/
│   │   └── app.css           # All styles
│   └── js/
│       ├── camera.js         # Camera initialization & capture
│       ├── ar-overlay.js     # AR bounding box & result card
│       ├── scanner.js        # Scan workflow orchestrator
│       ├── settings.js       # Settings page logic
│       └── history.js        # History page logic
│
└── database/
    └── database.sql          # MySQL schema
```

---

## 15. How It Works

```
User opens app → Camera starts → User points at object → Taps SCAN
     ↓
Image captured (canvas) → Resized & compressed → Base64 encoded
     ↓
Sent to API (POST /api/recognize.php)
     ↓
PHP validates image → Sends to Gemini/Agnes AI
     ↓
AI returns structured JSON (label, product, bounding box, confidence)
     ↓
PHP validates response → Saves to history → Returns JSON to browser
     ↓
JavaScript renders AR bounding box + product info card over camera
```

---

## 16. License

This project is provided as-is for educational and personal use.

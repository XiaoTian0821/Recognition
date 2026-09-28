# AI + AR Smart Object Recognition System

A lightweight, modern, mobile-first Web Application that transforms any smartphone browser into an **AI-powered Augmented Reality (AR) Product Scanner**. Point your smartphone camera at physical products to extract real-time product specifications, brand info, and dynamic 2D AR bounding box overlays.

---

## 🌟 Key Features

1. **Live Smartphone Camera Integration**: Uses `navigator.mediaDevices.getUserMedia` targeting rear environment cameras with automatic cropping and resolution downscaling (max 1280px, 0.70 JPEG compression).
2. **AI Vision Object Recognition**: Interoperable multi-provider architecture supporting **Google Gemini Vision API** and **Agnes Vision API**.
3. **Auto Fallback System**: Automatically fails over to Agnes Vision if Gemini Vision experiences rate limits or timeouts.
4. **AR-Style Visual Overlay**: Dynamic bounding box drawing using normalized 0–1000 AI object detection coordinates converted directly to responsive CSS viewport percentages.
5. **Interactive Glassmorphism UI Card**: Expandable product card displaying Manufacturer, Features, Descriptions, Confidence percentages, and Provider source.
6. **Zero External Framework Dependencies**: Clean, standard PHP 8.3 + MySQL 8 + Vanilla JavaScript codebase—no Node.js, Composer, React, or Laravel required.
7. **Secure Key Management**: Server-side configuration handling with `.htaccess` / PHP protected files; API keys are never exposed in JavaScript or HTML.
8. **Optional Scan History**: Logs detected items in MySQL database for historical review.

---

## 🛠️ Technology Stack

- **Backend**: PHP 8.3+ (PDO Extension, cURL, JSON)
- **Database**: MySQL 8.0+
- **Frontend**: HTML5, CSS3, Vanilla JavaScript (ES6+ async/await)
- **Browser APIs**: MediaDevices API, Canvas API, Fetch API
- **Icons & Styling**: Bootstrap 5 CDN, Font Awesome CDN

---

## 🚀 Local Installation (XAMPP / WampServer)

1. **Clone or Extract Project**:
   Place the project directory under your local Web server root:
   ```text
   C:\xampp\htdocs\ai-ar-object-recognition\

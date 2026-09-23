/* ═══════════════════════════════════════════════════════════════
   Camera Module
   Handles camera initialization, stream management, and capture.
   ═══════════════════════════════════════════════════════════════ */

'use strict';

const Camera = (() => {
    let stream = null;
    let videoEl = null;
    let captureCanvas = null;

    /**
     * Initialize camera references and start the stream.
     */
    async function init(videoElement, captureCanvasElement) {
        videoEl = videoElement;
        captureCanvas = captureCanvasElement;

        if (!navigator.mediaDevices || !navigator.mediaDevices.getUserMedia) {
            throw new Error('Camera API is not available in this browser.');
        }

        const constraints = {
            video: {
                facingMode: 'environment',
                width:  { ideal: 1280 },
                height: { ideal: 720 },
            },
            audio: false,
        };

        try {
            stream = await navigator.mediaDevices.getUserMedia(constraints);
            videoEl.srcObject = stream;
            return { ok: true };
        } catch (err) {
            return handleCameraError(err);
        }
    }

    /**
     * Handle camera permission or access errors.
     */
    function handleCameraError(err) {
        let message = 'Unable to access camera.';

        if (err.name === 'NotAllowedError' || err.name === 'PermissionDeniedError') {
            message = 'Camera permission denied. Please allow camera access in your browser settings.';
        } else if (err.name === 'NotFoundError' || err.name === 'DevicesNotFoundError') {
            message = 'No camera found on this device.';
        } else if (err.name === 'NotReadableError' || err.name === 'TrackStartError') {
            message = 'Camera is in use by another application. Please close other apps and try again.';
        } else if (err.name === 'SecurityError') {
            message = 'Camera access requires a secure context (HTTPS or localhost).';
        }

        return { ok: false, error: message };
    }

    /**
     * Capture the current video frame as a Base64 JPEG string.
     * Uses a hidden offscreen canvas to avoid layout issues.
     */
    function capture() {
        if (!videoEl || !captureCanvas) {
            return null;
        }

        const ctx = captureCanvas.getContext('2d');
        if (!ctx) {
            return null;
        }

        // Use the video's natural dimensions for capture
        const videoWidth  = videoEl.videoWidth;
        const videoHeight = videoEl.videoHeight;

        if (videoWidth === 0 || videoHeight === 0) {
            return null;
        }

        captureCanvas.width  = videoWidth;
        captureCanvas.height = videoHeight;

        ctx.drawImage(videoEl, 0, 0, videoWidth, videoHeight);

        return captureCanvas.toDataURL('image/jpeg', 0.88);
    }

    /**
     * Stop the camera stream and release resources.
     */
    function stop() {
        if (stream) {
            stream.getTracks().forEach(track => track.stop());
            stream = null;
        }
        if (videoEl) {
            videoEl.srcObject = null;
        }
    }

    /**
     * Check if the camera is currently active.
     */
    function isActive() {
        return stream !== null;
    }

    return { init, capture, stop, isActive };
})();

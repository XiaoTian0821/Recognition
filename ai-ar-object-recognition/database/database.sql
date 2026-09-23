-- ─────────────────────────────────────────────────────────────────
-- AI + AR Smart Object Recognition System
-- Database Schema
-- ─────────────────────────────────────────────────────────────────

CREATE DATABASE IF NOT EXISTS ai_ar_recognition
    CHARACTER SET utf8mb4
    COLLATE utf8mb4_unicode_ci;

USE ai_ar_recognition;

-- ─────────────────────────────────────────────────────────────────
-- Scan History Table
-- Stores successful recognition results for later review.
-- ─────────────────────────────────────────────────────────────────

CREATE TABLE IF NOT EXISTS scan_history (
    id          BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
    object_label VARCHAR(200)   NOT NULL DEFAULT '',
    product_name VARCHAR(300)   NOT NULL DEFAULT '',
    manufacturer VARCHAR(200)   NOT NULL DEFAULT '',
    specification TEXT,
    description TEXT,
    confidence  DECIMAL(5, 2)   NOT NULL DEFAULT 0.00 COMMENT '0.00 to 100.00',
    provider    VARCHAR(50)     NOT NULL DEFAULT 'unknown',
    created_at  DATETIME        NOT NULL DEFAULT CURRENT_TIMESTAMP,
    PRIMARY KEY (id),
    INDEX idx_created_at (created_at DESC),
    INDEX idx_object_label (object_label(100)),
    INDEX idx_provider (provider)
) ENGINE=InnoDB
  DEFAULT CHARSET=utf8mb4
  COLLATE=utf8mb4_unicode_ci;

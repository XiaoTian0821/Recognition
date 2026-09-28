<?php
declare(strict_types=1);

/**
 * Minimal JSON-over-HTTPS client for AI provider APIs.
 *
 * Uses cURL when available (preferred), otherwise the streams extension.
 * Secrets (API keys) only ever appear inside request headers.
 */
final class HttpHelper
{
    /**
     * POST a JSON payload.
     *
     * @param string[] $headers request headers without the "Name: value" separator
     * @return array{status: int, body: string, error: string|null}
     */
    public static function postJson(
        string $url,
        array $payload,
        array $headers,
        int $timeoutSeconds
    ): array {
        $body = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Could not encode request payload.'];
        }
        return self::request('POST', $url, $body, $headers, $timeoutSeconds, true);
    }

    /**
     * GET a JSON resource (used for provider connectivity probes).
     *
     * @param string[] $headers request headers without the "Name: value" separator
     * @return array{status: int, body: string, error: string|null}
     */
    public static function getJson(string $url, array $headers, int $timeoutSeconds): array
    {
        return self::request('GET', $url, null, $headers, $timeoutSeconds, false);
    }

    /**
     * Core request routine (cURL when available, streams as fallback).
     *
     * @param string[] $headers
     * @return array{status: int, body: string, error: string|null}
     */
    private static function request(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeout,
        bool $jsonContent
    ): array {
        if (function_exists('curl_init')) {
            return self::requestCurl($method, $url, $body, $headers, $timeout, $jsonContent);
        }
        return self::requestStream($method, $url, $body, $headers, $timeout, $jsonContent);
    }

    private static function requestCurl(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeout,
        bool $jsonContent
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            return ['status' => 0, 'body' => '', 'error' => 'cURL is not initialized properly.'];
        }

        $curlHeaders = [];
        foreach ($headers as $header) {
            $curlHeaders[] = $header;
        }
        $hasContentType = false;
        foreach ($curlHeaders as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $hasContentType = true;
                break;
            }
        }
        if (!$hasContentType && $jsonContent) {
            $curlHeaders[] = 'Content-Type: application/json';
        }

        $opts = [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
            CURLOPT_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_HTTP_VERSION => CURL_CHTTP_2,
            CURLOPT_HTTPHEADER => $curlHeaders,
        ];
        if ($method === 'POST') {
            $opts[CURLOPT_POSTFIELDS] = $body ?? '';
        }

        $responseBody = (string) curl_exec($ch);
        $error = curl_error($ch);
        $status = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
        curl_close($ch);

        if ($error !== '' && $responseBody === '' && $status === 0) {
            return ['status' => 0, 'body' => '', 'error' => "Network error: {$error}"];
        }
        if ($status < 200 || $status >= 300) {
            return ['status' => $status, 'body' => $responseBody, 'error' => self::hintFromErrorBody($responseBody, $status)];
        }
        return ['status' => $status, 'body' => $responseBody, 'error' => null];
    }

    private static function requestStream(
        string $method,
        string $url,
        ?string $body,
        array $headers,
        int $timeout,
        bool $jsonContent
    ): array {
        $headerLines = array_merge($jsonContent ? ['Content-Type: application/json'] : [], $headers);
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headerLines),
                'timeout' => $timeout,
                'ignore_errors' => true,
            ],
        ];
        if ($method === 'POST') {
            $opts['http']['content'] = $body ?? '';
        }
        $context = stream_context_create($opts);
        $responseBody = @file_get_contents($url, false, $context);

        if ($responseBody === false) {
            return ['status' => 0, 'body' => '', 'error' => 'Network error: the request could not be completed.'];
        }

        $status = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $line) {
                if (is_string($line) && stripos($line, 'HTTP/') === 0 && preg_match('/\s(\d{3})/', $line, $m)) {
                    $status = (int) $m[1];
                }
            }
        }
        if ($status === 0) {
            $status = 200;
        }
        if ($status < 200 || $status >= 300) {
            return [
                'status' => $status,
                'body' => $responseBody,
                'error' => "HTTP {$status} from the provider.",
            ];
        }
        return ['status' => $status, 'body' => $responseBody, 'error' => null];
    }

    /**
     * Turn a provider error body into a short message that is safe to log
     * and show: it never includes keys (keys live in headers only) and
     * is truncated to 300 characters.
     */
    private static function hintFromErrorBody(string $body, int $status): string
    {
        $decoded = json_decode($body, true);
        if (is_array($decoded)) {
            $message = $decoded['error']['message'] ?? $decoded['error'] ?? $decoded['message'] ?? null;
            if (is_string($message) && trim($message) !== '') {
                $message = trim($message);
                if (strlen($message) > 300) {
                    $message = substr($message, 0, 300) . '…';
                }
                return "HTTP {$status}: " . $message;
            }
        }
        $snippet = trim($body);
        if (strlen($snippet) > 300) {
            $snippet = substr($snippet, 0, 300) . '…';
        }
        return "HTTP {$status} from the provider." . ($snippet !== '' ? " {$snippet}" : '');
    }
}

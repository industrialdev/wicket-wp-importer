<?php

declare(strict_types=1);

namespace WicketImporter\Services;

class Logger
{
    /**
     * Log info message.
     */
    public function info(string $message, array $context = []): void
    {
        $this->log('info', $message, $context);
    }

    /**
     * Log error message.
     */
    public function error(string $message, array $context = []): void
    {
        $this->log('error', $message, $context);
    }

    /**
     * Log warning message.
     */
    public function warning(string $message, array $context = []): void
    {
        $this->log('warning', $message, $context);
    }

    /**
     * Internal log handler.
     */
    private function log(string $level, string $message, array $context): void
    {
        // S5: redact PII (emails, UUIDs) so WC logs (world-readable to any admin,
        // retained ~30 days) and error_log (may ship off-box) don't accumulate
        // member identifiers. Applies to both the message and the context array.
        $message = self::redactPii($message);
        $context = self::redactPiiInContext($context);

        if (function_exists('wc_get_logger')) {
            $logger = wc_get_logger();
            $logger->log($level, $message, array_merge(['source' => 'wicket-wp-importer'], $context));
        } else {
            error_log(sprintf('[Wicket Importer %s]: %s %s', strtoupper($level), $message, json_encode($context)));
        }
    }

    /**
     * Mask emails and UUIDs in a string.
     */
    private static function redactPii(string $value): string
    {
        // Email (must run before the UUID pass so the local-part isn't matched).
        $value = preg_replace('/[\w.+-]+@[\w.-]+\.[a-z]{2,}/i', '[email]', $value) ?? $value;
        // UUID v1-v5.
        $value = preg_replace('/\b[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\b/i', '[uuid]', $value) ?? $value;

        return $value;
    }

    /**
     * Recursively redact PII in a context array (values only; keys preserved).
     */
    private static function redactPiiInContext(array $context): array
    {
        foreach ($context as $key => $value) {
            if (is_string($value)) {
                $context[$key] = self::redactPii($value);
            } elseif (is_array($value)) {
                $context[$key] = self::redactPiiInContext($value);
            }
        }

        return $context;
    }
}

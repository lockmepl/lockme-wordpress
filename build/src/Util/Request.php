<?php

declare (strict_types=1);
namespace LockmeDep\LockmeIntegration\Util;

defined('ABSPATH') or die('No script kiddies please!');
final class Request
{
    public static function hasPost(): bool
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request helper.
        return !empty($_POST);
    }
    public static function getString(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        if (!isset($_GET[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        return sanitize_text_field(wp_unslash((string) $_GET[$key]));
    }
    public static function getInt(string $key, int $default = 0): int
    {
        return absint(self::getString($key, (string) $default));
    }
    public static function getBool(string $key): bool
    {
        return self::getString($key) !== '';
    }
    public static function postString(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        if (!isset($_POST[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        return sanitize_text_field(wp_unslash((string) $_POST[$key]));
    }
    public static function postRaw(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        if (!isset($_POST[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        return (string) wp_unslash($_POST[$key]);
    }
    public static function postInt(string $key, int $default = 0): int
    {
        return absint(self::postString($key, (string) $default));
    }
    public static function postArray(string $key): array
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        if (!isset($_POST[$key]) || !is_array($_POST[$key])) {
            return [];
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Missing,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        return (array) wp_unslash($_POST[$key]);
    }
    public static function requestString(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        if (!isset($_REQUEST[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.NonceVerification.Recommended,WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Centralized request sanitization helper.
        return sanitize_text_field(wp_unslash((string) $_REQUEST[$key]));
    }
    public static function requestInt(string $key, int $default = 0): int
    {
        return absint(self::requestString($key, (string) $default));
    }
    public static function serverString(string $key, string $default = ''): string
    {
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server data sanitization helper.
        if (!isset($_SERVER[$key])) {
            return $default;
        }
        // phpcs:ignore WordPress.Security.ValidatedSanitizedInput.InputNotSanitized -- Server data sanitization helper.
        return sanitize_text_field(wp_unslash((string) $_SERVER[$key]));
    }
}

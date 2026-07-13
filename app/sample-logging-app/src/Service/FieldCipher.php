<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Field-level encryption for log data (AES-256-GCM).
 *
 * Selected sensitive fields are encrypted BEFORE the log line is written, so
 * OpenSearch only ever stores ciphertext ("enc:v1:..."). The key lives on the
 * app side (LOG_ENCRYPT_KEY env) — anyone with cluster access sees only blobs;
 * our app decrypts on read (OpenSearchLogService).
 *
 * Trade-off (by design): encrypted fields cannot be searched/filtered in
 * OpenSearch. Keep query keys (ids) in plaintext; encrypt only payload PII.
 */
final class FieldCipher
{
    private const PREFIX = 'enc:v1:';
    private const CIPHER = 'aes-256-gcm';

    /**
     * 32-byte key derived from the LOG_ENCRYPT_KEY passphrase.
     */
    private static function key(): string
    {
        return hash('sha256', (string)env('LOG_ENCRYPT_KEY', 'poc-demo-key-change-me'), true);
    }

    /**
     * Encrypt one value -> "enc:v1:<base64(iv|tag|ciphertext)>".
     */
    public static function encrypt(string $plain): string
    {
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return self::PREFIX . base64_encode($iv . $tag . $cipher);
    }

    /**
     * Decrypt one "enc:v1:..." value. Returns null if the value is corrupt or
     * the key is wrong; returns the input unchanged if it isn't encrypted.
     */
    public static function decrypt(string $value): ?string
    {
        if (!str_starts_with($value, self::PREFIX)) {
            return $value;
        }

        $raw = base64_decode(substr($value, strlen(self::PREFIX)), true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }

        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, self::CIPHER, self::key(), OPENSSL_RAW_DATA, $iv, $tag);

        return $plain === false ? null : $plain;
    }

    /**
     * Walk a log payload and encrypt the values of the configured sensitive
     * field names wherever they appear (top level, new_values, changes, ...).
     *
     * @param array $data Log payload.
     * @param array $fields Sensitive field names, e.g. ['email', 'description'].
     * @return array
     */
    public static function encryptFields(array $data, array $fields): array
    {
        foreach ($data as $key => $value) {
            if (in_array((string)$key, $fields, true)) {
                $data[$key] = is_array($value)
                    ? self::encryptAll($value)          // e.g. changes.email = {before, after}
                    : self::encrypt((string)$value);
            } elseif (is_array($value)) {
                $data[$key] = self::encryptFields($value, $fields);
            }
        }

        return $data;
    }

    /**
     * Decrypt every "enc:v1:" string anywhere in a document (self-describing,
     * so no field list is needed on the read side).
     */
    public static function decryptFields(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($value) && str_starts_with($value, self::PREFIX)) {
                $data[$key] = self::decrypt($value) ?? '[decrypt-failed]';
            } elseif (is_array($value)) {
                $data[$key] = self::decryptFields($value);
            }
        }

        return $data;
    }

    /**
     * Encrypt every scalar leaf under a sensitive key.
     */
    private static function encryptAll(array $value): array
    {
        foreach ($value as $key => $item) {
            $value[$key] = is_array($item) ? self::encryptAll($item) : self::encrypt((string)$item);
        }

        return $value;
    }
}

<?php
declare(strict_types=1);

namespace App\Service;

/**
 * Runtime-editable registry of which log fields get encrypted (FieldCipher).
 *
 * Backed by config/encrypt_fields.json so the list can be managed from the
 * EncryptionFields admin page without a deploy. AuditLogBehavior reads it on
 * every table init, so changes take effect on the next request.
 */
final class EncryptFieldsRegistry
{
    /** Fallback when the file is missing/unreadable. */
    private const DEFAULTS = ['email', 'description'];

    /** @var array<string>|null per-request cache */
    private static ?array $cache = null;

    private static function path(): string
    {
        return CONFIG . 'encrypt_fields.json';
    }

    /**
     * Current list of encrypted field names.
     *
     * @return array<string>
     */
    public static function list(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $path = self::path();
        if (is_readable($path)) {
            $data = json_decode((string)file_get_contents($path), true);
            if (is_array($data)) {
                return self::$cache = array_values(array_filter(array_map('strval', $data)));
            }
        }

        return self::$cache = self::DEFAULTS;
    }

    /**
     * Add a field name. Returns false if invalid or already present.
     */
    public static function add(string $field): bool
    {
        $field = strtolower(trim($field));
        if ($field === '' || !preg_match('/^[a-z][a-z0-9_]{0,63}$/', $field)) {
            return false;
        }

        $fields = self::list();
        if (in_array($field, $fields, true)) {
            return false;
        }

        $fields[] = $field;

        return self::save($fields);
    }

    /**
     * Remove a field name. Returns false if it wasn't in the list.
     */
    public static function remove(string $field): bool
    {
        $fields = self::list();
        $remaining = array_values(array_diff($fields, [$field]));
        if (count($remaining) === count($fields)) {
            return false;
        }

        return self::save($remaining);
    }

    private static function save(array $fields): bool
    {
        $ok = file_put_contents(
            self::path(),
            json_encode(array_values($fields), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n",
            LOCK_EX
        );
        if ($ok === false) {
            return false;
        }
        self::$cache = array_values($fields);

        return true;
    }
}

<?php
/**
 * Kryptografie – ochrana uložených tajemství (hesla k databázi, heslo ZIPu).
 *
 * Model:
 *   1. Při instalaci vznikne náhodný *hlavní klíč* (MK, 32 B).
 *   2. MK je uložen zašifrovaný klíčem odvozeným z přihlašovacího hesla (KEK).
 *   3. Po přihlášení se MK rozbalí a drží jen v serverové session.
 *   4. Tajemství v konfiguraci jsou šifrována MK (AES-256-GCM).
 *
 * Důsledek: kdo získá soubory nástroje bez znalosti hesla, nezíská ani
 * přístupové údaje k databázi. Zároveň si uživatel nemusí nic pamatovat navíc.
 */

declare(strict_types=1);

// Přímé volání tohoto souboru nedává smysl – vstupním bodem je index.php.
if (!defined('PB_VERSION')) {
    http_response_code(404);
    exit;
}


final class Crypto
{
    private const KDF_ITERATIONS = 210000;

    public static function available(): bool
    {
        return function_exists('openssl_encrypt') && in_array('aes-256-gcm', openssl_get_cipher_methods(), true);
    }

    /** Náhodný hlavní klíč. */
    public static function newKey(): string
    {
        return random_bytes(32);
    }

    /** Odvodí klíč z hesla (PBKDF2-SHA512). */
    public static function deriveKey(string $password, string $salt): string
    {
        return hash_pbkdf2('sha512', $password, $salt, self::KDF_ITERATIONS, 32, true);
    }

    /**
     * Zašifruje data klíčem. Výstup: base64(iv | tag | ciphertext).
     */
    public static function seal(string $plain, string $key): string
    {
        if (!self::available()) {
            return '';
        }
        $iv = random_bytes(12);
        $tag = '';
        $cipher = openssl_encrypt($plain, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        if ($cipher === false) {
            return '';
        }
        return base64_encode($iv . $tag . $cipher);
    }

    /**
     * Rozšifruje data. Vrací null při jakékoli chybě (i při porušení integrity).
     */
    public static function open(string $sealed, string $key): ?string
    {
        if (!self::available() || $sealed === '') {
            return null;
        }
        $raw = base64_decode($sealed, true);
        if ($raw === false || strlen($raw) < 29) {
            return null;
        }
        $iv = substr($raw, 0, 12);
        $tag = substr($raw, 12, 16);
        $cipher = substr($raw, 28);
        $plain = openssl_decrypt($cipher, 'aes-256-gcm', $key, OPENSSL_RAW_DATA, $iv, $tag);
        return $plain === false ? null : $plain;
    }

    /**
     * Zabalí hlavní klíč heslem.
     *
     * @return array{salt:string,wrapped:string}
     */
    public static function wrapMasterKey(string $masterKey, string $password): array
    {
        $salt = random_bytes(16);
        return [
            'salt' => base64_encode($salt),
            'wrapped' => self::seal($masterKey, self::deriveKey($password, $salt)),
        ];
    }

    /** Rozbalí hlavní klíč heslem, nebo vrátí null. */
    public static function unwrapMasterKey(string $wrapped, string $saltB64, string $password): ?string
    {
        $salt = base64_decode($saltB64, true);
        if ($salt === false) {
            return null;
        }
        return self::open($wrapped, self::deriveKey($password, $salt));
    }

    /** Bezpečné vymazání proměnné z paměti (best effort). */
    public static function wipe(string &$secret): void
    {
        if (function_exists('sodium_memzero')) {
            try {
                sodium_memzero($secret);
                return;
            } catch (Throwable $e) {
                // pokračujeme přepsáním níže
            }
        }
        $secret = str_repeat("\0", strlen($secret));
        $secret = '';
    }
}

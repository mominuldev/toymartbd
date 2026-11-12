<?php

declare(strict_types=1);

namespace App\Domain\Payment\Gateways\Sslcommerz;

use SensitiveParameter;

/**
 * The IPN / return-POST signature, reimplemented from SSLCommerz's own PHP library
 * (`lib/SslCommerzNotification.php`, SSLCOMMERZ_hash_verify; research §1.2):
 *
 *   1. split the posted `verify_key` on "," — the field names the signature covers;
 *   2. take those fields' posted values;
 *   3. add `store_passwd` => md5(store password);
 *   4. ksort; 5. join as key=value with "&"; 6. md5 and compare to `verify_sign`.
 *
 * `verify_sign_sha2` also appears in sample payloads, but its construction is
 * undocumented ([UNVERIFIED] in the research), so it is never relied on.
 *
 * Runs on the raw posted strings, before anything is parsed into a domain object or
 * written (rule 19). Comparison is constant-time.
 */
final class SslcommerzSignature
{
    /** @param  array<string, mixed>  $post */
    public static function verify(array $post, #[SensitiveParameter] string $storePassword): bool
    {
        $sign = $post['verify_sign'] ?? null;
        $keys = $post['verify_key'] ?? null;
        if (! is_string($sign) || ! is_string($keys) || $sign === '' || trim($keys) === '') {
            return false;
        }

        return hash_equals(self::sign($post, $keys, $storePassword), strtolower($sign));
    }

    /**
     * The signature for these posted fields. Public so hand-authored test fixtures can be
     * signed exactly the way the library verifies them.
     *
     * @param  array<string, mixed>  $post
     */
    public static function sign(array $post, string $verifyKey, #[SensitiveParameter] string $storePassword): string
    {
        $data = [];
        foreach (explode(',', $verifyKey) as $field) {
            $field = trim($field);
            if ($field === '') {
                continue;
            }
            $value = $post[$field] ?? '';
            $data[$field] = is_scalar($value) ? (string) $value : '';
        }
        $data['store_passwd'] = md5($storePassword);
        ksort($data);

        $pairs = [];
        foreach ($data as $key => $value) {
            $pairs[] = $key.'='.$value;
        }

        return md5(implode('&', $pairs));
    }
}

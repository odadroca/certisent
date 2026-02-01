<?php
declare(strict_types=1);

/**
 * TLS validation helpers.
 *
 * v0.7.2: hostname validation ("wrong.host" style).
 *
 * Notes:
 * - CertFetcher intentionally does NOT verify TLS; it captures what the endpoint serves.
 * - Validation here is a *separate* step and is opt-in per monitor via monitor_settings.tls_validation_mode.
 */
final class TlsValidator {

    /**
     * Validate that $host is covered by the certificate identity (SAN DNS/IP, CN fallback).
     *
     * @param string $host requested host (SNI/URL host)
     * @param array<string,mixed> $parsed openssl_x509_parse output
     * @return array{ok:bool,error:?string,matched:?string,candidates:array<int,string>}
     */
    public static function validateHostname(string $host, array $parsed): array {
        $host = trim($host);
        if ($host === '') {
            return ['ok'=>false,'error'=>'empty_host','matched'=>null,'candidates'=>[]];
        }

        // Normalize via IDNA when available.
        $hostNorm = self::normalizeHost($host);

        $candidates = self::extractIdentityNames($parsed);
        if (count($candidates) === 0) {
            return ['ok'=>false,'error'=>'no_identity_names','matched'=>null,'candidates'=>[]];
        }

        $isIp = filter_var($hostNorm, FILTER_VALIDATE_IP) !== false;

        foreach ($candidates as $name) {
            $pat = self::normalizeHost($name);
            if ($pat === '') continue;

            if ($isIp) {
                if (strcasecmp($hostNorm, $pat) === 0) {
                    return ['ok'=>true,'error'=>null,'matched'=>$name,'candidates'=>$candidates];
                }
                continue;
            }

            if (self::dnsNameMatches($hostNorm, $pat)) {
                return ['ok'=>true,'error'=>null,'matched'=>$name,'candidates'=>$candidates];
            }
        }

        return ['ok'=>false,'error'=>'hostname_mismatch','matched'=>null,'candidates'=>$candidates];
    }

    /**
     * @param array<string,mixed> $parsed
     * @return array<int,string> SAN DNS/IP entries, otherwise CN.
     */
    private static function extractIdentityNames(array $parsed): array {
        $out = [];

        $ext = $parsed['extensions'] ?? null;
        if (is_array($ext) && isset($ext['subjectAltName']) && is_string($ext['subjectAltName'])) {
            $raw = (string)$ext['subjectAltName'];
            $parts = array_map('trim', explode(',', $raw));
            foreach ($parts as $p) {
                if ($p === '') continue;
                // Common forms: "DNS:example.com" | "IP Address:1.2.3.4"
                if (stripos($p, 'DNS:') === 0) {
                    $v = trim(substr($p, 4));
                    if ($v !== '') $out[] = $v;
                } elseif (stripos($p, 'IP Address:') === 0) {
                    $v = trim(substr($p, strlen('IP Address:')));
                    if ($v !== '') $out[] = $v;
                }
            }
        }

        if (count($out) > 0) {
            return array_values(array_unique($out));
        }

        $subj = $parsed['subject'] ?? null;
        if (is_array($subj)) {
            $cn = $subj['CN'] ?? null;
            if (is_string($cn) && trim($cn) !== '') {
                return [trim($cn)];
            }
        }
        return [];
    }

    private static function normalizeHost(string $host): string {
        $h = strtolower(trim($host));
        // Remove trailing dot (FQDN form).
        $h = rtrim($h, '.');
        if ($h === '') return '';

        // If intl is available, normalize IDNs.
        if (function_exists('idn_to_ascii')) {
            $ascii = @idn_to_ascii($h, 0, INTL_IDNA_VARIANT_UTS46);
            if (is_string($ascii) && $ascii !== '') {
                $h = strtolower($ascii);
            }
        }
        return $h;
    }

    /**
     * Very small RFC 6125-style matcher:
     * - exact match, OR
     * - wildcard of the form *.example.com matches foo.example.com (one label only)
     */
    private static function dnsNameMatches(string $host, string $pattern): bool {
        $host = strtolower($host);
        $pattern = strtolower($pattern);

        if ($host === $pattern) return true;

        // Wildcard handling: only a single leading '*.' is supported.
        if (str_starts_with($pattern, '*.' )) {
            $suffix = substr($pattern, 2);
            if ($suffix === '') return false;
            if (!str_ends_with($host, '.' . $suffix)) return false;

            // Ensure wildcard covers exactly one label.
            $hostLabels = substr_count($host, '.');
            $suffixLabels = substr_count($suffix, '.');
            return $hostLabels === ($suffixLabels + 1);
        }

        return false;
    }
}

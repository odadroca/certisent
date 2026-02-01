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
     * Compute SPKI (SubjectPublicKeyInfo) sha256 for the leaf certificate.
     *
     * Returned value is base64(SHA-256(SPKI DER)). This is a Certinel-defined pin format.
     *
     * @return array{ok:bool,sha256_base64:?string,sha256_hex:?string,error:?string}
     */
    public static function computeSpkiSha256(string $certPem): array {
        $certPem = trim($certPem);
        if ($certPem === '') {
            return ['ok'=>false,'sha256_base64'=>null,'sha256_hex'=>null,'error'=>'empty_pem'];
        }

        $pub = @openssl_pkey_get_public($certPem);
        if ($pub === false) {
            return ['ok'=>false,'sha256_base64'=>null,'sha256_hex'=>null,'error'=>'openssl_pkey_get_public_failed'];
        }

        $det = openssl_pkey_get_details($pub);
        if (!is_array($det) || empty($det['key']) || !is_string($det['key'])) {
            return ['ok'=>false,'sha256_base64'=>null,'sha256_hex'=>null,'error'=>'openssl_pkey_details_failed'];
        }
        $pubPem = (string)$det['key'];
        $b64 = preg_replace('/-----BEGIN PUBLIC KEY-----|-----END PUBLIC KEY-----|\s+/', '', $pubPem);
        $b64 = is_string($b64) ? trim($b64) : '';
        if ($b64 === '') {
            return ['ok'=>false,'sha256_base64'=>null,'sha256_hex'=>null,'error'=>'spki_extract_failed'];
        }
        $der = base64_decode($b64, true);
        if (!is_string($der) || $der === '') {
            return ['ok'=>false,'sha256_base64'=>null,'sha256_hex'=>null,'error'=>'spki_base64_decode_failed'];
        }

        $bin = hash('sha256', $der, true);
        if (!is_string($bin) || strlen($bin) !== 32) {
            return ['ok'=>false,'sha256_base64'=>null,'sha256_hex'=>null,'error'=>'sha256_failed'];
        }
        return [
            'ok'=>true,
            'sha256_base64'=>base64_encode($bin),
            'sha256_hex'=>hash('sha256', $der),
            'error'=>null,
        ];
    }

    /**
     * Normalize a pin value (accepts optional 'sha256/' prefix).
     */
    public static function normalizeSpkiPin(string $pin): string {
        $p = trim($pin);
        if ($p === '') return '';
        $p = preg_replace('/\s+/', '', $p) ?? $p;
        if (stripos($p, 'sha256/') === 0) {
            $p = substr($p, 7);
        }
        if (stripos($p, 'sha256:') === 0) {
            $p = substr($p, 7);
        }
        return trim($p);
    }

    /**
     * Validate a normalized SPKI pin value (base64 sha256 digest).
     */
    public static function isValidSpkiPin(string $pin): bool {
        $p = self::normalizeSpkiPin($pin);
        if ($p === '') return false;
        if (!preg_match('/^[A-Za-z0-9+\/]+=*$/', $p)) return false;
        // SHA-256 digest is 32 bytes; base64 is typically 44 chars with '=' padding.
        if (strlen($p) < 43 || strlen($p) > 64) return false;
        $raw = base64_decode($p, true);
        return is_string($raw) && strlen($raw) === 32;
    }

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
     * Validate certificate chain trust using the system CA bundle.
     *
     * This is intentionally separate from CertFetcher (which keeps verify_peer=false to observe served certs).
     * The probe validates the chain only (no hostname verification here; hostname identity is checked separately).
     *
     * @return array{ok:bool,category:?string,error:?string,type:string,method:string,detail:array<string,mixed>}
     *   - ok: true when chain trust is valid
     *   - category: tls_self_signed | tls_untrusted_root | tls_untrusted_unknown (only when type=untrusted)
     *   - type: ok | untrusted | probe_error
     */
    public static function validateTrust(string $host, int $port): array {
        $host = trim($host);
        if ($host === '') {
            return ['ok'=>false,'category'=>'tls_untrusted_unknown','error'=>'empty_host','type'=>'probe_error','method'=>'none','detail'=>[]];
        }
        if ($port <= 0 || $port > 65535) $port = 443;

        if (function_exists('curl_init')) {
            return self::trustViaCurl($host, $port);
        }
        return self::trustViaStream($host, $port);
    }

    private static function trustViaCurl(string $host, int $port): array {
        $url = 'https://' . $host . ($port !== 443 ? (':' . $port) : '') . '/';

        $connectTimeout = (int)cfg('TLS_TRUST_CONNECT_TIMEOUT_SECS', 4);
        $timeout = (int)cfg('TLS_TRUST_TIMEOUT_SECS', 6);
        if ($connectTimeout < 1) $connectTimeout = 1;
        if ($timeout < $connectTimeout) $timeout = $connectTimeout;

        $ca = trim((string)cfg('TLS_CA_BUNDLE', ''));

        $ch = curl_init();
        if ($ch === false) {
            return ['ok'=>false,'category'=>'tls_untrusted_unknown','error'=>'curl_init_failed','type'=>'probe_error','method'=>'curl','detail'=>[]];
        }

        curl_setopt_array($ch, [
            CURLOPT_URL => $url,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HEADER => false,
            CURLOPT_NOBODY => true,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_CONNECTTIMEOUT => $connectTimeout,
            CURLOPT_TIMEOUT => $timeout,

            // Trust validation (chain trust); no hostname verification here.
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 0,

            CURLOPT_USERAGENT => 'certinel-trust-probe/0.7.3',
        ]);

        if ($ca !== '') {
            curl_setopt($ch, CURLOPT_CAINFO, $ca);
        }

        $ok = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = $errno !== 0 ? (string)curl_error($ch) : null;
        $httpInfo = defined('CURLINFO_RESPONSE_CODE') ? CURLINFO_RESPONSE_CODE : CURLINFO_HTTP_CODE;
        $httpCode = (int)curl_getinfo($ch, $httpInfo);

        $verifyResult = null;
        if (defined('CURLINFO_SSL_VERIFYRESULT')) {
            $vr = curl_getinfo($ch, CURLINFO_SSL_VERIFYRESULT);
            if (is_int($vr)) $verifyResult = $vr;
        }

        curl_close($ch);

        if ($ok !== false && $errno === 0) {
            return ['ok'=>true,'category'=>null,'error'=>null,'type'=>'ok','method'=>'curl','detail'=>['http_code'=>$httpCode,'verify_result'=>$verifyResult]];
        }

        // Heuristic: treat OpenSSL verify errors as trust failures; everything else is a probe error.
        $isTrustErr = ($errno === 60) || (is_int($verifyResult) && $verifyResult !== 0) || (
            $err !== null && self::looksLikeTrustError($err)
        );

        if ($isTrustErr) {
            $cat = self::classifyTrustError($verifyResult, $err);
            $msg = self::shortError('curl:' . $errno . ' ' . ($err ?? 'ssl_verify_failed'));
            return ['ok'=>false,'category'=>$cat,'error'=>$msg,'type'=>'untrusted','method'=>'curl','detail'=>['errno'=>$errno,'http_code'=>$httpCode,'verify_result'=>$verifyResult]];
        }

        $msg = self::shortError('curl:' . $errno . ' ' . ($err ?? 'probe_failed'));
        return ['ok'=>false,'category'=>null,'error'=>$msg,'type'=>'probe_error','method'=>'curl','detail'=>['errno'=>$errno,'http_code'=>$httpCode,'verify_result'=>$verifyResult]];
    }

    private static function trustViaStream(string $host, int $port): array {
        $connectTimeout = (int)cfg('TLS_TRUST_CONNECT_TIMEOUT_SECS', 4);
        $timeout = (int)cfg('TLS_TRUST_TIMEOUT_SECS', 6);
        if ($connectTimeout < 1) $connectTimeout = 1;
        if ($timeout < $connectTimeout) $timeout = $connectTimeout;
        $ca = trim((string)cfg('TLS_CA_BUNDLE', ''));

        $ssl = [
            'verify_peer' => true,
            'verify_peer_name' => false, // chain trust only; hostname checked separately.
            'allow_self_signed' => false,
            'SNI_enabled' => true,
            'peer_name' => $host,
            'disable_compression' => true,
        ];
        if ($ca !== '') {
            $ssl['cafile'] = $ca;
        }
        $ctx = stream_context_create(['ssl' => $ssl]);

        $errno = 0;
        $errstr = '';
        $captured = '';
        set_error_handler(function(int $severity, string $message) use (&$captured): bool {
            $captured = $message;
            return true;
        });
        $fp = @stream_socket_client('ssl://' . $host . ':' . $port, $errno, $errstr, $connectTimeout, STREAM_CLIENT_CONNECT, $ctx);
        restore_error_handler();

        if ($fp === false) {
            $msg = trim($errstr !== '' ? $errstr : ($captured !== '' ? $captured : 'connect_failed'));

            // Drain a small part of the OpenSSL error stack for diagnostics.
            $ossl = [];
            for ($i=0; $i<2; $i++) {
                $e = openssl_error_string();
                if ($e === false) break;
                $ossl[] = $e;
            }
            if (!empty($ossl)) {
                $msg .= ' | openssl: ' . implode('; ', $ossl);
            }

            $isTrustErr = self::looksLikeTrustError($msg);
            if ($isTrustErr) {
                $cat = self::classifyTrustError(null, $msg);
                return ['ok'=>false,'category'=>$cat,'error'=>self::shortError($msg),'type'=>'untrusted','method'=>'stream','detail'=>['errno'=>$errno]];
            }
            return ['ok'=>false,'category'=>null,'error'=>self::shortError($msg),'type'=>'probe_error','method'=>'stream','detail'=>['errno'=>$errno]];
        }

        // Handshake succeeded with trust enabled.
        stream_set_timeout($fp, $timeout);
        fclose($fp);
        return ['ok'=>true,'category'=>null,'error'=>null,'type'=>'ok','method'=>'stream','detail'=>[]];
    }

    private static function looksLikeTrustError(string $msg): bool {
        $m = strtolower($msg);
        return str_contains($m, 'self signed')
            || str_contains($m, 'unable to get local issuer')
            || str_contains($m, 'unable to verify')
            || str_contains($m, 'certificate')
            || str_contains($m, 'unknown ca')
            || str_contains($m, 'certificate chain')
            || str_contains($m, 'peer certificate');
    }

    private static function classifyTrustError($verifyResult, ?string $msg): string {
        if (is_int($verifyResult)) {
            if ($verifyResult === 18) return 'tls_self_signed';
            if (in_array($verifyResult, [19, 20, 21, 27], true)) return 'tls_untrusted_root';
        }
        $m = strtolower((string)($msg ?? ''));
        if (str_contains($m, 'self signed certificate') && !str_contains($m, 'in certificate chain')) {
            return 'tls_self_signed';
        }
        if (str_contains($m, 'self signed certificate in certificate chain')
            || str_contains($m, 'unable to get local issuer')
            || str_contains($m, 'unable to verify the first certificate')
            || str_contains($m, 'unknown ca')
            || str_contains($m, 'certificate chain')) {
            return 'tls_untrusted_root';
        }
        return 'tls_untrusted_unknown';
    }

    private static function shortError(string $msg): string {
        $msg = trim(str_replace(["\r", "\n", "\t"], ' ', $msg));
        $msg = preg_replace('/\s+/', ' ', $msg) ?? $msg;
        if (strlen($msg) <= 255) return $msg;
        return substr($msg, 0, 252) . '...';
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

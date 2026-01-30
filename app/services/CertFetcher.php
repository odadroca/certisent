<?php
declare(strict_types=1);

final class CertFetcher {

    /**
     * Fetch the leaf certificate presented by the server (SNI enabled).
     * Returns array with:
     *  - ok (bool)
     *  - error (string|null)
     *  - pem (string|null)
     *  - parsed (array|null)  // openssl_x509_parse output
     *  - fingerprint_sha256 (string|null)
     */
    public static function fetch(string $host, int $port = 443): array {
        $policy = SsrfPolicy::evaluateTarget($host, $port);
        if (!$policy['ok']) {
            return [
                'ok' => false,
                'error' => 'ssrf_blocked: ' . (string)($policy['reason'] ?? 'blocked'),
                'pem' => null,
                'parsed' => null,
                'fingerprint_sha256' => null,
            ];
        }

        $ctx = stream_context_create([
            'ssl' => [
                'capture_peer_cert' => true,
                'SNI_enabled' => true,
                'peer_name' => $host,
                // Do not verify in v0: we are observing what the endpoint serves.
                'verify_peer' => false,
                'verify_peer_name' => false,
                'allow_self_signed' => true,
            ],
        ]);

        $timeout = (int)cfg('TLS_CONNECT_TIMEOUT_SECS', 7);
        $remote = sprintf('ssl://%s:%d', $host, $port);

        $errNo = 0;
        $errStr = '';
        $client = @stream_socket_client($remote, $errNo, $errStr, $timeout, STREAM_CLIENT_CONNECT, $ctx);
        if ($client === false) {
            return ['ok'=>false, 'error'=>"connect_failed: {$errStr} ({$errNo})", 'pem'=>null, 'parsed'=>null, 'fingerprint_sha256'=>null];
        }

        stream_set_timeout($client, (int)cfg('TLS_READ_TIMEOUT_SECS', 7));
        $params = stream_context_get_params($client);
        $cert = $params['options']['ssl']['peer_certificate'] ?? null;
        if (!$cert) {
            fclose($client);
            return ['ok'=>false, 'error'=>"no_peer_certificate", 'pem'=>null, 'parsed'=>null, 'fingerprint_sha256'=>null];
        }

        $pem = '';
        if (!openssl_x509_export($cert, $pem)) {
            fclose($client);
            return ['ok'=>false, 'error'=>"openssl_export_failed", 'pem'=>null, 'parsed'=>null, 'fingerprint_sha256'=>null];
        }

        $parsed = openssl_x509_parse($cert);
        $finger = openssl_x509_fingerprint($cert, 'sha256');
        fclose($client);

        return ['ok'=>true, 'error'=>null, 'pem'=>$pem, 'parsed'=>$parsed ?: null, 'fingerprint_sha256'=>$finger ?: null];
    }
}

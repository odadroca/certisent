<?php
declare(strict_types=1);

use PHPUnit\Framework\TestCase;
use PHPUnit\Framework\Attributes\DataProvider;

final class TlsValidatorTest extends TestCase {

    /**
     * Generate a throwaway self-signed cert + private key for the test process.
     * Returned PEM is suitable for openssl_pkey_get_public().
     *
     * @return array{cert:string, parsed:array<string,mixed>}
     */
    private static function makeSelfSignedCert(string $cn, array $sanDns = []): array {
        $config = [
            'digest_alg' => 'sha256',
            'private_key_bits' => 2048,
            'private_key_type' => OPENSSL_KEYTYPE_RSA,
        ];

        $key = openssl_pkey_new($config);
        if ($key === false) {
            throw new RuntimeException('openssl_pkey_new failed');
        }

        $dn = ['commonName' => $cn];
        $csr = openssl_csr_new($dn, $key, $config);
        if ($csr === false) {
            throw new RuntimeException('openssl_csr_new failed');
        }

        // SAN extension is best-effort; tests that need SAN can synthesize the parsed
        // array directly to avoid relying on openssl.cnf side effects in CI runners.
        $x509 = openssl_csr_sign($csr, null, $key, 1, $config);
        if ($x509 === false) {
            throw new RuntimeException('openssl_csr_sign failed');
        }

        $pem = '';
        openssl_x509_export($x509, $pem);

        $parsed = openssl_x509_parse($x509, true) ?: [];

        // Inject SAN entries into the parsed structure so hostname tests are
        // independent of the runner's openssl.cnf.
        if (!empty($sanDns)) {
            $san = implode(', ', array_map(static fn(string $d) => 'DNS:' . $d, $sanDns));
            $parsed['extensions']['subjectAltName'] = $san;
        }

        return ['cert' => $pem, 'parsed' => $parsed];
    }

    public function test_computeSpkiSha256_returns_deterministic_base64_and_hex(): void {
        $made = self::makeSelfSignedCert('cert.test');
        $a = TlsValidator::computeSpkiSha256($made['cert']);
        $b = TlsValidator::computeSpkiSha256($made['cert']);

        self::assertTrue($a['ok']);
        self::assertSame($a['sha256_base64'], $b['sha256_base64'], 'same input must yield same digest');
        self::assertSame($a['sha256_hex'], $b['sha256_hex']);

        $raw = base64_decode((string)$a['sha256_base64'], true);
        self::assertIsString($raw);
        self::assertSame(32, strlen($raw), 'sha256 digest must be 32 bytes');
        self::assertSame(bin2hex($raw), $a['sha256_hex']);
    }

    public function test_computeSpkiSha256_rejects_empty_and_garbage(): void {
        self::assertFalse(TlsValidator::computeSpkiSha256('')['ok']);
        self::assertFalse(TlsValidator::computeSpkiSha256('-----BEGIN CERTIFICATE-----nope-----END CERTIFICATE-----')['ok']);
    }

    public function test_computeSpkiSha256_differs_between_two_independent_keys(): void {
        $a = TlsValidator::computeSpkiSha256(self::makeSelfSignedCert('a.test')['cert']);
        $b = TlsValidator::computeSpkiSha256(self::makeSelfSignedCert('b.test')['cert']);
        self::assertTrue($a['ok']);
        self::assertTrue($b['ok']);
        self::assertNotSame($a['sha256_base64'], $b['sha256_base64']);
    }

    #[DataProvider('pinNormalizationProvider')]
    public function test_normalizeSpkiPin(string $input, string $expected): void {
        self::assertSame($expected, TlsValidator::normalizeSpkiPin($input));
    }

    public static function pinNormalizationProvider(): array {
        $b64 = 'abcdefghijklmnopqrstuvwxyzABCDEFGHIJKLMNOPQ=';
        return [
            'empty'                => ['', ''],
            'whitespace only'      => ["  \t\n", ''],
            'plain base64'         => [$b64, $b64],
            'sha256 slash prefix'  => ['sha256/' . $b64, $b64],
            'sha256 colon prefix'  => ['sha256:' . $b64, $b64],
            'prefix mixed case'    => ['SHA256/' . $b64, $b64],
            'inner whitespace'     => ["sha256/  " . $b64 . "\n", $b64],
        ];
    }

    public function test_isValidSpkiPin_accepts_real_digest(): void {
        $made = self::makeSelfSignedCert('valid.test');
        $r = TlsValidator::computeSpkiSha256($made['cert']);
        self::assertTrue($r['ok']);
        self::assertTrue(TlsValidator::isValidSpkiPin((string)$r['sha256_base64']));
        self::assertTrue(TlsValidator::isValidSpkiPin('sha256/' . $r['sha256_base64']));
    }

    #[DataProvider('invalidPinProvider')]
    public function test_isValidSpkiPin_rejects_invalid(string $pin): void {
        self::assertFalse(TlsValidator::isValidSpkiPin($pin));
    }

    public static function invalidPinProvider(): array {
        return [
            'empty'           => [''],
            'too short'       => ['abc='],
            'non base64'      => ['!!!!notbase64!!!!'],
            'wrong byte len'  => [base64_encode(str_repeat('x', 16))], // decodes to 16 bytes, not 32
        ];
    }

    public function test_validateHostname_exact_match_via_san(): void {
        $made = self::makeSelfSignedCert('cert.test', ['example.com', 'www.example.com']);
        $r = TlsValidator::validateHostname('example.com', $made['parsed']);
        self::assertTrue($r['ok']);
        self::assertSame('example.com', $r['matched']);
    }

    public function test_validateHostname_wildcard_matches_one_label(): void {
        $made = self::makeSelfSignedCert('cert.test', ['*.example.com']);
        $ok = TlsValidator::validateHostname('foo.example.com', $made['parsed']);
        self::assertTrue($ok['ok']);

        $tooDeep = TlsValidator::validateHostname('foo.bar.example.com', $made['parsed']);
        self::assertFalse($tooDeep['ok'], 'wildcard must not span multiple labels');

        $apex = TlsValidator::validateHostname('example.com', $made['parsed']);
        self::assertFalse($apex['ok'], 'wildcard must not match the apex');
    }

    public function test_validateHostname_falls_back_to_cn_when_no_san(): void {
        $parsed = ['subject' => ['CN' => 'legacy.test']];
        $hit = TlsValidator::validateHostname('legacy.test', $parsed);
        self::assertTrue($hit['ok']);
        self::assertSame('legacy.test', $hit['matched']);

        $miss = TlsValidator::validateHostname('other.test', $parsed);
        self::assertFalse($miss['ok']);
        self::assertSame('hostname_mismatch', $miss['error']);
    }

    public function test_validateHostname_rejects_empty_host(): void {
        $made = self::makeSelfSignedCert('cert.test', ['example.com']);
        $r = TlsValidator::validateHostname('', $made['parsed']);
        self::assertFalse($r['ok']);
        self::assertSame('empty_host', $r['error']);
    }

    public function test_validateHostname_reports_no_identity_names(): void {
        $r = TlsValidator::validateHostname('example.com', []);
        self::assertFalse($r['ok']);
        self::assertSame('no_identity_names', $r['error']);
    }
}

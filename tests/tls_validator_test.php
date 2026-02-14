<?php
declare(strict_types=1);

// Tests for TlsValidator pure/deterministic methods.

// --- normalizeSpkiPin ---

assert_eq('', TlsValidator::normalizeSpkiPin(''), 'normalizeSpkiPin: empty string');
assert_eq('', TlsValidator::normalizeSpkiPin('  '), 'normalizeSpkiPin: whitespace only');
assert_eq('abc123+/=', TlsValidator::normalizeSpkiPin('sha256/abc123+/='), 'normalizeSpkiPin: sha256/ prefix');
assert_eq('abc123+/=', TlsValidator::normalizeSpkiPin('sha256:abc123+/='), 'normalizeSpkiPin: sha256: prefix');
assert_eq('abc123+/=', TlsValidator::normalizeSpkiPin('SHA256/abc123+/='), 'normalizeSpkiPin: case-insensitive prefix');
assert_eq('abc123+/=', TlsValidator::normalizeSpkiPin(' abc123+/= '), 'normalizeSpkiPin: trim whitespace');
assert_eq('rawvalue', TlsValidator::normalizeSpkiPin('rawvalue'), 'normalizeSpkiPin: no prefix');

// --- isValidSpkiPin ---

// Valid: base64 of 32 bytes => 44 chars with '=' padding
$valid32 = base64_encode(str_repeat("\x01", 32));
assert_true(TlsValidator::isValidSpkiPin($valid32), 'isValidSpkiPin: valid 32-byte digest');
assert_true(TlsValidator::isValidSpkiPin('sha256/' . $valid32), 'isValidSpkiPin: valid with sha256/ prefix');

// Invalid: wrong length (16 bytes)
$short = base64_encode(str_repeat("\x01", 16));
assert_false(TlsValidator::isValidSpkiPin($short), 'isValidSpkiPin: 16-byte digest rejected');

// Invalid: empty
assert_false(TlsValidator::isValidSpkiPin(''), 'isValidSpkiPin: empty rejected');

// Invalid: not base64
assert_false(TlsValidator::isValidSpkiPin('!!!not-base64!!!'), 'isValidSpkiPin: bad chars rejected');

// --- validateHostname ---

$parsedSan = [
    'subject' => ['CN' => 'example.com'],
    'extensions' => ['subjectAltName' => 'DNS:example.com, DNS:www.example.com, DNS:*.example.com'],
];

$r = TlsValidator::validateHostname('example.com', $parsedSan);
assert_true($r['ok'], 'validateHostname: exact SAN match');

$r = TlsValidator::validateHostname('www.example.com', $parsedSan);
assert_true($r['ok'], 'validateHostname: exact SAN match (www)');

$r = TlsValidator::validateHostname('sub.example.com', $parsedSan);
assert_true($r['ok'], 'validateHostname: wildcard *.example.com matches sub.example.com');

$r = TlsValidator::validateHostname('deep.sub.example.com', $parsedSan);
assert_false($r['ok'], 'validateHostname: wildcard does not match multi-label');

$r = TlsValidator::validateHostname('other.net', $parsedSan);
assert_false($r['ok'], 'validateHostname: unrelated host rejected');
assert_eq('hostname_mismatch', $r['error'], 'validateHostname: error type is hostname_mismatch');

// CN fallback when no SAN
$parsedCn = [
    'subject' => ['CN' => 'fallback.example.com'],
    'extensions' => [],
];
$r = TlsValidator::validateHostname('fallback.example.com', $parsedCn);
assert_true($r['ok'], 'validateHostname: CN fallback match');

$r = TlsValidator::validateHostname('other.example.com', $parsedCn);
assert_false($r['ok'], 'validateHostname: CN fallback mismatch');

// Empty host
$r = TlsValidator::validateHostname('', $parsedSan);
assert_false($r['ok'], 'validateHostname: empty host rejected');
assert_eq('empty_host', $r['error'], 'validateHostname: empty host error');

// No identity names
$parsedEmpty = ['subject' => [], 'extensions' => []];
$r = TlsValidator::validateHostname('example.com', $parsedEmpty);
assert_false($r['ok'], 'validateHostname: no identity names');
assert_eq('no_identity_names', $r['error'], 'validateHostname: no_identity_names error');

// IP address in SAN
$parsedIp = [
    'subject' => ['CN' => 'server'],
    'extensions' => ['subjectAltName' => 'IP Address:192.168.1.1, DNS:server.local'],
];
$r = TlsValidator::validateHostname('192.168.1.1', $parsedIp);
assert_true($r['ok'], 'validateHostname: IP SAN match');

$r = TlsValidator::validateHostname('192.168.1.2', $parsedIp);
assert_false($r['ok'], 'validateHostname: IP SAN mismatch');

$r = TlsValidator::validateHostname('server.local', $parsedIp);
assert_true($r['ok'], 'validateHostname: DNS SAN match alongside IP');

// FQDN trailing dot normalization
$r = TlsValidator::validateHostname('example.com.', $parsedSan);
assert_true($r['ok'], 'validateHostname: trailing dot normalized');

// Case insensitivity
$r = TlsValidator::validateHostname('EXAMPLE.COM', $parsedSan);
assert_true($r['ok'], 'validateHostname: case insensitive match');

// --- computeSpkiSha256 ---

$r = TlsValidator::computeSpkiSha256('');
assert_false($r['ok'], 'computeSpkiSha256: empty PEM rejected');
assert_eq('empty_pem', $r['error'], 'computeSpkiSha256: empty_pem error');

$r = TlsValidator::computeSpkiSha256('not-a-cert');
assert_false($r['ok'], 'computeSpkiSha256: garbage PEM rejected');

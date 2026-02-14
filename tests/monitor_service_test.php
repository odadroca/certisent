<?php
declare(strict_types=1);

// Tests for MonitorService::parseUrl() — pure function, no DB.

// --- Valid URLs ---

$r = MonitorService::parseUrl('https://example.com');
assert_eq('example.com', $r['host'], 'parseUrl: host from https URL');
assert_eq(443, $r['port'], 'parseUrl: default port 443');
assert_eq('https://example.com/', $r['url'], 'parseUrl: normalized URL');

$r = MonitorService::parseUrl('https://example.com:8443');
assert_eq('example.com', $r['host'], 'parseUrl: host with custom port');
assert_eq(8443, $r['port'], 'parseUrl: custom port extracted');
assert_eq('https://example.com:8443/', $r['url'], 'parseUrl: normalized with port');

// Auto-prefix https:// when missing
$r = MonitorService::parseUrl('example.com');
assert_eq('example.com', $r['host'], 'parseUrl: auto-prefix host');
assert_eq(443, $r['port'], 'parseUrl: auto-prefix default port');

$r = MonitorService::parseUrl('example.com:9443');
assert_eq('example.com', $r['host'], 'parseUrl: auto-prefix with port');
assert_eq(9443, $r['port'], 'parseUrl: auto-prefix port extracted');

// Subdomain
$r = MonitorService::parseUrl('https://api.sub.example.com');
assert_eq('api.sub.example.com', $r['host'], 'parseUrl: subdomain host');

// --- Invalid URLs ---

assert_throws(fn() => MonitorService::parseUrl(''), 'parseUrl: empty URL throws');
assert_throws(fn() => MonitorService::parseUrl('   '), 'parseUrl: whitespace URL throws');
assert_throws(fn() => MonitorService::parseUrl('http://example.com'), 'parseUrl: http scheme rejected');

// Trailing whitespace handled
$r = MonitorService::parseUrl('  https://example.com  ');
assert_eq('example.com', $r['host'], 'parseUrl: trimmed whitespace');

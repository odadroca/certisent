<?php
declare(strict_types=1);

final class SsrfPolicy {

    /**
     * Evaluate whether a (host, port) target is allowed under the configured SSRF policy.
     *
     * Returns array:
     *  - ok (bool)
     *  - reason (string|null)
     *  - mode (string)
     *  - resolved_ips (array<int,string>)
     */
    public static function evaluateTarget(string $host, int $port): array {
        $mode = strtolower(trim((string)cfg('SSRF_MODE', 'legacy')));
        if (!in_array($mode, ['legacy', 'public_only', 'allowlist_private'], true)) {
            $mode = 'legacy';
        }

        // Legacy mode preserves v0.4.x behavior (no SSRF blocking).
        if ($mode === 'legacy') {
            return ['ok' => true, 'reason' => null, 'mode' => $mode, 'resolved_ips' => []];
        }

        // Optional allowlist primitives (empty means "no special allows").
        $allowCidrs = self::parseCsv((string)cfg('SSRF_ALLOW_CIDRS', ''));
        $allowHosts = self::parseCsv((string)cfg('SSRF_ALLOW_HOSTS', ''));
        $allowPorts = self::parseCsv((string)cfg('SSRF_ALLOW_PORTS', ''));

        if (count($allowPorts) > 0) {
            $allowed = false;
            foreach ($allowPorts as $p) {
                if ((int)$p === (int)$port) {
                    $allowed = true;
                    break;
                }
            }
            if (!$allowed) {
                return [
                    'ok' => false,
                    'reason' => "port_not_allowed: {$port}",
                    'mode' => $mode,
                    'resolved_ips' => [],
                ];
            }
        }

        $ips = self::resolveHostToIps($host);
        if (count($ips) === 0) {
            return [
                'ok' => false,
                'reason' => 'dns_resolution_failed',
                'mode' => $mode,
                'resolved_ips' => [],
            ];
        }

        // public_only: block any private/reserved results.
        if ($mode === 'public_only') {
            foreach ($ips as $ip) {
                if (self::isPrivateOrReservedIp($ip)) {
                    return [
                        'ok' => false,
                        'reason' => "private_or_reserved_ip: {$ip}",
                        'mode' => $mode,
                        'resolved_ips' => $ips,
                    ];
                }
            }
            return ['ok' => true, 'reason' => null, 'mode' => $mode, 'resolved_ips' => $ips];
        }

        // allowlist_private: private/reserved blocked unless allowlisted.
        foreach ($ips as $ip) {
            if (!self::isPrivateOrReservedIp($ip)) {
                continue;
            }

            $hostAllowed = self::isHostAllowlisted($host, $allowHosts);
            $ipAllowed = self::isIpAllowlisted($ip, $allowCidrs);
            if (!$hostAllowed && !$ipAllowed) {
                return [
                    'ok' => false,
                    'reason' => "private_or_reserved_ip_not_allowlisted: {$ip}",
                    'mode' => $mode,
                    'resolved_ips' => $ips,
                ];
            }
        }

        return ['ok' => true, 'reason' => null, 'mode' => $mode, 'resolved_ips' => $ips];
    }

    /**
     * Evaluate whether a webhook URL is allowed under WEBHOOK_MODE.
     *
     * Modes:
     * - legacy: allow any URL (v0.4.x behavior)
     * - public_only: require https and block private/reserved
     * - allowlist: require https and allow private/reserved only if allowlisted via SSRF_ALLOW_*
     *
     * @return array{ok:bool,reason:?string,mode:string,resolved_ips:array<int,string>}
     */
    public static function evaluateWebhookUrl(string $url): array {
        $mode = strtolower(trim((string)cfg('WEBHOOK_MODE', 'legacy')));
        if (!in_array($mode, ['legacy', 'public_only', 'allowlist'], true)) {
            $mode = 'legacy';
        }

        if ($mode === 'legacy') {
            return ['ok' => true, 'reason' => null, 'mode' => $mode, 'resolved_ips' => []];
        }

        $p = @parse_url($url);
        if (!is_array($p)) {
            return ['ok' => false, 'reason' => 'invalid_url', 'mode' => $mode, 'resolved_ips' => []];
        }

        $scheme = strtolower((string)($p['scheme'] ?? ''));
        if ($scheme !== 'https') {
            return ['ok' => false, 'reason' => 'scheme_not_https', 'mode' => $mode, 'resolved_ips' => []];
        }

        $host = (string)($p['host'] ?? '');
        if (trim($host) === '') {
            return ['ok' => false, 'reason' => 'missing_host', 'mode' => $mode, 'resolved_ips' => []];
        }

        $port = (int)($p['port'] ?? 443);
        if ($port <= 0 || $port > 65535) {
            return ['ok' => false, 'reason' => 'invalid_port', 'mode' => $mode, 'resolved_ips' => []];
        }

        $allowCidrs = self::parseCsv((string)cfg('SSRF_ALLOW_CIDRS', ''));
        $allowHosts = self::parseCsv((string)cfg('SSRF_ALLOW_HOSTS', ''));
        $allowPorts = self::parseCsv((string)cfg('SSRF_ALLOW_PORTS', ''));

        $ips = self::resolveHostToIps($host);
        if (count($ips) === 0) {
            return ['ok' => false, 'reason' => 'dns_resolution_failed', 'mode' => $mode, 'resolved_ips' => []];
        }

        if ($mode === 'public_only') {
            foreach ($ips as $ip) {
                if (self::isPrivateOrReservedIp($ip)) {
                    return ['ok' => false, 'reason' => "private_or_reserved_ip: {$ip}", 'mode' => $mode, 'resolved_ips' => $ips];
                }
            }
            return ['ok' => true, 'reason' => null, 'mode' => $mode, 'resolved_ips' => $ips];
        }

        // allowlist mode: only restrict private/reserved.
        $hasPrivate = false;
        foreach ($ips as $ip) {
            if (self::isPrivateOrReservedIp($ip)) {
                $hasPrivate = true;
                $hostAllowed = self::isHostAllowlisted($host, $allowHosts);
                $ipAllowed = self::isIpAllowlisted($ip, $allowCidrs);
                if (!$hostAllowed && !$ipAllowed) {
                    return ['ok' => false, 'reason' => "private_or_reserved_ip_not_allowlisted: {$ip}", 'mode' => $mode, 'resolved_ips' => $ips];
                }
            }
        }

        // If a port allowlist is configured, apply it only to private/reserved webhook targets.
        if ($hasPrivate && count($allowPorts) > 0) {
            $allowed = false;
            foreach ($allowPorts as $p0) {
                if ((int)$p0 === $port) { $allowed = true; break; }
            }
            if (!$allowed) {
                return ['ok' => false, 'reason' => "port_not_allowed: {$port}", 'mode' => $mode, 'resolved_ips' => $ips];
            }
        }

        return ['ok' => true, 'reason' => null, 'mode' => $mode, 'resolved_ips' => $ips];
    }

    /** @return array<int,string> */
    private static function parseCsv(string $s): array {
        $s = trim($s);
        if ($s === '') return [];
        $parts = array_map('trim', explode(',', $s));
        $out = [];
        foreach ($parts as $p) {
            if ($p === '') continue;
            $out[] = $p;
        }
        return array_values(array_unique($out));
    }

    /** @return array<int,string> */
    private static function resolveHostToIps(string $host): array {
        $host = trim($host);
        if ($host === '') return [];

        // Literal IP.
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            return [$host];
        }

        $ips = [];

        // A/AAAA records if available.
        if (function_exists('dns_get_record')) {
            $recs = @dns_get_record($host, DNS_A + DNS_AAAA);
            if (is_array($recs)) {
                foreach ($recs as $r) {
                    if (isset($r['ip']) && is_string($r['ip']) && filter_var($r['ip'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $r['ip'];
                    } elseif (isset($r['ipv6']) && is_string($r['ipv6']) && filter_var($r['ipv6'], FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
                        $ips[] = $r['ipv6'];
                    }
                }
            }
        }

        // Fallback: IPv4 only.
        if (count($ips) === 0) {
            $v4s = @gethostbynamel($host);
            if (is_array($v4s)) {
                foreach ($v4s as $ip) {
                    if (is_string($ip) && filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
                        $ips[] = $ip;
                    }
                }
            }
        }

        $ips = array_values(array_unique($ips));
        return $ips;
    }

    private static function isHostAllowlisted(string $host, array $allowHosts): bool {
        $h = strtolower(trim($host));
        if ($h === '') return false;

        foreach ($allowHosts as $pat) {
            $p = strtolower(trim((string)$pat));
            if ($p === '') continue;

            if ($p === $h) {
                return true;
            }

            // Suffix match: ".example.com" or "*.example.com".
            if (str_starts_with($p, '*.')) {
                $suffix = substr($p, 1); // keep leading '.'
                if ($suffix !== '' && str_ends_with($h, $suffix)) {
                    return true;
                }
            } elseif (str_starts_with($p, '.')) {
                if (str_ends_with($h, $p)) {
                    return true;
                }
            }
        }

        return false;
    }

    private static function isIpAllowlisted(string $ip, array $allowCidrs): bool {
        foreach ($allowCidrs as $cidr) {
            $cidr = trim((string)$cidr);
            if ($cidr === '') continue;
            if (self::cidrMatch($ip, $cidr)) {
                return true;
            }
        }
        return false;
    }

    private static function cidrMatch(string $ip, string $cidr): bool {
        $cidr = trim($cidr);
        if ($cidr === '') return false;

        if (!str_contains($cidr, '/')) {
            // Allow specifying a single IP.
            return $ip === $cidr;
        }

        [$net, $bitsStr] = explode('/', $cidr, 2);
        $net = trim($net);
        $bits = (int)trim($bitsStr);

        if (!filter_var($net, FILTER_VALIDATE_IP) || !filter_var($ip, FILTER_VALIDATE_IP)) {
            return false;
        }

        $netBin = @inet_pton($net);
        $ipBin = @inet_pton($ip);
        if ($netBin === false || $ipBin === false) return false;

        // Must be same address family.
        if (strlen($netBin) !== strlen($ipBin)) return false;

        $maxBits = strlen($netBin) * 8;
        if ($bits < 0 || $bits > $maxBits) return false;

        $bytes = intdiv($bits, 8);
        $remBits = $bits % 8;

        if ($bytes > 0) {
            if (substr($netBin, 0, $bytes) !== substr($ipBin, 0, $bytes)) {
                return false;
            }
        }

        if ($remBits === 0) {
            return true;
        }

        $mask = (0xFF << (8 - $remBits)) & 0xFF;
        $netByte = ord($netBin[$bytes]);
        $ipByte = ord($ipBin[$bytes]);
        return (($netByte & $mask) === ($ipByte & $mask));
    }

    private static function isPrivateOrReservedIp(string $ip): bool {
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4)) {
            return self::isPrivateOrReservedIpv4($ip);
        }
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            return self::isPrivateOrReservedIpv6($ip);
        }
        return true;
    }

    private static function isPrivateOrReservedIpv4(string $ip): bool {
        $blocked = [
            '0.0.0.0/8',
            '10.0.0.0/8',
            '100.64.0.0/10',
            '127.0.0.0/8',
            '169.254.0.0/16',
            '172.16.0.0/12',
            '192.0.0.0/24',
            '192.0.2.0/24',
            '192.168.0.0/16',
            '198.18.0.0/15',
            '198.51.100.0/24',
            '203.0.113.0/24',
            '224.0.0.0/4',
            '240.0.0.0/4',
            '255.255.255.255/32',
        ];
        foreach ($blocked as $cidr) {
            if (self::cidrMatch($ip, $cidr)) return true;
        }
        return false;
    }

    private static function isPrivateOrReservedIpv6(string $ip): bool {
        $blocked = [
            '::/128',
            '::1/128',
            'fc00::/7',
            'fe80::/10',
            'ff00::/8',
            '2001:db8::/32',
        ];
        foreach ($blocked as $cidr) {
            if (self::cidrMatch($ip, $cidr)) return true;
        }
        return false;
    }
}

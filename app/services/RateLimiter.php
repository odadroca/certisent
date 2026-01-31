<?php
declare(strict_types=1);

/**
 * Coarse rate limiting (DB-backed).
 *
 * Goals:
 * - Defaults are high and should not impact typical use.
 * - Non-breaking if the DB migration was not applied: if the rate_limits table
 *   is missing or errors, rate limiting is bypassed (allow).
 * - Minimal state: fixed window counters with temporary blocks.
 */
final class RateLimiter {

    /**
     * Login throttling by source IP.
     * @return array{allowed:bool,retry_after:int,scope:string}
     */
    public static function checkLoginIp(string $ip): array {
        $ip = trim($ip);
        if ($ip === '') {
            return ['allowed'=>true,'retry_after'=>0,'scope'=>'login_ip'];
        }
        $max = (int)cfg('RATE_LIMIT_LOGIN_MAX', 60);
        $win = (int)cfg('RATE_LIMIT_LOGIN_WINDOW_SEC', 900);
        $out = self::consume('login_ip:' . $ip, $max, $win);
        $out['scope'] = 'login_ip';
        return $out;
    }

    /**
     * API throttling for /api/v1/*.
     *
     * Applies:
     * - per-IP limits always
     * - per-token limits when a Bearer token is present
     *
     * @return array{allowed:bool,retry_after:int,scope:string}
     */
    public static function checkApi(string $ip, ?string $bearerToken): array {
        $ip = trim($ip);
        $maxIp = (int)cfg('RATE_LIMIT_API_IP_MAX', 600);
        $winIp = (int)cfg('RATE_LIMIT_API_IP_WINDOW_SEC', 60);
        if ($ip !== '') {
            $r = self::consume('api_ip:' . $ip, $maxIp, $winIp);
            if (!$r['allowed']) {
                $r['scope'] = 'api_ip';
                return $r;
            }
        }

        $token = $bearerToken !== null ? trim($bearerToken) : '';
        if ($token !== '') {
            $maxTok = (int)cfg('RATE_LIMIT_API_TOKEN_MAX', 1200);
            $winTok = (int)cfg('RATE_LIMIT_API_TOKEN_WINDOW_SEC', 60);
            $hash = hash('sha256', $token);
            $r2 = self::consume('api_token:' . $hash, $maxTok, $winTok);
            $r2['scope'] = 'api_token';
            return $r2;
        }

        return ['allowed'=>true,'retry_after'=>0,'scope'=>'api'];
    }

    /**
     * Consume one unit from a per-key fixed-window rate limit.
     *
     * @return array{allowed:bool,retry_after:int}
     */
    private static function consume(string $key, int $max, int $windowSeconds): array {
        if ($max <= 0 || $windowSeconds <= 0) {
            return ['allowed'=>true,'retry_after'=>0];
        }

        try {
            $pdo = db();
            $nowTs = time();
            $nowUtc = db_now_utc();

            $pdo->beginTransaction();

            $sel = $pdo->prepare('SELECT `key`, window_start, window_seconds, `count`, blocked_until, last_block_at, blocked_count FROM rate_limits WHERE `key` = :k LIMIT 1 FOR UPDATE');
            $sel->execute([':k'=>$key]);
            $row = $sel->fetch();

            if (!$row) {
                $ins = $pdo->prepare('INSERT INTO rate_limits (`key`, window_start, window_seconds, `count`, blocked_until, last_block_at, blocked_count, updated_at)
                                      VALUES (:k, :ws, :wsec, :c, NULL, NULL, 0, :u)');
                $ins->execute([
                    ':k'=>$key,
                    ':ws'=>$nowUtc,
                    ':wsec'=>$windowSeconds,
                    ':c'=>1,
                    ':u'=>$nowUtc,
                ]);
                $pdo->commit();
                return ['allowed'=>true,'retry_after'=>0];
            }

            $blockedUntil = isset($row['blocked_until']) ? (string)$row['blocked_until'] : '';
            if ($blockedUntil !== '') {
                $buTs = strtotime($blockedUntil . ' UTC');
                if ($buTs !== false && $nowTs < $buTs) {
                    $pdo->commit();
                    return ['allowed'=>false,'retry_after'=>max(1, $buTs - $nowTs)];
                }
            }

            $wsTs = strtotime(((string)$row['window_start']) . ' UTC');
            if ($wsTs === false) $wsTs = $nowTs;
            $count = (int)($row['count'] ?? 0);

            $reset = ($nowTs - $wsTs) >= $windowSeconds;
            if ($reset) {
                $wsTs = $nowTs;
                $count = 0;
            }

            $count += 1;
            $newWindowStart = gmdate('Y-m-d H:i:s', $wsTs);

            $newBlockedUntil = null;
            $newLastBlockAt = null;
            $blockedCount = (int)($row['blocked_count'] ?? 0);

            $allowed = true;
            $retryAfter = 0;

            if ($count > $max) {
                $allowed = false;
                $newLastBlockAt = $nowUtc;
                $blockedCount += 1;
                $bu = $nowTs + $windowSeconds;
                $newBlockedUntil = gmdate('Y-m-d H:i:s', $bu);
                $retryAfter = max(1, $bu - $nowTs);
            }

            $upd = $pdo->prepare('UPDATE rate_limits
                                  SET window_start = :ws,
                                      window_seconds = :wsec,
                                      `count` = :c,
                                      blocked_until = :bu,
                                      last_block_at = COALESCE(:lba, last_block_at),
                                      blocked_count = :bc,
                                      updated_at = :u
                                  WHERE `key` = :k');
            $upd->execute([
                ':ws'=>$newWindowStart,
                ':wsec'=>$windowSeconds,
                ':c'=>$count,
                ':bu'=>$newBlockedUntil,
                ':lba'=>$newLastBlockAt,
                ':bc'=>$blockedCount,
                ':u'=>$nowUtc,
                ':k'=>$key,
            ]);

            $pdo->commit();
            return ['allowed'=>$allowed,'retry_after'=>$retryAfter];

        } catch (Throwable $e) {
            // Non-breaking fallback: if the table doesn't exist or DB is unavailable, allow.
            try {
                if (isset($pdo) && $pdo instanceof PDO && $pdo->inTransaction()) {
                    $pdo->rollBack();
                }
            } catch (Throwable $e2) {
                // ignore
            }
            return ['allowed'=>true,'retry_after'=>0];
        }
    }
}

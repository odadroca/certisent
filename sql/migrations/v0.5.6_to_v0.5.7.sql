-- v0.5.7: coarse rate limiting

CREATE TABLE IF NOT EXISTS rate_limits (
  `key` VARCHAR(190) NOT NULL,
  window_start DATETIME NOT NULL,
  window_seconds INT NOT NULL,
  `count` INT NOT NULL,
  blocked_until DATETIME NULL,
  last_block_at DATETIME NULL,
  blocked_count INT NOT NULL DEFAULT 0,
  updated_at DATETIME NOT NULL,
  PRIMARY KEY (`key`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

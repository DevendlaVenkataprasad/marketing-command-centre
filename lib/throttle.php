<?php
/**
 * Rate limits for the doors anyone can knock on: sign-in, password reset,
 * public forms and invitation replies.
 *
 * Each attempt is a row of (scope, key, time). A key is always stored as a
 * hash -- an address or an IP is never written down in the clear -- and rows
 * older than a day are swept away as new ones arrive, so the table stays
 * small without a cron job.
 *
 *   if (fabo_throttle_blocked('login-ip', fabo_client_ip(), 20, 900)) { refuse }
 *   ... on a failure:  fabo_throttle_hit('login-ip', fabo_client_ip());
 *   ... on success:    fabo_throttle_clear('login-acct', $email);
 */

require_once __DIR__ . '/db.php';

function fabo_throttle_install(): void
{
    static $done = false;
    if ($done) {
        return;
    }
    $done = true;
    fabo_db()->query("CREATE TABLE IF NOT EXISTS `auth_throttle` (
  `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
  `scope` VARCHAR(24) NOT NULL,
  `k` CHAR(64) NOT NULL,
  `at` DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (`id`),
  KEY `k_scope_key_at` (`scope`, `k`, `at`)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
}

/** The address the request came from, as the web server saw it. */
function fabo_client_ip(): string
{
    // REMOTE_ADDR only: X-Forwarded-For is written by the client and would let
    // anybody pick a fresh "address" for every guess.
    return (string) ($_SERVER['REMOTE_ADDR'] ?? 'unknown');
}

function fabo_throttle_key(string $scope, string $key): string
{
    return hash('sha256', $scope . '|' . mb_strtolower(trim($key)));
}

/** Records one attempt. */
function fabo_throttle_hit(string $scope, string $key): void
{
    try {
        fabo_throttle_install();
        fabo_exec('INSERT INTO auth_throttle (scope, k) VALUES (?, ?)', 'ss', [$scope, fabo_throttle_key($scope, $key)]);
        if (random_int(1, 50) === 1) {
            fabo_exec('DELETE FROM auth_throttle WHERE at < NOW() - INTERVAL 1 DAY');
        }
    } catch (Throwable $e) {
        error_log('FABO throttle write: ' . $e->getMessage());
    }
}

/** Attempts recorded for this key in the last $window seconds. */
function fabo_throttle_count(string $scope, string $key, int $window): int
{
    try {
        fabo_throttle_install();
        return (int) (fabo_one('SELECT COUNT(*) AS n FROM auth_throttle
                                 WHERE scope = ? AND k = ? AND at > NOW() - INTERVAL ? SECOND',
                               'ssi', [$scope, fabo_throttle_key($scope, $key), $window])['n'] ?? 0);
    } catch (Throwable $e) {
        error_log('FABO throttle read: ' . $e->getMessage());
        return 0;
    }
}

/** Has this key already used up its $limit attempts in the last $window seconds? */
function fabo_throttle_blocked(string $scope, string $key, int $limit, int $window): bool
{
    return fabo_throttle_count($scope, $key, $window) >= $limit;
}

/** Forgets a key's attempts -- after a successful sign-in, say. */
function fabo_throttle_clear(string $scope, string $key): void
{
    try {
        fabo_throttle_install();
        fabo_exec('DELETE FROM auth_throttle WHERE scope = ? AND k = ?', 'ss', [$scope, fabo_throttle_key($scope, $key)]);
    } catch (Throwable $e) {
        error_log('FABO throttle clear: ' . $e->getMessage());
    }
}

/** The sentence for a refusal. Deliberately the same whatever was refused. */
function fabo_throttle_words(): string
{
    return 'Too many attempts. Please wait 15 minutes and try again.';
}

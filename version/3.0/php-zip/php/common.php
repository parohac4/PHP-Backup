<?php
declare(strict_types=1);

function cfg(): array {
  $path = __DIR__ . '/config.php';
  if (function_exists('opcache_invalidate')) @opcache_invalidate($path, true);
  return require $path;
}
function no_cache(): void {
  header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
  header('Pragma: no-cache');
  header('X-Content-Type-Options: nosniff');
  header('Referrer-Policy: no-referrer');
}
function fail(int $code, string $msg): never {
  http_response_code($code); no_cache();
  header('Content-Type: text/plain; charset=UTF-8'); echo $msg; exit;
}
function allow_ip(array $allow): bool {
  if (!$allow) return true; $ip = $_SERVER['REMOTE_ADDR'] ?? ''; return in_array($ip, $allow, true);
}
function ensure_dir(string $d): void {
  if (!is_dir($d) && !@mkdir($d, 0775, true)) fail(500, "Cannot create dir: $d");
}
function norm(string $p): string { return '/' . ltrim(str_replace('\\','/',$p), '/'); }
function excluded(string $relNorm, array $ex): bool {
  foreach ($ex as $x) { $x = rtrim(str_replace('\\','/',$x),'/').'/'; if (str_contains($relNorm.'/', $x)) return true; }
  return false;
}
function require_auth(array $cfg): void {
  if (!allow_ip($cfg['allow_ips'])) fail(403, 'Forbidden (IP)');
  if (!empty($cfg['require_https']) && (($_SERVER['HTTPS'] ?? 'off') !== 'on')) fail(400, 'HTTPS required');

  // Token pouze z POST nebo z Authorization: Bearer (žádný GET)
  $token = $_POST['token'] ?? '';
  $token = is_string($token) ? trim($token) : '';
  if ($token === '') {
    $auth = $_SERVER['HTTP_AUTHORIZATION'] ?? ($_SERVER['Authorization'] ?? '');
    if (is_string($auth) && stripos($auth, 'Bearer ') === 0) {
      $token = trim(substr($auth, 7));
    }
  }
  $tCfg  = (string)$cfg['token'];

  if ($token === '' || $tCfg === '' || !hash_equals($tCfg, $token)) {
    fail(401, 'Unauthorized');
  }
}
function root_paths(array $cfg): array {
  $root = realpath((string)$cfg['backup_root']) ?: '';
  if ($root === '' || !is_dir($root)) fail(500, "Invalid backup_root");
  $out  = (string)$cfg['backup_dir']; ensure_dir($out);
  return [$root, $out];
}
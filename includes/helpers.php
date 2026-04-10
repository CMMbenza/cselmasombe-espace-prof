<?php
// prof/includes/helpers.php
declare(strict_types=1);

if (!function_exists('e')) {
  function e(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
}

function redirect(string $url): never {
  header("Location: $url"); exit;
}

function ensure_dir(string $dir): void {
  if (!is_dir($dir)) @mkdir($dir, 0775, true);
}

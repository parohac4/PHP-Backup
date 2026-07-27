<?php
/**
 * generate-token.php
 *
 * Malý skript pro vygenerování náhodného tokenu (64 hex znaků).
 * Spusť v prohlížeči nebo v CLI: php generate-token.php
 */

header('Content-Type: text/plain; charset=UTF-8');

// 32 bytů = 256 bitů → 64 hex znaků
$bytes = random_bytes(32);
$token = bin2hex($bytes);

echo "Vygenerovaný token:\n\n";
echo $token . "\n";
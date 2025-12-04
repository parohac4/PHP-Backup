<?php
/**
 * Pomocný skript pro vygenerování bezpečného API tokenu
 * 
 * Spusťte tento soubor jednou a zkopírujte vygenerovaný token do config.php
 * PO VYGENEROVÁNÍ TOHOTO SOUBORU SMAŽTE!
 */

header('Content-Type: text/plain; charset=utf-8');

echo "========================================\n";
echo "Generování API tokenu pro PHP Backup Tool\n";
echo "========================================\n\n";

// Vygenerovat silný token
$token = bin2hex(random_bytes(32)); // 64 znaků hexadecimálního řetězce

echo "VYGENEROVANÝ TOKEN:\n";
echo $token . "\n\n";

echo "========================================\n";
echo "INSTRUKCE:\n";
echo "========================================\n\n";
echo "1. Zkopírujte výše uvedený token\n";
echo "2. Otevřete soubor config.php\n";
echo "3. Najděte řádek s 'api_token'\n";
echo "4. Nahraďte 'ZMENTE_TENTO_TOKEN_NA_SILNY_NAHODNY_STRING' vygenerovaným tokenem\n";
echo "5. Uložte soubor\n";
echo "6. SMAŽTE tento soubor (generate-token.php) z bezpečnostních důvodů!\n\n";

echo "Příklad:\n";
echo "Před: 'api_token' => 'ZMENTE_TENTO_TOKEN_NA_SILNY_NAHODNY_STRING',\n";
echo "Po:   'api_token' => '" . $token . "',\n\n";


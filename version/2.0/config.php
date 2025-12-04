<?php
/**
 * Konfigurační soubor pro PHP Backup Tool v2.0
 * 
 * DŮLEŽITÉ: Před nasazením na produkci změňte všechny hesla a tokeny!
 */

return [
    // ===== AUTENTIZACE =====
    // Token pro přístup k API
    // 
    // JAK VYGENEROVAT TOKEN (NEJEDNODUŠŠÍ ZPŮSOB):
    // 1. Otevřete v prohlížeči: setup/index.php
    // 2. Klikněte na "Vygenerovat nový token"
    // 3. Token bude automaticky uložen do tohoto souboru
    // 4. PO DOKONČENÍ SMAŽTE adresář setup/ z bezpečnostních důvodů!
    //
    // ALTERNATIVNĚ (ruční způsob):
    // 1. Otevřete v prohlížeči: generate-token.php
    // 2. Zkopírujte vygenerovaný token
    // 3. Vložte ho sem místo výchozí hodnoty
    // 4. PO VYGENEROVÁNÍ SMAŽTE generate-token.php!
    //
    // Příklad:
    // 'api_token' => 'a1b2c3d4e5f6g7h8i9j0k1l2m3n4o5p6q7r8s9t0u1v2w3x4y5z6a7b8c9d0e1f2',
    'api_token' => getenv('BACKUP_API_TOKEN') ?: '478548f1d746fa63f627c01c83fcdb098c3646976d30fa07c41be3d0a1337e79',
    
    // ===== ZÁLOHA SOUBORŮ =====
    // Kořenový adresář pro zálohu - TADY NASTAVTE CESTU, KTEROU CHCETE ZÁLOHOVAT
    //
    // JAK NASTAVIT CESTU:
    // 1. Zjistěte absolutní cestu k vašemu webu na FTP serveru
    //    (obvykle najdete v administraci hostingu nebo v .htaccess)
    // 2. Nahraďte hodnotu níže vaší cestou
    //
    // PŘÍKLADY:
    // Pokud je váš web v: /data/web/virtuals/271620/virtual
    // 'backup_root' => '/data/web/virtuals/271620/virtual',
    //
    // Pokud je váš web v: /var/www/html
    // 'backup_root' => '/var/www/html',
    //
    // Pokud chcete zálohovat o 3 úrovně výš než tento skript:
    // 'backup_root' => dirname(__DIR__, 3),
    //
    // DŮLEŽITÉ: Použijte ABSOLUTNÍ cestu (začíná /), ne relativní!
    // Cestu nastavte pomocí setup/index.php - zadáte číslo webhostingu a cesta se vygeneruje automaticky
    'backup_root' => '', // Nastavte pomocí setup/index.php
    
    // Adresář pro ukládání záloh (musí být zapisovatelný)
    'backup_dir' => __DIR__ . '/backups',
    
    // Vzory souborů/adresářů k vyloučení z zálohy
    // Poznámka: Pokud chcete zálohovat vše včetně tmp, session, www, 
    // ponechte tento seznam prázdný nebo vylučte pouze konkrétní soubory
    'exclude_patterns' => [
        '*.log',
        '*.bak',
        '*.cache',
        '.git',
        '.svn',
        'backups', // vyloučit samotný adresář se zálohami
        'node_modules',
        'vendor',
        // 'session', // ODSTRANĚNO - zálohuje se
        // 'tmp',     // ODSTRANĚNO - zálohuje se
        // 'cache',   // ODSTRANĚNO - zálohuje se
    ],
    
    // ===== DATABÁZE =====
    // Konfigurace databází pro dump
    'databases' => [
        [
            'name' => 'mydb1',
            'host' => 'localhost',
            'port' => 3306,
            'user' => 'root',
            'pass' => 'password1',
        ],
        // Přidejte další databáze podle potřeby:
        // [
        //     'name' => 'mydb2',
        //     'host' => 'localhost',
        //     'port' => 3306,
        //     'user' => 'root',
        //     'pass' => 'password2',
        // ],
    ],
    
    // ===== BEZPEČNOST =====
    // CSRF ochrana (doporučeno: true)
    'enable_csrf' => true,
    
    // Rate limiting - minimální interval mezi zálohami (v sekundách)
    // Nastaveno na 0 = vypnuto (dočasně)
    'rate_limit_seconds' => 0,
    
    // Maximální velikost ZIP archivu (v MB, 0 = bez limitu)
    'max_zip_size_mb' => 0,
    
    // ===== ÚDRŽBA =====
    // Počet dní, po které se zálohy uchovávají (starší se automaticky smažou)
    'retain_days' => 7,
    
    // ===== VOLITELNÉ =====
    // Heslo pro ZIP archiv (prázdné = bez hesla)
    'zip_password' => getenv('ZIP_PASSWORD') ?: '',
    
    // Komprese databázových dumpů (true = .sql.gz, false = .sql)
    'compress_dumps' => true,
];


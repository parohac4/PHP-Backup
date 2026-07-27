<?php
return [
  'backup_root' => '/data/web/virtuals/XXX/virtual/www/',   // <- UPRAV
  'backup_dir'  => __DIR__ . '/data',
  'token'       => 'vas_bezpecny_token',               // <- UPRAV (POST pole "token")
  'require_https' => true,
  'min_seconds_between_runs' => 60,
  'allow_ips' => [],

  // Excludy
  'exclude_paths' => [
    '/.git/', '/.svn/', '/.hg/', '/node_modules/', '/vendor/bin/',
    '/cache/', '/logs/', '/log/', '/tmp/', '/var/tmp/', '/var/cache/',
    '/.idea/', '/.vscode/', '/.DS_Store', '/Thumbs.db',
    '/php-zip/public/data/', // vlastní výstupy
  ],

  // Inkrementální zpracování
  'batch_size' => 400,      // kolik souborů na 1 krok
  'scan_limit' => 0,        // 0 = bez limitu (max počet nascanovaných souborů)
];
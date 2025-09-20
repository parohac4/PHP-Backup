<?php

$config = require __DIR__ . '/config.php';

$files = glob($config['backupDir'] . '/*.zip');
$now = time();

foreach ($files as $file) {
    if ($now - filemtime($file) > $config['retainDays'] * 86400) {
        unlink($file);
    }
}

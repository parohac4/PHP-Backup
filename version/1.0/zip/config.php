<?php

return [
    'batchSize'        => 1000,
    'excludePatterns'  => ['session', 'tmp', '*.log', '*.bak', '.htaccess'],
    
    'backupRoot' => '/data/web/virtuals/271620/virtual',

    'enableCSRF'       => true,
    'retainDays'       => 7,

    'enableEncryption' => false,
    'zipPassword'      => getenv('ZIP_PASSWORD') ?: 'tajneheslo',

    'databases' => [
    [
        'name' => 'mydb1',
        'host' => 'localhost',
        'user' => 'root',
        'pass' => 'password1',
    ],
    // [
    //     'name' => 'mydb2',
    //     'host' => 'localhost',
    //     'user' => 'root',
    //     'pass' => 'password2',
    // ],
],


    'cloud' => [
        'enabled'  => false,
        'provider' => 's3', // nebo 'ftp', 'gdrive' (nutná vlastní implementace)
        's3' => [
            'bucket' => 'moje-backupy',
            'region' => 'eu-central-1',
        ],
    ],
];

<?php

class DatabaseDumper {
    public static function dumpAll(array $databases): array {
        $files = [];

        foreach ($databases as $db) {
            $tmp = tempnam(sys_get_temp_dir(), 'sql_') . '_' . $db['name'] . '.sql';
            $cmd = sprintf(
                'mysqldump -h%s -u%s -p%s %s > %s',
                escapeshellarg($db['host']),
                escapeshellarg($db['user']),
                escapeshellarg($db['pass']),
                escapeshellarg($db['name']),
                escapeshellarg($tmp)
            );
            exec($cmd, $out, $code);
            if ($code === 0) {
                $files[] = $tmp;
            }
        }

        return $files;
    }
}

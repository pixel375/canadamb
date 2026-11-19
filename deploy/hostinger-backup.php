<?php

declare(strict_types=1);

/**
 * Backup the currently running CanadaMB MariaDB schema without putting credentials
 * on the command line or in workflow logs. Called ONLY after maintenance begins.
 *
 * Usage: php hostinger-backup.php /absolute/current/release /private/backup.sql exact_database
 */

use Illuminate\Contracts\Console\Kernel;

if (PHP_SAPI !== 'cli' || $argc !== 4) {
    fwrite(STDERR, "Usage: php hostinger-backup.php RELEASE DESTINATION DATABASE\n");
    exit(2);
}

umask(0077);
[$self, $releasePath, $destination, $expectedDatabase] = $argv;
$release = realpath($releasePath);
$parent = realpath(dirname($destination));

if ($release === false || $parent === false || ! is_file($release.'/vendor/autoload.php')
    || ! preg_match('/^[a-zA-Z0-9_]{1,64}$/D', $expectedDatabase)
    || ! str_ends_with($destination, '/'.$expectedDatabase.'.sql')
    || file_exists($destination) || is_link($destination)) {
    fwrite(STDERR, "Invalid backup target or release.\n");
    exit(1);
}

try {
    require $release.'/vendor/autoload.php';
    $app = require $release.'/bootstrap/app.php';
    $app->make(Kernel::class)->bootstrap();

    if (config('database.default') !== 'mariadb') {
        throw new RuntimeException('The active database is not MariaDB.');
    }

    $settings = config('database.connections.mariadb', []);
    if (($settings['database'] ?? null) !== $expectedDatabase) {
        throw new RuntimeException('Backup database does not match approved schema.');
    }

    $host = (string) ($settings['host'] ?? '');
    $user = (string) ($settings['username'] ?? '');
    $password = (string) ($settings['password'] ?? '');
    $port = (string) ($settings['port'] ?? '3306');
    if ($host === '' || $user === '' || ! ctype_digit($port)) {
        throw new RuntimeException('Database connection is incomplete.');
    }

    $binary = '/usr/bin/mariadb-dump';
    if (! is_executable($binary)) {
        throw new RuntimeException('mariadb-dump is not available at the verified Hostinger path.');
    }

    $command = [
        $binary, '--single-transaction', '--skip-lock-tables', '--quick',
        '--default-character-set=utf8mb4', '--host='.$host, '--port='.$port,
        '--user='.$user, $expectedDatabase,
    ];
    $output = fopen($destination, 'x');
    if ($output === false) {
        throw new RuntimeException('Cannot create exclusive backup file.');
    }

    $environment = array_merge($_ENV, [
        'PATH' => getenv('PATH') ?: '/usr/bin:/bin',
        'HOME' => getenv('HOME') ?: '/',
        'MYSQL_PWD' => $password,
    ]);
    $pipes = [];
    $process = proc_open($command, [
        0 => ['pipe', 'r'],
        1 => $output,
        2 => ['pipe', 'w'],
    ], $pipes, null, $environment);

    if (! is_resource($process)) {
        fclose($output);
        throw new RuntimeException('Cannot start database backup program.');
    }
    fclose($pipes[0]);
    // Consume error output but never publish it, because it can contain private paths.
    stream_get_contents($pipes[2]);
    fclose($pipes[2]);
    $code = proc_close($process);
    fclose($output);

    if ($code !== 0 || ! is_file($destination) || filesize($destination) < 256) {
        throw new RuntimeException('Database backup failed or was empty.');
    }
    echo 'MariaDB backup completed; bytes: '.filesize($destination)."\n";
} catch (Throwable $error) {
    if (is_file($destination)) {
        @unlink($destination);
    }
    fwrite(STDERR, "Database backup failed safely; inspect private server logs.\n");
    exit(1);
}

<?php

declare(strict_types=1);

// Generate an independent Composer root manifest; never rewrite the package manifest/lock.
$directory = dirname(__DIR__);
$profile = $argv[1] ?? '';
$profiles = [
    'laravel10' => ['laravel/framework' => '10.50.2', 'orchestra/testbench' => '^8.0', 'phpunit/phpunit' => '^10.5', 'symfony/http-foundation' => '^6.4', 'guzzlehttp/guzzle' => '7.13.1'],
    'laravel11' => ['laravel/framework' => '^11.0', 'orchestra/testbench' => '^9.0', 'phpunit/phpunit' => '^11.5'],
    'laravel12' => ['laravel/framework' => '12.69.3', 'orchestra/testbench' => '^10.0', 'phpunit/phpunit' => '^11.5'],
    'floors' => ['laravel/framework' => '10.50.2', 'orchestra/testbench' => '^8.0', 'phpunit/phpunit' => '^10.5', 'symfony/http-foundation' => '^6.4', 'guzzlehttp/guzzle' => '7.8.2', 'firebase/php-jwt' => '7.1.0'],
];
if (! isset($profiles[$profile]) && $profile !== 'hrm') {
    fwrite(STDERR, "Usage: php tools/compatibility.php laravel10|laravel11|laravel12|floors|hrm\n");
    exit(1);
}
$manifest = json_decode(file_get_contents($directory.'/composer.json'), true, flags: JSON_THROW_ON_ERROR);
foreach ($profiles[$profile] ?? [] as $package => $constraint) {
    $section = isset($manifest['require'][$package]) ? 'require' : 'require-dev';
    $manifest[$section][$package] = $constraint;
}
$manifest['config']['platform']['php'] = '8.2.12';
// Keep vendor at the package root: subprocess fixtures intentionally bootstrap it there.
$manifest['config']['vendor-dir'] = 'vendor';
if ($profile === 'hrm') {
    // A synthetic consumer, not the real HRM repository or its complete dependency set.
    $manifest = [
        'name' => 'doxa/hrm-compatibility-fixture',
        'description' => 'Synthetic consumer of the Doxa Laravel SDK for compatibility verification',
        'license' => 'proprietary',
        'repositories' => [['type' => 'path', 'url' => '.', 'options' => ['versions' => ['doxa/laravel-sdk' => 'dev-main']]]],
        'require' => ['php' => '8.2.12', 'doxa/laravel-sdk' => 'dev-main', 'laravel/framework' => '10.50.2', 'laravel/sanctum' => '3.3.3', 'symfony/http-foundation' => '^6.4', 'guzzlehttp/guzzle' => '7.13.1'],
        'config' => ['platform' => ['php' => '8.2.12'], 'allow-plugins' => false, 'vendor-dir' => '.compatibility/hrm-vendor'],
    ];
}
if (! is_dir($directory.'/.compatibility')) {
    mkdir($directory.'/.compatibility', 0777, true);
}
$path = '.compatibility/composer-'.$profile.'.json';
file_put_contents($directory.'/'.$path, json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR)."\n");
echo $path."\n";
if (in_array('--run', $argv, true)) {
    chdir($directory);
    putenv('COMPOSER='.$path);
    putenv('COMPOSER_CACHE_DIR='.$directory.'/.compatibility/composer-cache');
    // Explicit opt-in for testing historical targets that Composer blocks on advisories.
    // Never persist this exception in the package's Composer policy.
    $historical = in_array('--historical-advisories', $argv, true) ? ' --no-security-blocking' : '';
    $commands = ['composer update --no-interaction --prefer-dist'.$historical.($profile === 'hrm' ? ' --no-install' : ''),
        // Exact historical version pins are deliberate in these generated fixtures.
        'composer validate --strict --no-check-publish --no-check-all'];
    if ($profile !== 'hrm') {
        $php = escapeshellarg(PHP_BINARY);
        $commands[] = $php.' vendor/phpunit/phpunit/phpunit --do-not-cache-result --log-junit .compatibility/'.$profile.'.junit.xml';
        $commands[] = $php.' vendor/phpstan/phpstan/phpstan analyse --no-progress';
        $commands[] = $php.' vendor/laravel/pint/builds/pint --test';
    }
    foreach ($commands as $command) {
        passthru($command, $status);
        if ($status !== 0) {
            exit($status);
        }
    }
}

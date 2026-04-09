<?php

use Symfony\Component\Dotenv\Dotenv;

require dirname(__DIR__).'/vendor/autoload.php';

$generatedSassDir = dirname(__DIR__).'/var/sass';
$generatedSassFile = $generatedSassDir.'/app.output.css';

if (!is_file($generatedSassFile)) {
    if (!is_dir($generatedSassDir) && !mkdir($generatedSassDir, 0777, true) && !is_dir($generatedSassDir)) {
        throw new RuntimeException(sprintf('Could not create Sass output directory "%s".', $generatedSassDir));
    }

    // Functional tests don't exercise Sass compilation, but templates still resolve the generated CSS asset.
    if (false === file_put_contents($generatedSassFile, "/* test placeholder */\n")) {
        throw new RuntimeException(sprintf('Could not create Sass output file "%s".', $generatedSassFile));
    }
}

if (method_exists(Dotenv::class, 'bootEnv')) {
    (new Dotenv())->bootEnv(dirname(__DIR__).'/.env');
}

$_SERVER['APP_ENV'] ??= $_ENV['APP_ENV'] ?? 'test';
$_SERVER['APP_DEBUG'] ??= $_ENV['APP_DEBUG'] ?? '1';
$_SERVER['AUTH0_DOMAIN'] ??= $_ENV['AUTH0_DOMAIN'] ?? 'test.auth0.com';
$_ENV['AUTH0_DOMAIN'] = $_SERVER['AUTH0_DOMAIN'];
$_SERVER['AUTH0_CLIENT_ID'] ??= $_ENV['AUTH0_CLIENT_ID'] ?? 'test-client-id';
$_ENV['AUTH0_CLIENT_ID'] = $_SERVER['AUTH0_CLIENT_ID'];
$_SERVER['AUTH0_CLIENT_SECRET'] ??= $_ENV['AUTH0_CLIENT_SECRET'] ?? 'test-client-secret';
$_ENV['AUTH0_CLIENT_SECRET'] = $_SERVER['AUTH0_CLIENT_SECRET'];

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

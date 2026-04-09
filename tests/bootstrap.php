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

setTestEnvDefault('APP_ENV', 'test');
setTestEnvDefault('APP_DEBUG', '1');
setTestEnvDefault('AUTH0_DOMAIN', 'test.auth0.com');
setTestEnvDefault('AUTH0_CLIENT_ID', 'test-client-id');
setTestEnvDefault('AUTH0_CLIENT_SECRET', 'test-client-secret');

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

function setTestEnvDefault(string $name, string $default): void
{
    $current = $_SERVER[$name] ?? $_ENV[$name] ?? getenv($name);

    if (!is_string($current) || '' === trim($current)) {
        $_SERVER[$name] = $default;
        $_ENV[$name] = $default;
        putenv(sprintf('%s=%s', $name, $default));

        return;
    }

    $_SERVER[$name] = $current;
    $_ENV[$name] = $current;
    putenv(sprintf('%s=%s', $name, $current));
}

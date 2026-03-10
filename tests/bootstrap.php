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

if ($_SERVER['APP_DEBUG']) {
    umask(0000);
}

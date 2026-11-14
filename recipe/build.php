<?php

/*
 * Compiles recipe/manifest.json + recipe/config/ into the files served to Symfony Flex through the endpoint
 * declared in the host project (see README, "Installation"):
 *
 *   recipe/index.json
 *   recipe/psolutions.encrypt-bundle.<version>.json
 *
 * Run after changing the recipe: php recipe/build.php
 */

const PACKAGE = 'psolutions/encrypt-bundle';
const VERSION = '5.1';
const REPOSITORY = 'github.com/frizquierdo/EncryptBundle';
const BRANCH = 'master';

$dir = __DIR__;
$manifest = json_decode(file_get_contents($dir . '/manifest.json'), true, 512, JSON_THROW_ON_ERROR);

$files = [];
$iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir . '/config', FilesystemIterator::SKIP_DOTS));
foreach ($iterator as $file) {
    $relative = substr($file->getPathname(), strlen($dir) + 1);
    $files[$relative] = [
        'contents' => explode("\n", rtrim(file_get_contents($file->getPathname()), "\n")),
        'executable' => false,
    ];
}
ksort($files);

$recipe = ['manifest' => $manifest, 'files' => $files];
$recipe['ref'] = sha1(json_encode($recipe));
$dotted = str_replace('/', '.', PACKAGE);

$flags = JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR;
file_put_contents(
    sprintf('%s/%s.%s.json', $dir, $dotted, VERSION),
    json_encode(['manifests' => [PACKAGE => $recipe]], $flags) . "\n"
);
file_put_contents($dir . '/index.json', json_encode([
    'recipes' => [PACKAGE => [VERSION]],
    'branch' => BRANCH,
    'is_contrib' => true,
    '_links' => [
        'repository' => REPOSITORY,
        'origin_template' => '{package}:{version}@' . REPOSITORY . ':' . BRANCH,
        'recipe_template' => 'https://api.github.com/repos/frizquierdo/EncryptBundle/contents/recipe/{package_dotted}.{version}.json',
    ],
], $flags) . "\n");

echo "Recipe compiled.\n";

<?php
/**
 * Smoke / gate : fonctionnalités expérimentales (Decision Arena).
 * Le flag experimentalFeaturesEnabled est géré côté frontend (localStorage) ;
 * ce script vérifie qu'aucune migration SQL dédiée n'est requise pour ce MVP.
 */
declare(strict_types=1);

$root = dirname(__DIR__);
$errors = [];

$migrationDir = $root . DIRECTORY_SEPARATOR . 'storage' . DIRECTORY_SEPARATOR . 'migrations';
if (is_dir($migrationDir)) {
    foreach (glob($migrationDir . '/*.sql') ?: [] as $file) {
        $base = basename($file);
        if (stripos($base, 'experimental_features') !== false) {
            $errors[] = "Migration SQL inattendue pour le flag expérimental: {$base}";
        }
    }
}

if ($errors) {
    fwrite(STDERR, implode(PHP_EOL, $errors) . PHP_EOL);
    exit(1);
}

echo "OK test_experimental_features_gate: aucune migration SQL dédiée experimental_features requise pour le MVP (flag UI localStorage).\n";
exit(0);

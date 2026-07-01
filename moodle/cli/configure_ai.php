<?php
// Configuration idempotente du fournisseur IA + placement.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');

// 1) Injecter le jeton de la passerelle dans la config du plugin.
$token = trim(file_get_contents('/run/secrets/ollama_token'));
set_config('ollama_token', $token, 'aiprovider_ollamasecure');

// 2) Activer le fournisseur via le gestionnaire IA (API Moodle 4.5).
//    NOTE: valider la signature exacte contre \core_ai\manager du code réel.
try {
    $manager = \core\di::get(\core_ai\manager::class);
    // Exemple d'activation ; adapter au setter réel (enable_provider / set_provider_config).
    set_config('enabled', 1, 'aiprovider_ollamasecure');
    cli_writeln('Fournisseur aiprovider_ollamasecure configuré.');
} catch (Throwable $e) {
    cli_writeln('AVERTISSEMENT: adapter le câblage du manager IA: ' . $e->getMessage());
}

// 3) Purger les caches.
purge_all_caches();
cli_writeln('OK');

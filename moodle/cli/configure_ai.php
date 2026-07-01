<?php
// Configuration idempotente du fournisseur IA + placement.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');

// 1) Injecter le jeton de la passerelle dans la config du plugin.
$token = trim(file_get_contents('/run/secrets/ollama_token'));
set_config('ollama_token', $token, 'aiprovider_ollamasecure');

// 2) Activer le fournisseur ET le placement editeur via les vraies API 4.5.
\core\plugininfo\aiprovider::enable_plugin('aiprovider_ollamasecure', 1);
\core\plugininfo\aiplacement::enable_plugin('aiplacement_editor', 1);
cli_writeln('Fournisseur aiprovider_ollamasecure et placement editor actives.');

// 3) Purger les caches.
purge_all_caches();
cli_writeln('OK');

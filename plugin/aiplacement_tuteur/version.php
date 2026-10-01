<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'aiplacement_tuteur';
$plugin->version   = 2026100102; // db/hooks.php nouveau : upgrade.php ne resynchronise les metadonnees
                                  // (hooks inclus) que si version.php augmente (vecu au Task 2 avec
                                  // db/services.php).
$plugin->requires  = 2024100700; // Moodle 4.5 (aligne sur aiprovider_ollamasecure).
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1';

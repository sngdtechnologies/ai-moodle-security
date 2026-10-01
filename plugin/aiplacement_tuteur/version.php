<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'aiplacement_tuteur';
$plugin->version   = 2026100102; // Historique : 2026100100 (installation initiale) ->
                                  // 2026100101 (ajout de db/services.php, Tache 2) ->
                                  // 2026100102 (ajout de db/hooks.php, Tache 3). Moodle ne
                                  // resynchronise db/*.php que si ce numero augmente.
$plugin->requires  = 2024100700; // Moodle 4.5 (aligne sur aiprovider_ollamasecure).
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1';

<?php
// moodle/cli/configure_system.php -- configuration systeme de Moodle pour le cron (idempotent),
// invoque par l'entrypoint a chaque demarrage :
//   1. chemins des binaires (Administration > Serveur > Chemins systeme) : PHP CLI est requis par
//      le cron et par le bouton "Run now" des taches planifiees ; du sert aux calculs d'espace disque.
//      Uniquement les binaires PRESENTS dans l'image (pas de paquet supplementaire : image minimale).
//   2. desactive les taches planifiees qui exigent Internet : le conteneur n'a aucune sortie reseau
//      par conception, elles echoueraient et seraient rejouees en boucle avec des traces de debug.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

// 1. Chemins systeme.
foreach (['pathtophp' => 'php', 'pathtodu' => 'du'] as $setting => $binary) {
    $path = trim((string)shell_exec('command -v ' . escapeshellarg($binary)));
    if ($path !== '' && get_config('core', $setting) !== $path) {
        set_config($setting, $path);
        echo "[configure_system] {$setting} = {$path}\n";
    }
}

// 2. Taches necessitant Internet.
$needsinternet = ['\core\task\h5p_get_content_types_task'];   // telecharge les types de contenu H5P depuis h5p.org
foreach ($needsinternet as $classname) {
    $task = \core\task\manager::get_scheduled_task($classname);
    if ($task && !$task->get_disabled()) {
        $task->set_disabled(true);
        $task->set_customised(true);   // evite que la mise a niveau de Moodle la reactive
        \core\task\manager::configure_scheduled_task($task);
        echo "[configure_system] tache desactivee (exige Internet) : {$classname}\n";
    }
}

<?php
// moodle/cli/demo_tuteur.php -- verification de bout en bout du placement aiplacement_tuteur :
// appelle le service web aiplacement_tuteur_generate_text comme le ferait le navigateur, en tant
// qu'etudiant1, dans le contexte du cours de demonstration. Sur le modele de demo_indirect.php.
// Usage (dans le conteneur moodle) :
//   docker compose exec -T moodle sh -c "cat > /tmp/demo_tuteur.php" < moodle/cli/demo_tuteur.php
//   docker compose exec -T moodle php /tmp/demo_tuteur.php
//   docker compose exec -T moodle php /tmp/demo_tuteur.php "Ta question"
//
// Note : WS_SERVER doit etre defini a true AVANT le bootstrap Moodle. Un script CLI_SCRIPT force
// NO_MOODLE_COOKIES=true dans lib/setup.php, ce qui fait echouer systematiquement
// call_external_function() avec errorcode=servicerequireslogin pour tout service web de type
// "write", quel que soit l'utilisateur de session actif (deja rencontre et documente en Task 2).
// Predefinir WS_SERVER=true fait passer la garde de connexion comme le ferait une vraie requete
// de service web, sans contourner aucun controle de capacite/permission.
//
// NB : ce script teste la couche action/PHP via call_external_function() (avec WS_SERVER=true),
// pas le trajet HTTP/WAF complet. Une verification HTTP reelle (POST authentifie a travers
// proxy+WAF) a ete faite separement et a revele un faux positif OWASP CRS (regle 933160 ->
// anomalie 949110, HTTP 403) sur une question contenant du code Python ("os.system(...)") ;
// voir le rapport de correction (verification WAF) -- decision de la personne responsable
// requise avant toute modification de regle WAF.
define('WS_SERVER', true);
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB;

$question = $argv[1] ?? "Explique en 2 phrases ce qu'est une liste en Python.";

$user = $DB->get_record('user', ['username' => 'etudiant1'], '*', MUST_EXIST);
\core\session\manager::set_user($user);

$course = $DB->get_record('course', ['shortname' => 'PYTHON101'], '*', MUST_EXIST);
$context = \context_course::instance($course->id);

echo "Question (en tant qu'etudiant1, cours PYTHON101) :\n  $question\n\n";

$t = microtime(true);
$result = \core_external\external_api::call_external_function(
    'aiplacement_tuteur_generate_text',
    ['contextid' => $context->id, 'prompttext' => $question],
    false
);
$dt = round(microtime(true) - $t, 1);

if ($result['error']) {
    $e = $result['exception'];
    echo "ERREUR ({$dt}s) : {$e->errorcode} -- {$e->message}\n";
    exit(1);
}

$data = $result['data'];
echo "--- Reponse du tuteur ({$dt}s) ---\n", html_entity_decode($data['generatedcontent']), "\n\n";
printf("success=%s errorcode=%s longueur=%d\n", var_export($data['success'], true), $data['errorcode'], strlen($data['generatedcontent']));

if ($data['success'] && $data['generatedcontent'] !== '') {
    echo "RESULTAT : OK\n";
    exit(0);
}
echo "RESULTAT : ECHEC (reponse vide ou success=false)\n";
exit(1);

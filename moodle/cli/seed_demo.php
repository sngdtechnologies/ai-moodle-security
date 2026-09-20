<?php
// moodle/cli/seed_demo.php -- peuple Moodle avec des donnees de DEMONSTRATION pour la soutenance :
// 1 cours, 1 enseignant, 3 etudiants (inscrits), 1 devoir, 1 forum. Idempotent (ne recree pas
// ce qui existe). Les mots de passe generes sont ecrits sur stdout : a rediriger vers un fichier.
// Usage (dans le conteneur moodle) :  php /tmp/seed_demo.php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
require_once($CFG->dirroot . '/course/lib.php');
require_once($CFG->dirroot . '/course/modlib.php');
require_once($CFG->dirroot . '/user/lib.php');
require_once($CFG->libdir . '/enrollib.php');
global $DB, $CFG;

\core\session\manager::set_user(get_admin());

// --- cours ---------------------------------------------------------------------------------
$shortname = 'PYTHON101';
$course = $DB->get_record('course', ['shortname' => $shortname]);
if (!$course) {
    $course = create_course((object)[
        'fullname' => 'Introduction à la programmation Python', 'shortname' => $shortname,
        'category' => 1, 'format' => 'topics', 'visible' => 1,
        'summary' => '<p>Cours de démonstration : bases de Python avec un tuteur IA local et sécurisé.</p>',
        'summaryformat' => FORMAT_HTML,
    ]);
    echo "cours cree : #{$course->id} {$shortname}\n";
} else {
    echo "cours existant : #{$course->id} {$shortname}\n";
}
course_create_sections_if_missing($course, [0, 1, 2]);

// --- utilisateurs --------------------------------------------------------------------------
$people = [
    ['enseignant', 'Amina',  'Fotso',  'editingteacher'],
    ['etudiant1',  'Joseph', 'Mbarga', 'student'],
    ['etudiant2',  'Carine', 'Ngono',  'student'],
    ['etudiant3',  'Boris',  'Tchoua', 'student'],
];
$manual = enrol_get_plugin('manual');
$instance = $DB->get_record('enrol', ['courseid' => $course->id, 'enrol' => 'manual'], '*', MUST_EXIST);
foreach ($people as [$username, $first, $last, $roleshort]) {
    $u = $DB->get_record('user', ['username' => $username, 'mnethostid' => $CFG->mnet_localhost_id]);
    if (!$u) {
        $password = 'Demo#' . random_string(8) . '7a';   // respecte la politique par defaut
        $id = user_create_user((object)[
            'username' => $username, 'password' => $password, 'firstname' => $first, 'lastname' => $last,
            'email' => "{$username}@example.cm", 'auth' => 'manual', 'confirmed' => 1,
            'mnethostid' => $CFG->mnet_localhost_id, 'lang' => 'fr', 'city' => 'Yaoundé', 'country' => 'CM',
        ]);
        echo "IDENTIFIANT\t{$username}\t{$password}\t{$roleshort}\n";   // immediat : rien n'est perdu si la suite echoue
        echo "utilisateur cree : {$username}\n";
    } else {
        $id = $u->id;
        echo "utilisateur existant : {$username} (mot de passe inchange)\n";
    }
    $roleid = $DB->get_field('role', 'id', ['shortname' => $roleshort], MUST_EXIST);
    $manual->enrol_user($instance, $id, $roleid);
}

// --- activites -----------------------------------------------------------------------------
function seed_module(stdClass $course, string $modname, array $fields): int {
    global $DB;
    if ($DB->record_exists($modname, ['course' => $course->id, 'name' => $fields['name']])) {
        echo "activite existante : {$fields['name']}\n";
        return 0;
    }
    $mi = (object)($fields + [
        'modulename' => $modname, 'module' => $DB->get_field('modules', 'id', ['name' => $modname], MUST_EXIST),
        'course' => $course->id, 'section' => 1, 'visible' => 1, 'visibleoncoursepage' => 1,
        'introformat' => FORMAT_HTML, 'showdescription' => 1, 'cmidnumber' => '', 'completion' => 0,
        'availabilityconditionsjson' => '',
    ]);
    $cm = add_moduleinfo($mi, $course);
    echo "activite creee : {$fields['name']} (cm #{$cm->coursemodule})\n";
    return (int)$cm->coursemodule;
}

seed_module($course, 'assign', [
    'name' => 'Exercice 1 — Les boucles for',
    'intro' => '<p>Écrivez une fonction Python qui calcule la moyenne d\'une liste de notes, puis expliquez votre démarche.</p>',
    'submissiondrafts' => 0, 'requiresubmissionstatement' => 0, 'sendnotifications' => 0,
    'sendlatenotifications' => 0, 'duedate' => 0, 'allowsubmissionsfromdate' => 0, 'cutoffdate' => 0,
    'gradingduedate' => 0, 'grade' => 100, 'teamsubmission' => 0, 'requireallteammemberssubmit' => 0,
    'blindmarking' => 0, 'hidegrader' => 0, 'markingworkflow' => 0, 'markingallocation' => 0,
    'maxattempts' => -1, 'attemptreopenmethod' => 'none',
    'assignsubmission_onlinetext_enabled' => 1, 'assignsubmission_file_enabled' => 0,
    'assignfeedback_comments_enabled' => 1,
]);
seed_module($course, 'forum', [
    'name' => 'Forum — Questions au tuteur',
    'intro' => '<p>Espace d\'échange sur Python entre étudiants et enseignant.</p>'
             . '<p><strong>Aucune réponse n\'est générée automatiquement.</strong> Pour vous aider à rédiger votre message, '
             . 'vous pouvez utiliser l\'assistant IA de l\'éditeur de texte (génération de texte) : '
             . 'l\'IA n\'intervient que lorsque vous la sollicitez.</p>',
    'type' => 'general', 'forcesubscribe' => 0, 'trackingtype' => 1, 'maxbytes' => 0, 'maxattachments' => 0,
    'assessed' => 0, 'blockperiod' => 0, 'blockafter' => 0, 'warnafter' => 0,
]);
rebuild_course_cache($course->id, true);

// --- diagnostic : qui peut utiliser l'IA dans l'editeur ? ------------------------------------------
$ctx = context_course::instance($course->id);
$caps = $DB->get_fieldset_select('capabilities', 'name', "name LIKE 'aiplacement/%' OR name LIKE 'moodle/ai:%'");
echo "\n--- capacites IA ---\n";
foreach (['admin', 'enseignant', 'etudiant1'] as $un) {
    $uid = $DB->get_field('user', 'id', ['username' => $un]);
    $ok = [];
    foreach ($caps as $c) { $ok[] = $c . '=' . (has_capability($c, $ctx, $uid) ? 'oui' : 'NON'); }
    echo "{$un} : " . implode(', ', $ok) . "\n";
}

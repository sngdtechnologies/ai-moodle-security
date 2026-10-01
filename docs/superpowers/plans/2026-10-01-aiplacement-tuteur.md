# aiplacement_tuteur Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Construire le plugin Moodle `aiplacement_tuteur` : un bouton « Demander au tuteur » et un
tiroir de discussion, visibles sur toutes les pages d'un cours, qui permettent à un étudiant de
poser une question au tuteur IA sans passer par l'éditeur de texte.

**Architecture:** Nouveau *placement* du sous-système `core_ai` de Moodle 4.5, calqué sur
`ai/placement/courseassist/` (hooks + tiroir) pour l'interface et sur `ai/placement/editor/` pour
le service web. Réutilise sans modification le pipeline déjà validé
`manager → process_generate_text → client::ask() (N1-N4)`.

**Tech Stack:** PHP 8.2 (Moodle 4.5), JavaScript ES6 (AMD/RequireJS), Mustache, Docker.

**Spec:** `docs/superpowers/specs/2026-10-01-aiplacement-tuteur-design.md`

## Global Constraints

- Composant : `aiplacement_tuteur`. Code source du dépôt dans `plugin/aiplacement_tuteur/`, copié
  par l'image Docker vers `/var/www/html/ai/placement/tuteur` (convention déjà utilisée pour
  `plugin/aiprovider_ollamasecure` → `ai/provider/ollamasecure`).
- **Aucune modification** de `plugin/aiprovider_ollamasecure/classes/client.php`,
  `process_generate_text.php`, ni d'aucun autre fichier de `aiprovider_ollamasecure`.
- Une seule capacité : `aiplacement/tuteur:use`, `CONTEXT_COURSE`, accordée par défaut à
  `manager`, `editingteacher`, `teacher`, `student`.
- Aucun canal `[DONNEES]` : le service web ne transporte que `prompttext` (la question brute).
- Aucun historique de conversation : chaque question est un appel indépendant à `client::ask()`.
- Visible uniquement quand `$PAGE->context->contextlevel` est `CONTEXT_COURSE` ou
  `CONTEXT_MODULE`, jamais sur le cours `SITEID` (page d'accueil du site), jamais sur les
  `pagelayout` `maintenance`, `print`, `redirect`, `embedded`, `login`.
- `generatedcontent` est toujours déclaré `PARAM_TEXT` dans `execute_returns()` et retourné **tel
  quel**, sans `format_text()` ni concaténation de balises (sinon reproduction du bug corrigé dans
  `aiplacement_editor`, documenté dans la spec section 7).
- Tout fichier copié sur l'EC2 doit avoir des fins de ligne LF (`tr -d '\r'` avant `scp`/`cat >` —
  convention déjà établie dans ce dépôt pour éviter les erreurs shell/PHP).
- Le code du plugin est **compilé dans l'image Docker au build**, pas monté en volume : toute
  modification exige `docker compose up -d --build moodle` sur l'EC2 pour être visible.
- `version.php` suit la convention déjà en place dans `plugin/aiprovider_ollamasecure/version.php`
  (`MATURITY_ALPHA` + `$plugin->release`), pas celle des plugins cœur Moodle (`MATURITY_STABLE`) —
  déviation mineure et délibérée par rapport au libellé littéral de la spec section 4, pour rester
  cohérent avec le reste du dépôt.

## Review Focus

1. **Question vide ou trop longue** (> `client::MAX_LEN`, 6000 caractères) : doit produire un
   message gracieux (`success=true`, pas d'exception) — testé en Task 2.
2. **Capacité `aiplacement/tuteur:use` retirée** pour l'étudiant (surcharge de rôle) : l'appel au
   service web doit lancer une exception propre (`require_capability`), jamais une erreur fatale
   non gérée — testé en Task 2.
3. **Double soumission rapide** (clic répété sur « Envoyer ») : une seule requête en vol à la
   fois, pas de requêtes concurrentes dupliquées contre un modèle déjà lent — codé en Task 5
   (`this.isAsking`). **Non testable automatiquement dans cet environnement** (pas de navigateur
   automatisé disponible ici) : à vérifier manuellement par l'utilisateur.
4. **Échec réseau ou exception JavaScript** pendant l'appel au service web : doit afficher
   `error.mustache` et jamais laisser l'indicateur de chargement bloqué indéfiniment — codé en
   Task 5 (`catch` → `displayError()`). **Non testable automatiquement** pour la même raison.
5. **Visibilité hors contexte** : le bouton ne doit apparaître QUE sur une vraie page de cours
   (page de cours ou activité), jamais sur la page d'accueil du site ni une page d'administration
   — testé en Task 3 par requêtes HTTP réelles (sans navigateur, juste `curl`).

---

## Task 1 : Scaffold du plugin (backend, sans interface) + câblage Docker

**Files:**
- Create: `plugin/aiplacement_tuteur/version.php`
- Create: `plugin/aiplacement_tuteur/classes/placement.php`
- Create: `plugin/aiplacement_tuteur/classes/utils.php`
- Create: `plugin/aiplacement_tuteur/classes/privacy/provider.php`
- Create: `plugin/aiplacement_tuteur/db/access.php`
- Create: `plugin/aiplacement_tuteur/lang/en/aiplacement_tuteur.php`
- Create: `plugin/aiplacement_tuteur/lang/fr/aiplacement_tuteur.php`
- Modify: `moodle/Dockerfile:17-18`
- Modify: `moodle/cli/configure_ai.php:14-15`
- Test (sur l'EC2) : `moodle/cli/verify_tuteur_task1.php` (script jetable, pas committé)

**Interfaces:**
- Produces: `\aiplacement_tuteur\placement` (classe, `get_action_list(): array`),
  `\aiplacement_tuteur\utils::is_tutor_available(\context $context): bool`,
  `\aiplacement_tuteur\privacy\provider`, capacité `aiplacement/tuteur:use`, toutes les chaînes de
  langue du plugin (table complète, réutilisées par les tâches suivantes sans y retoucher).

- [ ] **Step 1 : Créer `version.php`**

```php
<?php
defined('MOODLE_INTERNAL') || die();

$plugin->component = 'aiplacement_tuteur';
$plugin->version   = 2026100100;
$plugin->requires  = 2024100700; // Moodle 4.5 (aligne sur aiprovider_ollamasecure).
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1';
```

- [ ] **Step 2 : Créer `classes/placement.php`**

```php
<?php
namespace aiplacement_tuteur;

/**
 * Declare les actions IA utilisees par cet emplacement.
 */
class placement extends \core_ai\placement {
    public function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
        ];
    }
}
```

- [ ] **Step 3 : Créer `classes/utils.php`**

```php
<?php
namespace aiplacement_tuteur;

use core_ai\manager;

/**
 * Fonctions utilitaires pour l'emplacement tuteur.
 */
class utils {
    /**
     * Verifie si le tuteur IA est disponible dans ce contexte : plugin active,
     * capacite accordee, action activee, au moins un fournisseur disponible.
     *
     * @param \context $context Le contexte a verifier.
     * @return bool
     */
    public static function is_tutor_available(\context $context): bool {
        [$plugintype, $pluginname] = explode('_', \core_component::normalize_componentname('aiplacement_tuteur'), 2);
        $manager = \core_plugin_manager::resolve_plugininfo_class($plugintype);
        if (!$manager::is_plugin_enabled($pluginname)) {
            return false;
        }

        $providers = manager::get_providers_for_actions([\core_ai\aiactions\generate_text::class], true);
        if (!has_capability('aiplacement/tuteur:use', $context)
            || !manager::is_action_available(\core_ai\aiactions\generate_text::class)
            || !manager::is_action_enabled('aiplacement_tuteur', \core_ai\aiactions\generate_text::class)
            || empty($providers[\core_ai\aiactions\generate_text::class])
        ) {
            return false;
        }

        return true;
    }
}
```

- [ ] **Step 4 : Créer `classes/privacy/provider.php`**

```php
<?php
namespace aiplacement_tuteur\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Sous-systeme de confidentialite : ce plugin ne stocke aucune donnee personnelle propre
 * (les reponses generees sont tracees par core_ai, deja couvert par son propre fournisseur).
 *
 * @codeCoverageIgnore
 */
class provider implements null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}
```

- [ ] **Step 5 : Créer `db/access.php`**

```php
<?php
defined('MOODLE_INTERNAL') || die();

$capabilities = [
    'aiplacement/tuteur:use' => [
        'captype' => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes' => [
            'manager' => CAP_ALLOW,
            'editingteacher' => CAP_ALLOW,
            'teacher' => CAP_ALLOW,
            'student' => CAP_ALLOW,
        ],
    ],
];
```

- [ ] **Step 6 : Créer `lang/en/aiplacement_tuteur.php`** (table complète, section 9 de la spec —
      certaines chaînes ne sont utilisées qu'à partir des tâches 3-5, mais les déclarer maintenant
      évite de retoucher ce fichier à chaque tâche suivante)

```php
<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Tutor placement';
$string['tuteur:use'] = 'Use the AI tutor';
$string['privacy:metadata'] = 'The Tutor placement plugin does not store any personal data.';
$string['notutor'] = 'The AI tutor is not available in this context.';
$string['tutorbuttonlabel'] = 'Ask the tutor';
$string['tutortooltip'] = 'Ask the AI tutor a question about this course';
$string['askplaceholder'] = 'Type your question…';
$string['send'] = 'Send';
$string['generating'] = 'Generating your answer';
$string['generatefailtitle'] = 'Something went wrong';
$string['tryagain'] = 'Try again';
$string['newquestion'] = 'Ask another question';
$string['regenerate'] = 'Ask again';
$string['aidrawerlabel'] = 'AI tutor drawer';
```

- [ ] **Step 7 : Créer `lang/fr/aiplacement_tuteur.php`**

```php
<?php
defined('MOODLE_INTERNAL') || die();

$string['pluginname'] = 'Emplacement tuteur';
$string['tuteur:use'] = 'Utiliser le tuteur IA';
$string['privacy:metadata'] = 'Le plugin d\'emplacement tuteur ne stocke aucune donnée personnelle.';
$string['notutor'] = 'Le tuteur IA n\'est pas disponible dans ce contexte.';
$string['tutorbuttonlabel'] = 'Demander au tuteur';
$string['tutortooltip'] = 'Poser une question au tuteur IA sur ce cours';
$string['askplaceholder'] = 'Tapez votre question…';
$string['send'] = 'Envoyer';
$string['generating'] = 'Génération de la réponse en cours';
$string['generatefailtitle'] = 'Une erreur est survenue';
$string['tryagain'] = 'Réessayer';
$string['newquestion'] = 'Poser une autre question';
$string['regenerate'] = 'Reposer la question';
$string['aidrawerlabel'] = 'Panneau du tuteur IA';
```

- [ ] **Step 8 : Vérifier la syntaxe PHP localement**

```bash
for f in plugin/aiplacement_tuteur/version.php plugin/aiplacement_tuteur/classes/placement.php plugin/aiplacement_tuteur/classes/utils.php plugin/aiplacement_tuteur/classes/privacy/provider.php plugin/aiplacement_tuteur/db/access.php plugin/aiplacement_tuteur/lang/en/aiplacement_tuteur.php plugin/aiplacement_tuteur/lang/fr/aiplacement_tuteur.php; do
  /c/tools/php84/php -l "$f" || echo "ECHEC: $f"
done
```
Expected: `No syntax errors detected` pour chacun des 7 fichiers.

- [ ] **Step 9 : Modifier `moodle/Dockerfile`** (ajouter après la ligne 18)

Avant :
```dockerfile
# Copie le plugin fournisseur dans ai/provider/ollamasecure
COPY plugin/aiprovider_ollamasecure /var/www/html/ai/provider/ollamasecure
```
Après :
```dockerfile
# Copie le plugin fournisseur dans ai/provider/ollamasecure
COPY plugin/aiprovider_ollamasecure /var/www/html/ai/provider/ollamasecure
# Copie l'emplacement tuteur dans ai/placement/tuteur (amd/build precompile, commite dans le depot
# -- l'image n'a pas de Node/npm, voir Task 5).
COPY plugin/aiplacement_tuteur /var/www/html/ai/placement/tuteur
```

- [ ] **Step 10 : Modifier `moodle/cli/configure_ai.php`** (activer aussi le placement tuteur)

Avant :
```php
\core\plugininfo\aiprovider::enable_plugin('ollamasecure', 1);
\core\plugininfo\aiplacement::enable_plugin('editor', 1);
cli_writeln('Fournisseur ollamasecure et placement editor actives.');
```
Après :
```php
\core\plugininfo\aiprovider::enable_plugin('ollamasecure', 1);
\core\plugininfo\aiplacement::enable_plugin('editor', 1);
\core\plugininfo\aiplacement::enable_plugin('tuteur', 1);
cli_writeln('Fournisseur ollamasecure et placements editor+tuteur actives.');
```

- [ ] **Step 11 : Déployer sur l'EC2 et reconstruire l'image Moodle**

```bash
KEY=$(grep AWS_REMOTE_KEY_PATH .env | cut -d= -f2- | tr -d '\r"')
HOST=$(grep AWS_REMOTE_HOST .env | cut -d= -f2- | tr -d '\r')
# Copie le plugin complet (fins de ligne LF) + le Dockerfile/configure_ai.php modifies.
rm -rf /tmp/deploy && mkdir -p /tmp/deploy
cp -r plugin/aiplacement_tuteur /tmp/deploy/
find /tmp/deploy -type f -exec sh -c 'tr -d "\r" < "$1" > "$1.tmp" && mv "$1.tmp" "$1"' _ {} \;
tr -d '\r' < moodle/Dockerfile > /tmp/deploy_Dockerfile
tr -d '\r' < moodle/cli/configure_ai.php > /tmp/deploy_configure_ai.php
scp -i "$KEY" -o BatchMode=yes -r /tmp/deploy/aiplacement_tuteur ubuntu@$HOST:ai-moodle-security/plugin/
scp -i "$KEY" -o BatchMode=yes /tmp/deploy_Dockerfile ubuntu@$HOST:ai-moodle-security/moodle/Dockerfile
scp -i "$KEY" -o BatchMode=yes /tmp/deploy_configure_ai.php ubuntu@$HOST:ai-moodle-security/moodle/cli/configure_ai.php
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose up -d --build moodle 2>&1 | tail -15'
```
Expected: le build se termine sans erreur, le conteneur `moodle` redémarre.

- [ ] **Step 12 : Créer et exécuter le script de vérification (sur l'EC2, pas committé)**

```php
<?php
// Verification jetable Task 1 -- PAS commitee au depot.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB;

$plugininfo = \core_plugin_manager::instance()->get_plugin_info('aiplacement_tuteur');
echo "plugin trouve : ", var_export($plugininfo !== null, true), "\n";
echo "plugin active : ", var_export($plugininfo && $plugininfo->is_enabled(), true), "\n";

$course = $DB->get_record('course', ['shortname' => 'PYTHON101'], '*', MUST_EXIST);
$context = \context_course::instance($course->id);
$student = $DB->get_record('user', ['username' => 'etudiant1'], '*', MUST_EXIST);

echo "capacite accordee a etudiant1 : ", var_export(has_capability('aiplacement/tuteur:use', $context, $student), true), "\n";
echo "is_tutor_available() pour etudiant1 : ", var_export(\aiplacement_tuteur\utils::is_tutor_available($context), true), "\n";
```

```bash
cat > /tmp/verify_tuteur_task1.php <<'EOF'
<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB;
$plugininfo = \core_plugin_manager::instance()->get_plugin_info('aiplacement_tuteur');
echo "plugin trouve : ", var_export($plugininfo !== null, true), "\n";
echo "plugin active : ", var_export($plugininfo && $plugininfo->is_enabled(), true), "\n";
$course = $DB->get_record('course', ['shortname' => 'PYTHON101'], '*', MUST_EXIST);
$context = \context_course::instance($course->id);
$student = $DB->get_record('user', ['username' => 'etudiant1'], '*', MUST_EXIST);
echo "capacite accordee a etudiant1 : ", var_export(has_capability('aiplacement/tuteur:use', $context, $student), true), "\n";
echo "is_tutor_available() pour etudiant1 : ", var_export(\aiplacement_tuteur\utils::is_tutor_available($context), true), "\n";
EOF
scp -i "$KEY" -o BatchMode=yes /tmp/verify_tuteur_task1.php ubuntu@$HOST:/tmp/verify_tuteur_task1.php
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose exec -T moodle sh -c "cat > /tmp/verify_tuteur_task1.php" < /tmp/verify_tuteur_task1.php && docker compose exec -T moodle php /tmp/verify_tuteur_task1.php'
```
Expected (avant ce task, ce script échouerait avec « Class aiplacement_tuteur\utils not found » et
`plugin trouve : false`) : les 4 lignes affichent `true`. Si `plugin active : false`, relancer
`docker compose exec -T moodle php admin/cli/upgrade.php --non-interactive` puis
`docker compose exec -T moodle php cli/configure_ai.php`.

- [ ] **Step 13 : Commit**

```bash
git add plugin/aiplacement_tuteur moodle/Dockerfile moodle/cli/configure_ai.php
git commit -m "feat(aiplacement_tuteur): scaffold du plugin (placement, utils, capacite, langues)

Nouveau placement IA aiplacement_tuteur/ : placement.php (declare generate_text), utils.php
(is_tutor_available, calque sur aiplacement_courseassist), capacite aiplacement/tuteur:use
(student/teacher/editingteacher/manager), chaines en/fr. Dockerfile copie le plugin dans
ai/placement/tuteur ; configure_ai.php l'active via aiplacement::enable_plugin('tuteur', 1).
Aucune interface pour l'instant (Task 3+). Verifie sur l'EC2 : plugin reconnu, active,
capacite accordee a etudiant1, is_tutor_available() = true."
```

---

## Task 2 : Service web `generate_text`

**Files:**
- Create: `plugin/aiplacement_tuteur/db/services.php`
- Create: `plugin/aiplacement_tuteur/classes/external/generate_text.php`
- Test (sur l'EC2) : `moodle/cli/verify_tuteur_task2.php` (jetable, pas committé)

**Interfaces:**
- Consumes: `\aiplacement_tuteur\utils::is_tutor_available()` (Task 1), chaîne `notutor` (Task 1).
- Produces: service web `aiplacement_tuteur_generate_text` ;
  `\aiplacement_tuteur\external\generate_text::execute(int $contextid, string $prompttext): array`
  retournant exactement les clés `success, timecreated, prompttext, generatedcontent,
  finishreason, errorcode, error` — signature réutilisée telle quelle par Task 5 (JavaScript).

- [ ] **Step 1 : Créer `db/services.php`**

```php
<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'aiplacement_tuteur_generate_text' => [
        'classname' => \aiplacement_tuteur\external\generate_text::class,
        'description' => 'Generate a tutor answer for the AI tutor placement',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];
```

- [ ] **Step 2 : Créer `classes/external/generate_text.php`**

```php
<?php
namespace aiplacement_tuteur\external;

use aiplacement_tuteur\utils;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;

/**
 * API externe : recoit la question de l'etudiant, l'envoie au gestionnaire IA, renvoie la reponse.
 */
class generate_text extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context ID', VALUE_REQUIRED),
            'prompttext' => new external_value(PARAM_RAW, 'The question for the AI tutor', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $contextid, string $prompttext): array {
        global $USER;
        [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
        ] = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
        ]);

        $context = \core\context::instance_by_id($contextid);
        self::validate_context($context);

        // Echoue fort si la capacite manque (posture fail-closed, cf. spec section 7).
        require_capability('aiplacement/tuteur:use', $context);
        if (!utils::is_tutor_available($context)) {
            throw new \moodle_exception('notutor', 'aiplacement_tuteur');
        }

        $action = new \core_ai\aiactions\generate_text(
            contextid: $contextid,
            userid: $USER->id,
            prompttext: $prompttext,
        );

        $manager = \core\di::get(\core_ai\manager::class);
        $response = $manager->process_action($action);

        return [
            'success' => $response->get_success(),
            // Ne JAMAIS passer generatedcontent dans format_text() : deja echappe par N4
            // (htmlspecialchars). Toute balise ajoutee ici fait echouer PARAM_TEXT des que la
            // reponse depasse un paragraphe (cf. Global Constraints et spec section 7).
            'generatedcontent' => $response->get_response_data()['generatedcontent'] ?? '',
            'finishreason' => $response->get_response_data()['finishreason'] ?? '',
            'errorcode' => $response->get_errorcode(),
            'error' => $response->get_errormessage(),
            'timecreated' => $response->get_timecreated(),
            'prompttext' => $prompttext,
        ];
    }

    public static function execute_returns(): external_function_parameters {
        return new external_function_parameters([
            'success' => new external_value(PARAM_BOOL, 'Was the request successful', VALUE_REQUIRED),
            'timecreated' => new external_value(PARAM_INT, 'The time the request was created', VALUE_REQUIRED),
            'prompttext' => new external_value(PARAM_RAW, 'The question for the AI tutor', VALUE_REQUIRED),
            'generatedcontent' => new external_value(PARAM_TEXT, 'The tutor answer.', VALUE_DEFAULT, ''),
            'finishreason' => new external_value(PARAM_ALPHAEXT, 'The reason generation was stopped', VALUE_DEFAULT, 'stop'),
            'errorcode' => new external_value(PARAM_INT, 'Error code if any', VALUE_DEFAULT, 0),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_DEFAULT, ''),
        ]);
    }
}
```

- [ ] **Step 3 : Vérifier la syntaxe PHP localement**

```bash
/c/tools/php84/php -l plugin/aiplacement_tuteur/db/services.php
/c/tools/php84/php -l plugin/aiplacement_tuteur/classes/external/generate_text.php
```
Expected: `No syntax errors detected` pour les deux fichiers.

- [ ] **Step 4 : Déployer et reconstruire**

```bash
KEY=$(grep AWS_REMOTE_KEY_PATH .env | cut -d= -f2- | tr -d '\r"')
HOST=$(grep AWS_REMOTE_HOST .env | cut -d= -f2- | tr -d '\r')
rm -rf /tmp/deploy && mkdir -p /tmp/deploy
cp -r plugin/aiplacement_tuteur /tmp/deploy/
find /tmp/deploy -type f -exec sh -c 'tr -d "\r" < "$1" > "$1.tmp" && mv "$1.tmp" "$1"' _ {} \;
scp -i "$KEY" -o BatchMode=yes -r /tmp/deploy/aiplacement_tuteur ubuntu@$HOST:ai-moodle-security/plugin/
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose up -d --build moodle 2>&1 | tail -10'
```

- [ ] **Step 5 : Créer et exécuter le script de vérification (3 cas, Review Focus 1 et 2)**

```php
<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB;

$student = $DB->get_record('user', ['username' => 'etudiant1'], '*', MUST_EXIST);
\core\session\manager::set_user($student);
$course = $DB->get_record('course', ['shortname' => 'PYTHON101'], '*', MUST_EXIST);
$context = \context_course::instance($course->id);
$roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);

// Cas 1 : question normale -> reponse non vide, success=true.
$r = \core_external\external_api::call_external_function('aiplacement_tuteur_generate_text',
    ['contextid' => $context->id, 'prompttext' => "Qu'est-ce qu'une variable en Python ?"], false);
echo "cas 1 (normal) : error=", var_export($r['error'], true);
if (!$r['error']) {
    echo " success=", var_export($r['data']['success'], true), " longueur=", strlen($r['data']['generatedcontent']);
}
echo "\n";

// Cas 2 (Review Focus 1) : question vide -> N1 bloque dans client.php, mais AUCUNE exception :
// success reste true avec un message gracieux (jamais une erreur fatale sur une entree invalide).
$r2 = \core_external\external_api::call_external_function('aiplacement_tuteur_generate_text',
    ['contextid' => $context->id, 'prompttext' => "   "], false);
echo "cas 2 (vide) : error=", var_export($r2['error'], true);
if (!$r2['error']) {
    echo " success=", var_export($r2['data']['success'], true), " reponse=", $r2['data']['generatedcontent'];
}
echo "\n";

// Cas 3 (Review Focus 2) : capacite retiree -> require_capability doit lancer une exception propre.
assign_capability('aiplacement/tuteur:use', CAP_PREVENT, $roleid, $context->id, true);
accesslib_clear_all_caches(false);
$r3 = \core_external\external_api::call_external_function('aiplacement_tuteur_generate_text',
    ['contextid' => $context->id, 'prompttext' => "Question quelconque"], false);
echo "cas 3 (capacite retiree) : error=", var_export($r3['error'], true);
if ($r3['error']) {
    echo " errorcode=", $r3['exception']->errorcode;
}
echo "\n";
// Nettoyage : retablir la capacite par defaut pour ne pas casser la demo.
unassign_capability('aiplacement/tuteur:use', $roleid, $context->id);
accesslib_clear_all_caches(false);
echo "capacite retablie : ", var_export(has_capability('aiplacement/tuteur:use', $context, $student), true), "\n";
```

```bash
cat > /tmp/verify_tuteur_task2.php <<'PHPEOF'
<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB;
$student = $DB->get_record('user', ['username' => 'etudiant1'], '*', MUST_EXIST);
\core\session\manager::set_user($student);
$course = $DB->get_record('course', ['shortname' => 'PYTHON101'], '*', MUST_EXIST);
$context = \context_course::instance($course->id);
$roleid = $DB->get_field('role', 'id', ['shortname' => 'student'], MUST_EXIST);
$r = \core_external\external_api::call_external_function('aiplacement_tuteur_generate_text',
    ['contextid' => $context->id, 'prompttext' => "Qu'est-ce qu'une variable en Python ?"], false);
echo "cas 1 (normal) : error=", var_export($r['error'], true);
if (!$r['error']) { echo " success=", var_export($r['data']['success'], true), " longueur=", strlen($r['data']['generatedcontent']); }
echo "\n";
$r2 = \core_external\external_api::call_external_function('aiplacement_tuteur_generate_text',
    ['contextid' => $context->id, 'prompttext' => "   "], false);
echo "cas 2 (vide) : error=", var_export($r2['error'], true);
if (!$r2['error']) { echo " success=", var_export($r2['data']['success'], true), " reponse=", $r2['data']['generatedcontent']; }
echo "\n";
assign_capability('aiplacement/tuteur:use', CAP_PREVENT, $roleid, $context->id, true);
accesslib_clear_all_caches(false);
$r3 = \core_external\external_api::call_external_function('aiplacement_tuteur_generate_text',
    ['contextid' => $context->id, 'prompttext' => "Question quelconque"], false);
echo "cas 3 (capacite retiree) : error=", var_export($r3['error'], true);
if ($r3['error']) { echo " errorcode=", $r3['exception']->errorcode; }
echo "\n";
unassign_capability('aiplacement/tuteur:use', $roleid, $context->id);
accesslib_clear_all_caches(false);
echo "capacite retablie : ", var_export(has_capability('aiplacement/tuteur:use', $context, $student), true), "\n";
PHPEOF
scp -i "$KEY" -o BatchMode=yes /tmp/verify_tuteur_task2.php ubuntu@$HOST:/tmp/verify_tuteur_task2.php
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose exec -T moodle sh -c "cat > /tmp/verify_tuteur_task2.php" < /tmp/verify_tuteur_task2.php && docker compose exec -T moodle php /tmp/verify_tuteur_task2.php'
```
Expected :
```
cas 1 (normal) : error=false success=true longueur=<un nombre > 0>
cas 2 (vide) : error=false success=true reponse=<message de refus du client, pas une chaine vide>
cas 3 (capacite retiree) : error=true errorcode=nopermissions
capacite retablie : true
```
Si le cas 1 échoue avec `error=true`, vérifier que le conteneur `ollama` répond
(`docker compose exec -T ollama ollama ps`) avant de creuser le code du plugin.

- [ ] **Step 6 : Commit**

```bash
git add plugin/aiplacement_tuteur/db/services.php plugin/aiplacement_tuteur/classes/external/generate_text.php
git commit -m "feat(aiplacement_tuteur): service web generate_text

aiplacement_tuteur_generate_text -> classes/external/generate_text.php, calque combine
d'aiplacement_editor (echec silencieux -> throw via is_tutor_available) et
aiplacement_courseassist (require_capability explicite, fail-closed). generatedcontent en
PARAM_TEXT, retourne tel quel (deja echappe par N4, jamais de format_text() supplementaire).
Verifie sur l'EC2 : reponse normale OK, question vide geree sans exception (N1), capacite
retiree -> exception nopermissions propre."
```

---

## Task 3 : Hooks, visibilité et squelette d'interface (bouton + tiroir vide)

**Files:**
- Create: `plugin/aiplacement_tuteur/db/hooks.php`
- Create: `plugin/aiplacement_tuteur/classes/hook_callbacks.php`
- Create: `plugin/aiplacement_tuteur/classes/output/tutor_ui.php`
- Create: `plugin/aiplacement_tuteur/templates/button.mustache`
- Create: `plugin/aiplacement_tuteur/templates/drawer.mustache`
- Test (sur l'EC2) : `moodle/cli/verify_tuteur_task3.php` + script bash `curl` (jetables)

**Interfaces:**
- Consumes: `\aiplacement_tuteur\utils::is_tutor_available()` (Task 1), chaînes
  `tutorbuttonlabel`/`tutortooltip`/`aidrawerlabel` (Task 1), hooks cœur
  `\core\hook\output\before_footer_html_generation`, `\core\hook\output\after_http_headers`.
- Produces: gabarits `aiplacement_tuteur/button`, `aiplacement_tuteur/drawer` (ce dernier référence
  le module `aiplacement_tuteur/placement`, qui n'existe pas encore avant Task 5 — sans
  conséquence pour ce task, qui ne clique sur rien).

- [ ] **Step 1 : Créer `db/hooks.php`**

```php
<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => \aiplacement_tuteur\hook_callbacks::class . '::before_footer_html_generation',
        'priority' => 0,
    ],
    [
        'hook' => \core\hook\output\after_http_headers::class,
        'callback' => \aiplacement_tuteur\hook_callbacks::class . '::after_http_headers',
        'priority' => 0,
    ],
];
```

- [ ] **Step 2 : Créer `classes/hook_callbacks.php`**

```php
<?php
namespace aiplacement_tuteur;

use core\hook\output\after_http_headers;
use core\hook\output\before_footer_html_generation;

/**
 * Relaie les hooks de sortie vers tutor_ui.
 */
class hook_callbacks {
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        \aiplacement_tuteur\output\tutor_ui::load_tutor_ui($hook);
    }

    public static function after_http_headers(after_http_headers $hook): void {
        \aiplacement_tuteur\output\tutor_ui::load_tutor_button($hook);
    }
}
```

- [ ] **Step 3 : Créer `classes/output/tutor_ui.php`**

```php
<?php
namespace aiplacement_tuteur\output;

use aiplacement_tuteur\utils;
use core\hook\output\after_http_headers;
use core\hook\output\before_footer_html_generation;

/**
 * Gestionnaire de sortie pour l'emplacement tuteur.
 */
class tutor_ui {
    public static function load_tutor_ui(before_footer_html_generation $hook): void {
        global $PAGE, $OUTPUT, $USER;
        if (!self::preflight_checks()) {
            return;
        }
        $params = [
            'userid' => $USER->id,
            'contextid' => $PAGE->context->id,
        ];
        $html = $OUTPUT->render_from_template('aiplacement_tuteur/drawer', $params);
        $hook->add_html($html);
    }

    public static function load_tutor_button(after_http_headers $hook): void {
        global $OUTPUT;
        if (!self::preflight_checks()) {
            return;
        }
        $html = $OUTPUT->render_from_template('aiplacement_tuteur/button', []);
        $hook->add_html($html);
    }

    /**
     * Determine si le bouton/tiroir doit s'afficher sur la page courante.
     *
     * Elargit la regle d'aiplacement_courseassist (CONTEXT_MODULE seul) a CONTEXT_COURSE
     * egalement, pour une visibilite "partout dans le cours" (spec section 6).
     */
    private static function preflight_checks(): bool {
        global $PAGE;
        if (during_initial_install()) {
            return false;
        }
        if (!get_config('aiplacement_tuteur', 'version')) {
            return false;
        }
        if (in_array($PAGE->pagelayout, ['maintenance', 'print', 'redirect', 'embedded', 'login'])) {
            return false;
        }
        if (!in_array($PAGE->context->contextlevel, [CONTEXT_COURSE, CONTEXT_MODULE], true)) {
            return false;
        }
        if ((isset($PAGE->course) ? $PAGE->course->id : SITEID) == SITEID) {
            return false;
        }
        return utils::is_tutor_available($PAGE->context);
    }
}
```

- [ ] **Step 4 : Créer `templates/button.mustache`**

```mustache
{{!
    @template aiplacement_tuteur/button

    Bouton qui ouvre le tiroir de discussion du tuteur IA.

    Context variables required for this template:
    * none

    Example context (json):
    {
    }
}}
<div class="ai-tutor-controls pt-3 pb-3">
    <button class="btn btn-outline-secondary"
            id="ai-tutor-open"
            aria-controls="ai-tutor-drawer"
            type="button"
            data-action="tutor-open"
            data-toggle="tooltip"
            data-html="true"
            data-delay="200"
            title="{{#str}} tutortooltip, aiplacement_tuteur {{/str}}"
    >
        {{#pix}} t/message, core {{/pix}}
        {{#str}} tutorbuttonlabel, aiplacement_tuteur {{/str}}
    </button>
    {{> core_message/message_jumpto }}
</div>
```

- [ ] **Step 5 : Créer `templates/drawer.mustache`**

```mustache
{{!
    @template aiplacement_tuteur/drawer

    Coquille du tiroir de discussion du tuteur IA. Le JavaScript (Task 5) y injecte le
    formulaire de question, le chargement, la reponse ou l'erreur.

    Context variables required for this template:
    * userid - User ID
    * contextid - Context ID

    Example context (json):
    {
        "userid": "2",
        "contextid": "25"
    }
}}
<div class="ai-tutor-drawer" id="ai-tutor-drawer" aria-label={{#quote}}{{#str}} aidrawerlabel, aiplacement_tuteur {{/str}}{{/quote}} tabindex="-1" role="region">
    <div class="ai-tutor-drawer-header">
        <button id="ai-tutor-drawer-close" class="btn ai-tutor-drawer-button" type="button" data-action="tutor-open">
            {{!-- Reutilise data-action="tutor-open" (pas un "tutor-close" dedie) : le gestionnaire
                 de clic de placement.js appelle toggleDrawer(), qui ferme si deja ouvert. Meme
                 mecanisme qu'aiplacement_courseassist (son bouton de fermeture partage le
                 data-action de son bouton d'ouverture). --}}
            {{#pix}} e/cancel, core {{/pix}}
            <span class="sr-only">{{#str}} closedrawer, core {{/str}}</span>
        </button>
    </div>
    <div class="ai-tutor-drawer-body" id="ai-tutor-drawer-body" data-hasdata="0" data-cancelled="0">
    </div>
</div>
{{#js}}
    require(['aiplacement_tuteur/placement'], function(AITutor) {
        const AI = new AITutor({{userid}}, {{contextid}});
    });
{{/js}}
```

- [ ] **Step 6 : Vérifier la syntaxe PHP localement**

```bash
/c/tools/php84/php -l plugin/aiplacement_tuteur/db/hooks.php
/c/tools/php84/php -l plugin/aiplacement_tuteur/classes/hook_callbacks.php
/c/tools/php84/php -l plugin/aiplacement_tuteur/classes/output/tutor_ui.php
```
Expected: `No syntax errors detected` pour les trois.

- [ ] **Step 7 : Déployer et reconstruire**

```bash
rm -rf /tmp/deploy && mkdir -p /tmp/deploy
cp -r plugin/aiplacement_tuteur /tmp/deploy/
find /tmp/deploy -type f -exec sh -c 'tr -d "\r" < "$1" > "$1.tmp" && mv "$1.tmp" "$1"' _ {} \;
scp -i "$KEY" -o BatchMode=yes -r /tmp/deploy/aiplacement_tuteur ubuntu@$HOST:ai-moodle-security/plugin/
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose up -d --build moodle 2>&1 | tail -10'
```

- [ ] **Step 8 : Vérifier le rendu des gabarits (PHP, sans navigateur)**

```bash
cat > /tmp/verify_tuteur_task3_render.php <<'PHPEOF'
<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $OUTPUT;
$html1 = $OUTPUT->render_from_template('aiplacement_tuteur/button', []);
echo "bouton contient data-action=tutor-open : ", var_export(strpos($html1, 'data-action="tutor-open"') !== false, true), "\n";
$html2 = $OUTPUT->render_from_template('aiplacement_tuteur/drawer', ['userid' => 2, 'contextid' => 25]);
echo "tiroir contient id=ai-tutor-drawer : ", var_export(strpos($html2, 'id="ai-tutor-drawer"') !== false, true), "\n";
echo "tiroir reference le module JS aiplacement_tuteur/placement : ", var_export(strpos($html2, 'aiplacement_tuteur/placement') !== false, true), "\n";
PHPEOF
scp -i "$KEY" -o BatchMode=yes /tmp/verify_tuteur_task3_render.php ubuntu@$HOST:/tmp/verify_tuteur_task3_render.php
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose exec -T moodle sh -c "cat > /tmp/verify_tuteur_task3_render.php" < /tmp/verify_tuteur_task3_render.php && docker compose exec -T moodle php /tmp/verify_tuteur_task3_render.php'
```
Expected: les 3 lignes affichent `true`.

- [ ] **Step 9 : Vérifier la visibilité réelle sur 3 pages (Review Focus 5, `curl`, sans navigateur)**

```bash
cat > /tmp/check_tuteur_visibility.sh <<'SHEOF'
H=lms.pkfokam; R="--resolve $H:443:127.0.0.1"; JAR=$(mktemp)
read -r PW
curl -sk -c $JAR $R https://$H/login/index.php -o /tmp/login.html
TOKEN=$(grep -o 'name="logintoken" value="[^"]*"' /tmp/login.html | head -1 | sed 's/.*value="//;s/"//')
curl -sk -b $JAR -c $JAR $R -o /dev/null -d "username=etudiant1" --data-urlencode "password=$PW" -d "logintoken=$TOKEN" https://$H/login/index.php
COURSEID=$(docker compose exec -T moodle php -r "define('CLI_SCRIPT', true); require '/var/www/html/config.php'; global \$DB; echo \$DB->get_field('course', 'id', ['shortname' => 'PYTHON101']);")
check() { local url="$1" label="$2"; local n; n=$(curl -sk -b $JAR $R "$url" | grep -c 'data-action="tutor-open"'); echo "$label : bouton present=$([ "$n" -gt 0 ] && echo oui || echo non)"; }
check "https://$H/course/view.php?id=$COURSEID" "page du cours PYTHON101 (attendu: oui)"
check "https://$H/" "page d'accueil du site (attendu: non)"
check "https://$H/admin/index.php" "page d'administration (attendu: non)"
rm -f $JAR
SHEOF
tr -d '\r' < /tmp/check_tuteur_visibility.sh | ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cat > ~/ai-moodle-security/check_tuteur_visibility.sh'
awk -F'\t' '$1=="etudiant1"{print $2}' secrets/demo_users.txt | tr -d '\r' | ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && bash check_tuteur_visibility.sh'
```
Expected:
```
page du cours PYTHON101 (attendu: oui) : bouton present=oui
page d'accueil du site (attendu: non) : bouton present=non
page d'administration (attendu: non) : bouton present=non
```

- [ ] **Step 10 : Commit**

```bash
git add plugin/aiplacement_tuteur/db/hooks.php plugin/aiplacement_tuteur/classes/hook_callbacks.php plugin/aiplacement_tuteur/classes/output/tutor_ui.php plugin/aiplacement_tuteur/templates/button.mustache plugin/aiplacement_tuteur/templates/drawer.mustache
git commit -m "feat(aiplacement_tuteur): hooks, visibilite et squelette d'interface

db/hooks.php + hook_callbacks.php + classes/output/tutor_ui.php, calques sur
aiplacement_courseassist. preflight_checks() elargit la visibilite a CONTEXT_COURSE en plus de
CONTEXT_MODULE (visible partout dans le cours, pas seulement sur les activites), exclut
SITEID et les pagelayouts maintenance/print/redirect/embedded/login.

Verifie sur l'EC2 par requetes HTTP reelles (sans navigateur) : le bouton apparait sur la page
du cours PYTHON101, absent de la page d'accueil du site et des pages d'administration."
```

---

## Task 4 : Gabarits restants (formulaire, chargement, réponse, erreur) + styles

**Files:**
- Create: `plugin/aiplacement_tuteur/templates/ask.mustache`
- Create: `plugin/aiplacement_tuteur/templates/loading.mustache`
- Create: `plugin/aiplacement_tuteur/templates/response.mustache`
- Create: `plugin/aiplacement_tuteur/templates/error.mustache`
- Create: `plugin/aiplacement_tuteur/styles.css`
- Test (sur l'EC2) : `moodle/cli/verify_tuteur_task4.php` (jetable)

**Interfaces:**
- Consumes: chaînes `askplaceholder`/`send`/`generating`/`generatefailtitle`/`tryagain`/
  `newquestion`/`regenerate` (Task 1), chaîne `contentwatermark` (core_ai, cœur Moodle, réutilisée
  sans la redéclarer), chaîne `copy` (core, réutilisée sans la redéclarer).
- Produces: gabarits `aiplacement_tuteur/ask`, `loading`, `response`, `error` — les sélecteurs
  `data-action` qu'ils exposent (`tutor-ask`, `tutor-cancel`, `tutor-regenerate`,
  `tutor-newquestion`, `tutor-retry`) sont consommés tels quels par `amd/src/selectors.js`
  (Task 5) : les noms doivent correspondre exactement.

- [ ] **Step 1 : Créer `templates/ask.mustache`**

```mustache
{{!
    @template aiplacement_tuteur/ask

    Formulaire de saisie d'une question pour le tuteur IA.

    Context variables required for this template:
    * none

    Example context (json):
    {
    }
}}
<section class="ai-tutor-ask mb-3">
    <label for="ai-tutor-question" class="sr-only">{{#str}} askplaceholder, aiplacement_tuteur {{/str}}</label>
    <textarea id="ai-tutor-question"
              class="form-control"
              rows="4"
              placeholder="{{#str}} askplaceholder, aiplacement_tuteur {{/str}}"
    ></textarea>
    <div class="d-block pt-3">
        <button class="btn btn-primary" type="button" data-action="tutor-ask" disabled>
            {{#str}} send, aiplacement_tuteur {{/str}}
        </button>
    </div>
</section>
```

- [ ] **Step 2 : Créer `templates/loading.mustache`**

```mustache
{{!
    @template aiplacement_tuteur/loading

    Indicateur affiche pendant la generation de la reponse.

    Context variables required for this template:
    * none

    Example context (json):
    {
    }
}}
<section class="card mb-3">
    <div class="card-body p-3">
        <h3 class="h6 card-title d-inline">
            {{#pix}} i/loading, core {{/pix}}
            {{#str}} generating, aiplacement_tuteur {{/str}}
        </h3>
        <div class="card-text content mt-3">
            <div class="d-block pt-3">
                <button class="btn btn-sm btn-outline-secondary" data-action="tutor-cancel">
                    {{#str}} cancel, core {{/str}}
                </button>
            </div>
        </div>
    </div>
</section>
```

- [ ] **Step 3 : Créer `templates/response.mustache`**

```mustache
{{!
    @template aiplacement_tuteur/response

    Affiche la question posee et la reponse du tuteur.

    Context variables required for this template:
    * question - La question posee (texte brut)
    * content - La reponse generee (HTML deja echappe par N4)

    Example context (json):
    {
        "question": "Qu'est-ce qu'une liste en Python ?",
        "content": "<p>Une liste est ...</p>"
    }
}}
<section class="card mb-3">
    <div class="card-body p-3">
        <h3 class="h6 card-title">{{question}}</h3>
        <div class="card-text content mt-3">
            <div id="ai-tutor-response" class="mb-3">
                {{{content}}}
            </div>
            <div class="ai-tutor-response-watermark">
                <small class="text-muted">
                    {{#pix}} t/message, core {{/pix}}
                    {{#str}} contentwatermark, core_ai {{/str}}
                </small>
            </div>
            <div class="ai-tutor-response-controls d-block pt-3">
                <button class="btn btn-sm btn-outline-secondary" data-action="tutor-regenerate">
                    {{#pix}} a/refresh, core {{/pix}}
                    {{#str}} regenerate, aiplacement_tuteur {{/str}}
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-action="tutor-newquestion">
                    {{#str}} newquestion, aiplacement_tuteur {{/str}}
                </button>
                <button class="btn btn-sm btn-outline-secondary" data-action="copytoclipboard" data-clipboard-target="#ai-tutor-response">
                    {{#pix}} e/copy, core {{/pix}}
                    {{#str}} copy, core {{/str}}
                </button>
            </div>
        </div>
    </div>
</section>
```

- [ ] **Step 4 : Créer `templates/error.mustache`**

```mustache
{{!
    @template aiplacement_tuteur/error

    Message affiche quand l'appel au tuteur echoue.

    Context variables required for this template:
    * none

    Example context (json):
    {
    }
}}
<section class="mb-3">
    <div class="card-body p-3">
        <h3 class="h6 card-title d-inline">
            {{#pix}} req, core {{/pix}}
            {{#str}} generatefailtitle, aiplacement_tuteur {{/str}}
        </h3>
        <div class="card-text content mt-3">
            <div class="d-block pt-3">
                <button class="btn btn-sm btn-outline-secondary" data-action="tutor-retry">
                    {{#str}} tryagain, aiplacement_tuteur {{/str}}
                </button>
            </div>
        </div>
    </div>
</section>
```

- [ ] **Step 5 : Créer `styles.css`**

```css
.ai-tutor-drawer {
    position: fixed;
    top: 60px;
    bottom: 0;
    right: calc(-315px + -10px);
    width: 315px;
    background-color: #f8f9fa;
    z-index: 1016;
    transition: right 0.2s ease, top 0.2s ease, bottom 0.2s ease, visibility 0.2s ease, transform 0.5s ease;
    visibility: hidden;
}
.ai-tutor-drawer.show {
    right: 0;
    visibility: visible;
}
.ai-tutor-drawer-header {
    padding: 0;
    height: 60px;
    display: flex;
    align-items: center;
}
.ai-tutor-drawer-header .ai-tutor-drawer-button {
    margin-left: auto;
    margin-right: 5px;
}
.ai-tutor-drawer-body {
    position: relative;
    height: calc(100vh - 120px);
    display: flex;
    flex-direction: column;
    flex-wrap: nowrap;
    padding: 0.4rem;
    overflow-y: auto;
    scrollbar-width: thin;
    scrollbar-color: #6a737b #f8f9fa;
}
#ai-tutor-response {
    font-size: 0.875em;
}
.ai-tutor-response-controls button .icon,
.ai-tutor-response-watermark img.icon {
    margin-right: 0;
}
```

- [ ] **Step 6 : Déployer et reconstruire**

```bash
rm -rf /tmp/deploy && mkdir -p /tmp/deploy
cp -r plugin/aiplacement_tuteur /tmp/deploy/
find /tmp/deploy -type f -exec sh -c 'tr -d "\r" < "$1" > "$1.tmp" && mv "$1.tmp" "$1"' _ {} \;
scp -i "$KEY" -o BatchMode=yes -r /tmp/deploy/aiplacement_tuteur ubuntu@$HOST:ai-moodle-security/plugin/
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose up -d --build moodle 2>&1 | tail -10'
```

- [ ] **Step 7 : Vérifier le rendu de chaque gabarit avec des données d'exemple**

```bash
cat > /tmp/verify_tuteur_task4.php <<'PHPEOF'
<?php
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $OUTPUT;
$ask = $OUTPUT->render_from_template('aiplacement_tuteur/ask', []);
echo "ask contient textarea#ai-tutor-question : ", var_export(strpos($ask, 'id="ai-tutor-question"') !== false, true), "\n";
echo "ask contient data-action=tutor-ask : ", var_export(strpos($ask, 'data-action="tutor-ask"') !== false, true), "\n";

$loading = $OUTPUT->render_from_template('aiplacement_tuteur/loading', []);
echo "loading contient data-action=tutor-cancel : ", var_export(strpos($loading, 'data-action="tutor-cancel"') !== false, true), "\n";

$response = $OUTPUT->render_from_template('aiplacement_tuteur/response', ['question' => 'Ma question ?', 'content' => '<p>Ma reponse.</p>']);
echo "response affiche la question : ", var_export(strpos($response, 'Ma question ?') !== false, true), "\n";
echo "response affiche le contenu : ", var_export(strpos($response, '<p>Ma reponse.</p>') !== false, true), "\n";
echo "response contient tutor-regenerate et tutor-newquestion : ", var_export(strpos($response, 'tutor-regenerate') !== false && strpos($response, 'tutor-newquestion') !== false, true), "\n";

$error = $OUTPUT->render_from_template('aiplacement_tuteur/error', []);
echo "error contient data-action=tutor-retry : ", var_export(strpos($error, 'data-action="tutor-retry"') !== false, true), "\n";
PHPEOF
scp -i "$KEY" -o BatchMode=yes /tmp/verify_tuteur_task4.php ubuntu@$HOST:/tmp/verify_tuteur_task4.php
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose exec -T moodle sh -c "cat > /tmp/verify_tuteur_task4.php" < /tmp/verify_tuteur_task4.php && docker compose exec -T moodle php /tmp/verify_tuteur_task4.php'
```
Expected: les 7 lignes affichent `true`.

- [ ] **Step 8 : Commit**

```bash
git add plugin/aiplacement_tuteur/templates/ask.mustache plugin/aiplacement_tuteur/templates/loading.mustache plugin/aiplacement_tuteur/templates/response.mustache plugin/aiplacement_tuteur/templates/error.mustache plugin/aiplacement_tuteur/styles.css
git commit -m "feat(aiplacement_tuteur): gabarits formulaire/chargement/reponse/erreur + styles

templates/ask.mustache (nouveau : aucune des deux references n'a de saisie libre),
loading/response/error adaptes d'aiplacement_courseassist. styles.css repris du tiroir
coulissant generique de courseassist, classes renommees ai-tutor-*.

Verifie sur l'EC2 : rendu de chaque gabarit avec donnees d'exemple, tous les
data-action/id attendus par amd/src/selectors.js (Task 5) sont presents."
```

---

## Task 5 : JavaScript AMD (source + compilation)

**Files:**
- Create: `plugin/aiplacement_tuteur/amd/src/placement.js`
- Create: `plugin/aiplacement_tuteur/amd/src/selectors.js`
- Create: `plugin/aiplacement_tuteur/amd/build/placement.min.js` (compilé, committé)
- Create: `plugin/aiplacement_tuteur/amd/build/placement.min.js.map` (compilé, committé)
- Create: `plugin/aiplacement_tuteur/amd/build/selectors.min.js` (compilé, committé)
- Create: `plugin/aiplacement_tuteur/amd/build/selectors.min.js.map` (compilé, committé)

**Contexte technique important** : l'image Docker Moodle **n'a ni Node, ni npm, ni Grunt**
(vérifié le 2026-10-01 : `command -v node/grunt/npx` ne renvoie rien dans le conteneur). Moodle
**n'a aucun mécanisme de secours** pour exécuter un module AMD non compilé : le navigateur charge
toujours `amd/build/<module>.min.js`, jamais `amd/src/*.js` directement, quel que soit le mode de
débogage. La compilation doit donc se faire **une seule fois, hors de l'image**, et le résultat
**committé** dans le dépôt — exactement comme Moodle le fait pour ses propres plugins
(`ai/placement/courseassist/amd/build/*.min.js` est un fichier versionné dans le dépôt Moodle, pas
généré à l'installation). Le `Dockerfile` n'a besoin d'aucune modification pour ce task : la ligne
`COPY plugin/aiplacement_tuteur /var/www/html/ai/placement/tuteur` (Task 1) copie `amd/build/` au
même titre que le reste.

Moodle 4.5 exige Node **22.11.0 à 22.x exclu 23** (`"engines": {"node": ">=22.11.0 <23"}` dans son
`package.json`, confirmé sur l'EC2). La compilation se fait dans un **conteneur Docker jetable**
(reproductible, ne dépend pas de la version de Node installée sur la machine de développement).

**Interfaces:**
- Consumes: webservice `aiplacement_tuteur_generate_text` (Task 2, args `{contextid, prompttext}`,
  retour `{success, error, generatedcontent, ...}`) ; gabarits `aiplacement_tuteur/ask`,
  `loading`, `response`, `error` (Task 4) ; `aiplacement_tuteur/drawer` les référence déjà
  (Task 3) ; core : `core_ai/policy` (`Policy.getPolicyStatus(userId)`, `Policy.acceptPolicy()`),
  `core_ai/helper` (`AIHelper.replaceLineBreaks(text)`), `core_ai/policyblock` (gabarit).
- Produces: module AMD `aiplacement_tuteur/placement` (export par défaut : classe `AITutor`,
  constructeur `(userId, contextId)`), module `aiplacement_tuteur/selectors`.

- [ ] **Step 1 : Créer `amd/src/selectors.js`**

```javascript
// This file is part of Moodle - http://moodle.org/

/**
 * Selecteurs utilises par le module de l'emplacement tuteur.
 *
 * @module     aiplacement_tuteur/selectors
 */
export default {
    ELEMENTS: {
        DRAWER: '#ai-tutor-drawer',
        DRAWER_BODY: '#ai-tutor-drawer .ai-tutor-drawer-body',
        PAGE: '#page',
        JUMPTO: '.ai-tutor-controls [data-region="jumpto"]',
        DRAWER_CLOSE: '#ai-tutor-drawer-close',
        QUESTION_INPUT: '#ai-tutor-question',
        OPEN_BUTTON: '#ai-tutor-open',
    },
    ACTIONS: {
        OPEN: '[data-action="tutor-open"]',
        ASK: '[data-action="tutor-ask"]',
        RETRY: '[data-action="tutor-retry"]',
        REGENERATE: '[data-action="tutor-regenerate"]',
        NEWQUESTION: '[data-action="tutor-newquestion"]',
        CANCEL: '[data-action="tutor-cancel"]',
        DECLINE: '.ai-policy-block [data-action="decline"]',
        ACCEPT: '.ai-policy-block [data-action="accept"]',
    }
};
```

- [ ] **Step 2 : Créer `amd/src/placement.js`**

```javascript
// This file is part of Moodle - http://moodle.org/

/**
 * Module pour afficher et piloter le tiroir de discussion du tuteur IA.
 *
 * @module     aiplacement_tuteur/placement
 */

import Templates from 'core/templates';
import Ajax from 'core/ajax';
import 'core/copy_to_clipboard';
import Notification from 'core/notification';
import Selectors from 'aiplacement_tuteur/selectors';
import Policy from 'core_ai/policy';
import AIHelper from 'core_ai/helper';
import DrawerEvents from 'core/drawer_events';
import {subscribe} from 'core/pubsub';
import * as MessageDrawerHelper from 'core_message/message_drawer_helper';
import * as FocusLock from 'core/local/aria/focuslock';
import {isSmall} from "core/pagehelpers";

const AITutor = class {

    /** @type {Integer} */
    userId;
    /** @type {Integer} */
    contextId;
    /** @type {String} Derniere question envoyee (pour "reposer la question"). */
    lastQuestion = '';
    /** @type {Boolean} Empeche une double soumission concurrente. */
    isAsking = false;
    /** @type {Boolean} */
    isDrawerFocusLocked = false;

    constructor(userId, contextId) {
        this.userId = userId;
        this.contextId = contextId;

        this.drawerElement = document.querySelector(Selectors.ELEMENTS.DRAWER);
        this.drawerBodyElement = document.querySelector(Selectors.ELEMENTS.DRAWER_BODY);
        this.pageElement = document.querySelector(Selectors.ELEMENTS.PAGE);
        this.jumpToElement = document.querySelector(Selectors.ELEMENTS.JUMPTO);
        this.openButtonElement = document.querySelector(Selectors.ELEMENTS.OPEN_BUTTON);
        this.drawerCloseElement = this.drawerElement.querySelector(Selectors.ELEMENTS.DRAWER_CLOSE);

        this.registerEventListeners();
    }

    registerEventListeners() {
        document.addEventListener('click', async(e) => {
            const openAction = e.target.closest(Selectors.ACTIONS.OPEN);
            if (openAction) {
                e.preventDefault();
                this.toggleDrawer();
                if (this.isDrawerOpen()) {
                    const isPolicyAccepted = await this.isPolicyAccepted();
                    if (!isPolicyAccepted) {
                        this.displayPolicy();
                        return;
                    }
                    this.displayAskForm();
                }
            }
        });

        document.addEventListener('keydown', e => {
            if (this.isDrawerOpen() && e.key === 'Escape') {
                this.closeDrawer();
            }
        });

        // Ferme le tiroir du tuteur si le tiroir de messagerie s'ouvre (evite deux tiroirs ouverts).
        subscribe(DrawerEvents.DRAWER_SHOWN, () => {
            if (this.isDrawerOpen()) {
                this.closeDrawer();
            }
        });

        this.jumpToElement.addEventListener('focus', () => {
            this.drawerCloseElement.focus();
        });
    }

    registerPolicyEventListeners() {
        const acceptAction = document.querySelector(Selectors.ACTIONS.ACCEPT);
        const declineAction = document.querySelector(Selectors.ACTIONS.DECLINE);
        if (acceptAction) {
            acceptAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.acceptPolicy().then(() => {
                    return this.displayAskForm();
                }).catch(Notification.exception);
            });
        }
        if (declineAction) {
            declineAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.closeDrawer();
            });
        }
    }

    registerAskEventListeners() {
        const askButton = document.querySelector(Selectors.ACTIONS.ASK);
        const questionInput = document.querySelector(Selectors.ELEMENTS.QUESTION_INPUT);
        if (!askButton || !questionInput) {
            return;
        }
        questionInput.addEventListener('input', () => {
            askButton.disabled = questionInput.value.trim() === '';
        });
        askButton.addEventListener('click', (e) => {
            e.preventDefault();
            this.askQuestion(questionInput.value.trim());
        });
    }

    registerErrorEventListeners() {
        const retryAction = document.querySelector(Selectors.ACTIONS.RETRY);
        if (retryAction) {
            retryAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.askQuestion(this.lastQuestion);
            });
        }
    }

    registerResponseEventListeners() {
        const regenerateAction = document.querySelector(Selectors.ACTIONS.REGENERATE);
        if (regenerateAction) {
            regenerateAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.askQuestion(this.lastQuestion);
            });
        }
        const newQuestionAction = document.querySelector(Selectors.ACTIONS.NEWQUESTION);
        if (newQuestionAction) {
            newQuestionAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.displayAskForm();
            });
        }
    }

    registerLoadingEventListeners() {
        const cancelAction = document.querySelector(Selectors.ACTIONS.CANCEL);
        if (cancelAction) {
            cancelAction.addEventListener('click', (e) => {
                e.preventDefault();
                this.isAsking = false;
                this.displayAskForm();
            });
        }
    }

    isDrawerOpen() {
        return this.drawerElement.classList.contains('show');
    }

    openDrawer() {
        MessageDrawerHelper.hide();
        this.drawerElement.classList.add('show');
        this.drawerElement.setAttribute('tabindex', '0');
        this.drawerBodyElement.setAttribute('aria-live', 'polite');
        this.jumpToElement.setAttribute('tabindex', 0);
        this.jumpToElement.focus();
        if (isSmall()) {
            FocusLock.trapFocus(this.drawerElement);
            this.drawerElement.setAttribute('aria-modal', 'true');
            this.drawerElement.setAttribute('role', 'dialog');
            this.isDrawerFocusLocked = true;
        }
    }

    closeDrawer() {
        if (this.isDrawerFocusLocked) {
            FocusLock.untrapFocus();
            this.drawerElement.removeAttribute('aria-modal');
            this.drawerElement.setAttribute('role', 'region');
            this.isDrawerFocusLocked = false;
        }
        this.drawerElement.classList.remove('show');
        this.drawerElement.setAttribute('tabindex', '-1');
        this.drawerBodyElement.removeAttribute('aria-live');
        this.jumpToElement.setAttribute('tabindex', -1);
        this.openButtonElement.focus();
    }

    toggleDrawer() {
        if (this.isDrawerOpen()) {
            this.closeDrawer();
        } else {
            this.openDrawer();
        }
    }

    async isPolicyAccepted() {
        return await Policy.getPolicyStatus(this.userId);
    }

    acceptPolicy() {
        return Policy.acceptPolicy();
    }

    displayPolicy() {
        Templates.render('core_ai/policyblock', {}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerPolicyEventListeners();
            return;
        }).catch(Notification.exception);
    }

    displayAskForm() {
        Templates.render('aiplacement_tuteur/ask', {}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerAskEventListeners();
            const questionInput = document.querySelector(Selectors.ELEMENTS.QUESTION_INPUT);
            if (questionInput) {
                questionInput.focus();
            }
            return;
        }).catch(Notification.exception);
    }

    displayLoading() {
        Templates.render('aiplacement_tuteur/loading', {}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerLoadingEventListeners();
            return;
        }).catch(Notification.exception);
    }

    /**
     * Envoie une question au tuteur et affiche le resultat.
     *
     * @param {String} question Le texte de la question (deja nettoye des espaces superflus).
     */
    async askQuestion(question) {
        // Garde anti double-soumission (Review Focus 3) : une seule requete en vol a la fois.
        if (this.isAsking || question === '') {
            return;
        }
        this.isAsking = true;
        this.lastQuestion = question;
        this.displayLoading();
        const request = {
            methodname: 'aiplacement_tuteur_generate_text',
            args: {
                contextid: this.contextId,
                prompttext: question,
            }
        };
        try {
            const responseObj = await Ajax.call([request])[0];
            this.isAsking = false;
            if (responseObj.error) {
                this.displayError();
                return;
            }
            const generatedContent = AIHelper.replaceLineBreaks(responseObj.generatedcontent);
            this.displayResponse(question, generatedContent);
        } catch (error) {
            // Echec reseau/exception (Review Focus 4) : jamais de chargement bloque indefiniment.
            this.isAsking = false;
            window.console.log(error);
            this.displayError();
        }
    }

    displayResponse(question, content) {
        Templates.render('aiplacement_tuteur/response', {question: question, content: content}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerResponseEventListeners();
            return;
        }).catch(Notification.exception);
    }

    displayError() {
        Templates.render('aiplacement_tuteur/error', {}).then((html) => {
            this.drawerBodyElement.innerHTML = html;
            this.registerErrorEventListeners();
            return;
        }).catch(Notification.exception);
    }
};

export default AITutor;
```

- [ ] **Step 3 : Compiler les modules AMD dans un conteneur Docker jetable**

```bash
mkdir -p plugin/aiplacement_tuteur/amd/build
docker run --rm -v "$(pwd)/plugin/aiplacement_tuteur:/plugin" node:22-bookworm-slim bash -lc '
  set -e
  apt-get update -qq && apt-get install -y -qq git >/dev/null
  git clone --branch MOODLE_405_STABLE --depth 1 https://github.com/moodle/moodle.git /moodle
  mkdir -p /moodle/ai/placement/tuteur
  cp -r /plugin/. /moodle/ai/placement/tuteur/
  cd /moodle
  npm ci --no-audit --no-fund
  npx grunt amd --root=ai/placement/tuteur
  cp -r /moodle/ai/placement/tuteur/amd/build/. /plugin/amd/build/
'
ls -la plugin/aiplacement_tuteur/amd/build/
```
Expected : la console affiche `Setting root to .../ai/placement/tuteur` puis la tâche `amd`
Grunt se termine sans erreur ; `plugin/aiplacement_tuteur/amd/build/` contient
`placement.min.js`, `placement.min.js.map`, `selectors.min.js`, `selectors.min.js.map`, chacun
non vide. **Si `npm ci` échoue** (dépôt npm inaccessible depuis le conteneur), relancer la
commande — c'est un problème réseau transitoire, pas une erreur de code.

- [ ] **Step 4 : Vérifier que les fichiers compilés référencent bien nos modules**

```bash
grep -q 'aiplacement_tuteur/placement' plugin/aiplacement_tuteur/amd/build/placement.min.js && echo "OK: placement.min.js se declare bien" || echo "ECHEC"
grep -q 'aiplacement_tuteur/selectors' plugin/aiplacement_tuteur/amd/build/selectors.min.js && echo "OK: selectors.min.js se declare bien" || echo "ECHEC"
grep -c 'aiplacement_tuteur_generate_text' plugin/aiplacement_tuteur/amd/build/placement.min.js
```
Expected: les deux `OK`, et le dernier `grep -c` renvoie `1` (le nom de la méthode du service web
apparaît bien dans le bundle compilé).

- [ ] **Step 5 : Déployer et reconstruire, purger les caches Moodle**

```bash
rm -rf /tmp/deploy && mkdir -p /tmp/deploy
cp -r plugin/aiplacement_tuteur /tmp/deploy/
find /tmp/deploy -type f -name '*.php' -o -type f -name '*.js' -o -type f -name '*.mustache' -o -type f -name '*.css' | xargs -I{} sh -c 'tr -d "\r" < "{}" > "{}.tmp" && mv "{}.tmp" "{}"'
scp -i "$KEY" -o BatchMode=yes -r /tmp/deploy/aiplacement_tuteur ubuntu@$HOST:ai-moodle-security/plugin/
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose up -d --build moodle 2>&1 | tail -10 && docker compose exec -T moodle php admin/cli/purge_caches.php'
```
Note : les `.map` sont du JSON (pas de `\r` problématique habituellement, mais le nettoyage
`tr -d '\r'` ne les corrompt pas) ; les `.min.js` eux-mêmes ne doivent **pas** être altérés par un
retrait de `\r` s'ils n'en contiennent pas — la commande ci-dessus ne touche que les extensions
listées (php/js/mustache/css), les `.map` sont copiés tels quels par le `scp -r` du dossier parent.

- [ ] **Step 6 : Vérifier que le fichier compilé est bien servi par Moodle (sans exécuter le JS)**

```bash
cat > /tmp/check_amd_served.sh <<'SHEOF'
H=lms.pkfokam; R="--resolve $H:443:127.0.0.1"
REV=$(docker compose exec -T moodle php -r "define('CLI_SCRIPT', true); require '/var/www/html/config.php'; echo \$CFG->jsrev;")
curl -sk $R "https://$H/lib/javascript.php/$REV/ai/placement/tuteur/amd/build/placement.min.js" -o /tmp/served.js -w "HTTP %{http_code}\n"
grep -c "aiplacement_tuteur/placement" /tmp/served.js
SHEOF
tr -d '\r' < /tmp/check_amd_served.sh | ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cat > ~/ai-moodle-security/check_amd_served.sh'
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && bash check_amd_served.sh'
```
Expected: `HTTP 200` et une valeur `>= 1` pour le `grep -c`. **Limite assumée** : ceci prouve que
le fichier est servi correctement, pas qu'il s'exécute sans erreur dans un navigateur réel —
Playwright n'est pas disponible dans cet environnement. Review Focus 3 et 4 (double soumission,
gestion d'erreur réseau) ne peuvent être vérifiés que manuellement par l'utilisateur, dans un
vrai navigateur, en ouvrant le tiroir et en testant ces deux scénarios.

- [ ] **Step 7 : Commit**

```bash
git add plugin/aiplacement_tuteur/amd
git commit -m "feat(aiplacement_tuteur): module AMD du tiroir de discussion (source + compile)

amd/src/placement.js (classe AITutor), amd/src/selectors.js, calques sur
aiplacement_courseassist mais sans multi-tours ni recuperation automatique du texte de la page :
formulaire de question libre, garde anti double-soumission (isAsking), gestion d'erreur
reseau/exception (catch -> displayError, jamais de chargement bloque). Reutilise core_ai/policy
et core_ai/helper sans modification.

amd/build/*.min.js(+.map) compiles une fois via un conteneur node:22-bookworm-slim jetable
(Moodle exige Node >=22.11.0 <23 ; l'image Docker du projet n'a pas de Node) et commites, comme
le fait Moodle pour ses propres plugins -- aucune dependance Node dans l'image de production.

Verifie sur l'EC2 : fichier compile servi par /lib/javascript.php avec HTTP 200, contenu
coherent. Non verifie : execution reelle dans un navigateur (hors de portee de cet
environnement) -- a confirmer manuellement avant la demo."
```

---

## Task 6 : Script de validation de bout en bout + vérification finale

**Files:**
- Create: `moodle/cli/demo_tuteur.php`

**Interfaces:**
- Consumes: l'intégralité de la pile (Tasks 1-5).
- Produces: script de validation réutilisable, sur le modèle de `moodle/cli/demo_indirect.php`
  déjà présent dans le dépôt.

- [ ] **Step 1 : Créer `moodle/cli/demo_tuteur.php`**

```php
<?php
// moodle/cli/demo_tuteur.php -- verification de bout en bout du placement aiplacement_tuteur :
// appelle le service web aiplacement_tuteur_generate_text comme le ferait le navigateur, en tant
// qu'etudiant1, dans le contexte du cours de demonstration. Sur le modele de demo_indirect.php.
// Usage (dans le conteneur moodle) :
//   docker compose exec -T moodle sh -c "cat > /tmp/demo_tuteur.php" < moodle/cli/demo_tuteur.php
//   docker compose exec -T moodle php /tmp/demo_tuteur.php
//   docker compose exec -T moodle php /tmp/demo_tuteur.php "Ta question"
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
```

- [ ] **Step 2 : Vérifier la syntaxe PHP localement**

```bash
/c/tools/php84/php -l moodle/cli/demo_tuteur.php
```
Expected: `No syntax errors detected`.

- [ ] **Step 3 : Déployer, reconstruire entièrement l'image, et exécuter le script**

```bash
tr -d '\r' < moodle/cli/demo_tuteur.php > /tmp/demo_tuteur_lf.php
scp -i "$KEY" -o BatchMode=yes /tmp/demo_tuteur_lf.php ubuntu@$HOST:ai-moodle-security/moodle/cli/demo_tuteur.php
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose up -d --build moodle 2>&1 | tail -10'
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && docker compose exec -T moodle sh -c "cat > /tmp/demo_tuteur.php" < moodle/cli/demo_tuteur.php && docker compose exec -T moodle php /tmp/demo_tuteur.php'
```
Expected: `RESULTAT : OK`, avec une réponse non vide affichée.

- [ ] **Step 4 : Re-confirmer la visibilité (régression, reprend Task 3 Step 9 sur l'image reconstruite)**

```bash
ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && cat /etc/cron.d/aimoodle-cron >/dev/null'  # sanity: toujours connecte
awk -F'\t' '$1=="etudiant1"{print $2}' secrets/demo_users.txt | tr -d '\r' | ssh -i "$KEY" -o BatchMode=yes ubuntu@$HOST 'cd ~/ai-moodle-security && bash check_tuteur_visibility.sh'
```
Expected: identique à Task 3 Step 9 (bouton présent sur le cours, absent ailleurs) — confirme
qu'aucune régression n'a été introduite par les tâches suivantes.

- [ ] **Step 5 : Commit**

```bash
git add moodle/cli/demo_tuteur.php
git commit -m "feat(aiplacement_tuteur): script de validation de bout en bout demo_tuteur.php

Sur le modele de demo_indirect.php : appelle le service web comme le navigateur, en tant
qu'etudiant1, dans le cours PYTHON101. Verifie sur l'EC2 apres reconstruction complete de
l'image : RESULTAT OK, reponse non vide. Regression de visibilite (Task 3) reconfirmee."
```

- [ ] **Step 6 : Rappel pour l'utilisateur (hors du champ de ce plan, à faire manuellement)**

Après ce task, le plugin est fonctionnellement complet et vérifié côté serveur, mais **jamais
testé dans un vrai navigateur** dans cette session (pas d'outil de navigation automatisée
disponible ici). Avant d'intégrer ce plugin à une démonstration :
1. Se connecter en `etudiant1`, ouvrir la page du cours PYTHON101, cliquer sur « Demander au
   tuteur », accepter la politique d'usage IA si demandée, poser une question, vérifier
   l'affichage de la réponse.
2. Tester manuellement les Review Focus 3 et 4 : cliquer deux fois rapidement sur « Envoyer »
   (ne doit pas déclencher deux requêtes), et couper temporairement l'accès réseau du navigateur
   pendant une génération (doit afficher le message d'erreur, pas un chargement bloqué).
3. Mettre à jour `docs/RUNBOOK-SOUTENANCE.md` si ce plugin doit figurer dans le déroulé de la
   soutenance (hors du champ de ce plan).

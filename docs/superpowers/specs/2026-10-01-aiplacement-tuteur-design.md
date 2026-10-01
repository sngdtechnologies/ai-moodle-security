# Spec : `aiplacement_tuteur` — emplacement IA dédié pour le tuteur

**Date** : 2026-10-01
**Statut** : design approuvé par l'utilisateur (2026-10-01) ; spec en attente de relecture avant `writing-plans`.

## 1. Objectif

Construire un emplacement IA Moodle (`aiplacement_tuteur`) qui affiche un bouton « Demander au
tuteur » et un tiroir de discussion sur **toutes les pages d'un cours**, permettant à un étudiant de
poser une question au tuteur IA sans passer par l'éditeur de texte (TinyMCE). C'est la première
interface *dédiée* au tuteur : jusqu'ici, le seul chemin UI disponible était `aiplacement_editor`,
conçu pour générer du contenu dans un champ de texte, pas pour une conversation.

Contexte thèse : ce plugin est un nouveau *placement* du sous-système `core_ai` de Moodle 4.5,
au même titre que `aiplacement_editor` (déjà utilisé pour le red teaming, ch. 5) et
`aiplacement_courseassist` (non utilisé dans ce projet). Il s'ajoute au pipeline existant sans le
modifier : [[project-prototype-scope]].

## 2. Décisions verrouillées (approuvées par l'utilisateur, 2026-10-01)

1. **Question → réponse simple.** Aucun canal `[DONNEES]` : ce plugin ne fait passer aucun contenu
   tiers (page, activité, devoir) vers le modèle. C'est une limite **délibérée**, pas un oubli —
   voir section 8.
2. **Pas d'historique.** Chaque question est indépendante ; rien n'est conservé entre deux
   questions dans la même session de navigateur. Nuance : le bouton « reposer la question »
   (section 10) garde le dernier texte saisi en mémoire côté JavaScript pour le renvoyer tel quel —
   une commodité d'interface, pas un historique conversationnel. Aucun contexte accumulé n'est
   jamais envoyé au modèle ; chaque appel à `client::ask()` reste un échange isolé.
3. **Visible partout dans le cours** (page de cours + toutes ses activités), pas seulement sur les
   pages d'activité comme `courseassist`.

## 3. Références et vérification

Deux plugins Moodle officiels servent de calque, lus intégralement et **vérifiés fichier par
fichier contre l'installation réelle sur l'EC2** (27 fichiers comparés après normalisation des fins
de ligne : 0 différence de contenu avec la branche `MOODLE_405_STABLE` sur GitHub, 2026-10-01) :

- **`ai/placement/editor/`** — pour le service web (`db/services.php`,
  `classes/external/generate_text.php`) et la déclaration de capacité (`db/access.php`). **Correction
  importante** : ce plugin n'a **ni `amd/src/` ni `templates/`** — son interface vit entièrement
  dans un plugin séparé (`lib/editor/tiny/plugins/aiplacement/`, le plugin TinyMCE), donc il n'offre
  aucun calque pour une fenêtre de discussion autonome.
- **`ai/placement/courseassist/`** — pour tout le reste : `db/hooks.php`,
  `classes/hook_callbacks.php`, `classes/output/assist_ui.php`, `amd/src/`, `templates/`,
  `styles.css`. C'est la seule référence du cœur Moodle qui injecte sa propre interface sur les
  pages de cours via des *hooks*, sans dépendre de l'éditeur de texte.

Fichier de notre projet déjà lu et pertinent : `plugin/aiprovider_ollamasecure/classes/process_generate_text.php`
(le fournisseur), qui confirme que `\core_ai\aiactions\generate_text` ne transporte qu'un seul champ
texte (`prompttext`) — voir section 8.

## 4. Arborescence et responsabilité de chaque fichier

Plugin : `aiplacement_tuteur`, dossier `ai/placement/tuteur/`.

| Fichier | Rôle | Calqué sur |
|---|---|---|
| `version.php` | `$plugin->component='aiplacement_tuteur'; $plugin->version=2026100100; $plugin->requires=2024100100; $plugin->maturity=MATURITY_STABLE;` | `editor` |
| `classes/placement.php` | `class placement extends \core_ai\placement { public function get_action_list(): array { return [\core_ai\aiactions\generate_text::class]; } }` | `editor`/`courseassist` |
| `classes/utils.php` | `is_tutor_available(\context $context): bool` — section 5 | `courseassist` (version complète, avec vérification du fournisseur) |
| `classes/output/tutor_ui.php` | `load_tutor_ui()`, `load_tutor_button()`, `preflight_checks()` — section 6 | `courseassist/classes/output/assist_ui.php` |
| `classes/hook_callbacks.php` | Relaie les deux hooks vers `tutor_ui` | `courseassist` |
| `classes/external/generate_text.php` | Reçoit l'appel du service web, appelle le `manager` — section 7 | `editor` + `courseassist` (combinés, section 7) |
| `classes/privacy/provider.php` | `implements \core_privacy\local\metadata\null_provider`, `get_reason()` retourne `'privacy:metadata'` | `editor` (reproduction exacte) |
| `db/access.php` | Une capacité, `aiplacement/tuteur:use` — section 5 | `courseassist` |
| `db/hooks.php` | `before_footer_html_generation` (prio 0) → `load_tutor_ui` ; `after_http_headers` (prio 0) → `load_tutor_button` | `courseassist` (reproduction exacte) |
| `db/services.php` | `aiplacement_tuteur_generate_text` → `classes/external/generate_text.php`, `ajax=>true`, `services=>[MOODLE_OFFICIAL_MOBILE_SERVICE]` | `editor`/`courseassist` |
| `lang/en/aiplacement_tuteur.php` | Chaînes — table section 9 | — |
| `lang/fr/aiplacement_tuteur.php` | Traduction des mêmes chaînes | — (nouveau : les deux références n'ont qu'un `lang/en/`) |
| `amd/src/placement.js` | Ouvre/ferme le tiroir, politique d'usage IA, envoi de la question, affichage — section 10 | `courseassist/amd/src/placement.js`, simplifié (pas de multi-tours, pas de `getTextContent()`) |
| `amd/src/selectors.js` | Sélecteurs CSS du JS ci-dessus | `courseassist` |
| `templates/button.mustache` | Bouton déclencheur du tiroir | `courseassist/templates/summarise_button.mustache` |
| `templates/drawer.mustache` | Conteneur du tiroir (coquille vide au premier chargement, le JS y injecte le formulaire) | `courseassist/templates/drawer.mustache` |
| `templates/ask.mustache` | **Nouveau** : zone de texte + bouton envoyer. N'existe dans aucune des deux références (elles n'ont pas de saisie libre). | — |
| `templates/loading.mustache` | Indicateur de génération en cours + bouton annuler | `courseassist` |
| `templates/response.mustache` | Réponse affichée + boutons « copier », « reposer la même question », « poser une autre question » | `courseassist`, étendu |
| `templates/error.mustache` | Message d'erreur + bouton réessayer | `courseassist` |
| `styles.css` | Mise en forme du tiroir (repris quasiment tel quel, classes renommées `ai-tutor-*`) | `courseassist/styles.css` |

**Confirmation explicite** : aucun fichier de ce plugin ne modifie `client.php`,
`process_generate_text.php`, `provider.php` ni aucun fichier de `aiprovider_ollamasecure`. Le chemin
`manager → process_generate_text → client::ask() (N1-N4)` est réutilisé sans changement — zéro
nouvelle surface à ré-évaluer sur le plan sécurité.

## 5. Permissions et disponibilité

`db/access.php` :
```php
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
Une seule capacité (contre deux pour `editor`, qui gère aussi la génération d'images — hors
périmètre ici). Les quatre rôles autorisés dès l'installation : le point 11 de la consigne initiale
(« vérifier que l'étudiant y a accès ») est réglé par ce fichier, pas après coup.

`classes/utils.php::is_tutor_available(\context $context): bool` — calqué sur la version
**complète** de `courseassist` (pas la version plus simple d'`editor`, qui ne vérifie pas la
disponibilité du fournisseur) :
```php
public static function is_tutor_available(\context $context): bool {
    [$plugintype, $pluginname] = explode('_', \core_component::normalize_componentname('aiplacement_tuteur'), 2);
    $manager = \core_plugin_manager::resolve_plugininfo_class($plugintype);
    if (!$manager::is_plugin_enabled($pluginname)) {
        return false;
    }
    $providers = \core_ai\manager::get_providers_for_actions([\core_ai\aiactions\generate_text::class], true);
    if (!has_capability('aiplacement/tuteur:use', $context)
        || !\core_ai\manager::is_action_available(\core_ai\aiactions\generate_text::class)
        || !\core_ai\manager::is_action_enabled('aiplacement_tuteur', \core_ai\aiactions\generate_text::class)
        || empty($providers[\core_ai\aiactions\generate_text::class])
    ) {
        return false;
    }
    return true;
}
```

## 6. Affichage : hooks et règle de visibilité

`db/hooks.php` — reproduction exacte du patron `courseassist` :
```php
$callbacks = [
    ['hook' => \core\hook\output\before_footer_html_generation::class,
     'callback' => \aiplacement_tuteur\hook_callbacks::class . '::before_footer_html_generation', 'priority' => 0],
    ['hook' => \core\hook\output\after_http_headers::class,
     'callback' => \aiplacement_tuteur\hook_callbacks::class . '::after_http_headers', 'priority' => 0],
];
```

`classes/output/tutor_ui.php::preflight_checks(): bool` — **c'est ici que la décision « visible
partout dans le cours » (section 2, point 3) se concrétise**, par un élargissement délibéré et
précis de la règle de `courseassist` (qui ne montre son interface que sur `CONTEXT_MODULE`) :
```php
private static function preflight_checks(): bool {
    global $PAGE;
    if (during_initial_install()) {
        return false;
    }
    if (!get_config('aiplacement_tuteur', 'version')) {
        return false;
    }
    if (in_array($PAGE->pagelayout, ['maintenance', 'print', 'redirect', 'embedded', 'login'])) {
        // 'login' ajouté par rapport à courseassist : pas de bouton avant authentification.
        return false;
    }
    // Contrairement a courseassist (CONTEXT_MODULE seul), on autorise AUSSI CONTEXT_COURSE :
    // le tuteur doit etre visible sur la page de cours elle-meme, pas seulement dans ses activites.
    if (!in_array($PAGE->context->contextlevel, [CONTEXT_COURSE, CONTEXT_MODULE], true)) {
        return false;
    }
    // Exclut la page d'accueil du site (cours id=1), qui n'est pas un vrai cours.
    if (($PAGE->course->id ?? SITEID) == SITEID) {
        return false;
    }
    return utils::is_tutor_available($PAGE->context);
}
```
`load_tutor_ui()` rend `aiplacement_tuteur/drawer` avec `{userid, contextid}` et l'ajoute via
`$hook->add_html()`. `load_tutor_button()` rend `aiplacement_tuteur/button` (aucun paramètre) de la
même façon. Les deux appellent `preflight_checks()` en premier et retournent sans rien faire si faux
— reproduction exacte du patron `courseassist`.

## 7. Service web

`db/services.php` :
```php
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

`classes/external/generate_text.php::execute(int $contextid, string $prompttext): array` — combine
les deux patrons de référence plutôt que d'en copier un seul :
1. `self::validate_parameters(...)` puis `$context = \core\context::instance_by_id($contextid);
   self::validate_context($context);` (commun aux deux références).
2. **`require_capability('aiplacement/tuteur:use', $context);`** — repris de `courseassist`
   (vérification qui échoue fort, par exception) plutôt que de la vérification silencieuse
   d'`editor`. Choix déliberé : cohérent avec la posture *fail-closed* déjà appliquée partout
   ailleurs dans ce projet (ex. timeouts du client, WAF).
3. **`if (!utils::is_tutor_available($context)) { throw new \moodle_exception('notutor', 'aiplacement_tuteur'); }`**
   — repris d'`editor`/`courseassist` (vérification de disponibilité *fonctionnelle*, distincte de
   la capacité : plugin désactivé, action désactivée, aucun fournisseur).
4. Construit `new \core_ai\aiactions\generate_text(contextid: $contextid, userid: $USER->id,
   prompttext: $prompttext);`, l'envoie à `\core\di::get(\core_ai\manager::class)->process_action($action)`.
5. Retourne exactement les 7 champs des deux références : `success, timecreated, prompttext,
   generatedcontent, finishreason, errorcode, error`.

**Point de vigilance explicite, hérité d'un bug déjà corrigé dans ce projet** (section 10,
`fix(plugin): N4 sans balises`, phase de préparation soutenance) : `generatedcontent` doit être
déclaré en `PARAM_TEXT` dans `execute_returns()` (comme `editor`, **pas** `PARAM_RAW` comme
`courseassist`), et **ne doit recevoir AUCUN traitement HTML supplémentaire** côté externe (ni
`format_text()`, ni concaténation de balises). Le contenu arrive déjà échappé par N4
(`client::sanitize_output()`, `htmlspecialchars(..., ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8')`) :
toute balise ajoutée après coup (même `<br />` via `format_text(FORMAT_PLAIN)`) fait échouer la
validation du type `PARAM_TEXT` côté Moodle dès que la réponse dépasse un paragraphe — c'est
exactement le défaut qui bloquait `aiplacement_editor` avant sa correction. Retourner
`$response->get_response_data()['generatedcontent'] ?? ''` **tel quel**, sans y toucher.

## 8. Limite assumée : pas de canal `[DONNEES]`

`\core_ai\aiactions\generate_text` (`ai/classes/aiactions/generate_text.php`, cœur Moodle) ne
déclare que trois propriétés dans son constructeur : `contextid`, `userid`, `prompttext`.
`get_configuration(string $name)` (classe de base, `ai/classes/aiactions/base.php`) fait
`return $this->$name;` — un accès direct à une propriété PHP déclarée, **sans mécanisme d'extension**.
Il est donc **structurellement impossible** de faire porter un second champ (« contenu à analyser »)
par cette action sans soit (a) l'encoder dans l'unique chaîne `prompttext` selon une convention à
inventer, re-décodée dans `process_generate_text.php`, soit (b) modifier le cœur Moodle (exclu).

Confirmé dans notre propre fournisseur (`plugin/aiprovider_ollamasecure/classes/process_generate_text.php`,
ligne 11-13) : l'appel actuel est `$client->ask($this->action->get_configuration('prompttext'),
$this->action->get_configuration('userid'));` — **sans troisième argument**. Le canal `[DONNEES]` de
`client.php` (séparation N1) existe et fonctionne, mais n'a jamais été exercé par une interface
Moodle réelle, dans aucun emplacement, uniquement par des scripts en ligne de commande
(`eval/redteam.php`, `moodle/cli/demo_indirect.php`).

**Décision (section 2, point 1) : ce plugin ne comble pas cette limite.** `aiplacement_tuteur`
envoie uniquement la question de l'étudiant comme `prompttext`, sans second champ. La limite reste
documentée comme perspective, comme elle l'était déjà. Un futur plugin qui voudrait l'adresser devrait
encoder instruction+contenu dans la chaîne unique `prompttext` et modifier `process_generate_text.php`
pour les re-séparer — hors périmètre ici.

## 9. Chaînes de langue

`lang/en/aiplacement_tuteur.php` et `lang/fr/aiplacement_tuteur.php` (ce dernier n'existe dans
aucune des deux références ; ajouté car le reste de l'interface de démonstration est en français) :

| Identifiant | Anglais | Français |
|---|---|---|
| `pluginname` | Tutor placement | Emplacement tuteur |
| `tuteur:use` (capacité) | Use the AI tutor | Utiliser le tuteur IA |
| `privacy:metadata` | The Tutor placement plugin does not store any personal data. | Le plugin d'emplacement tuteur ne stocke aucune donnée personnelle. |
| `notutor` | The AI tutor is not available in this context. | Le tuteur IA n'est pas disponible dans ce contexte. |
| `tutorbuttonlabel` | Ask the tutor | Demander au tuteur |
| `tutortooltip` | Ask the AI tutor a question about this course | Poser une question au tuteur IA sur ce cours |
| `askplaceholder` | Type your question… | Tapez votre question… |
| `send` | Send | Envoyer |
| `generating` | Generating your answer | Génération de la réponse en cours |
| `generatefailtitle` | Something went wrong | Une erreur est survenue |
| `tryagain` | Try again | Réessayer |
| `newquestion` | Ask another question | Poser une autre question |
| `regenerate` | Ask again | Reposer la question |
| `aidrawerlabel` | AI tutor drawer | Panneau du tuteur IA |

Chaînes **réutilisées depuis `core`/`core_ai`, non redéclarées** (simplification par rapport à
`courseassist`, qui redéclare localement certaines de ces chaînes déjà disponibles) :
`closedrawer` (core), `cancel` (core), `copy` (core), `contentwatermark` (core_ai).

## 10. JavaScript et gabarits — déroulé

`amd/src/placement.js` (classe `AITutor`, calquée sur `AICourseAssist` mais simplifiée — pas de
`getTextContent()`, pas de régénération automatique, pas de piste `hasGeneratedContent`/historique) :

1. Construction : `new AITutor(userId, contextId)`, enregistre les écouteurs (clic sur le bouton,
   `Echap` pour fermer, fermeture si le tiroir de messagerie s'ouvre — mêmes protections que
   `courseassist`, réutilisées telles quelles via `core/drawer_events` et `core_message/message_drawer_helper`).
2. Clic sur le bouton (`templates/button.mustache`) → ouvre le tiroir → vérifie la politique d'usage
   IA via `core_ai/policy` (`Policy.getPolicyStatus(userId)`, réutilisé sans modification) → si non
   acceptée, affiche `core_ai/policyblock` (réutilisé) ; sinon, affiche `templates/ask.mustache`
   (zone de texte + bouton Envoyer, vide).
3. Soumission du formulaire : appelle `Ajax.call([{methodname: 'aiplacement_tuteur_generate_text',
   args: {contextid, prompttext: <valeur de la zone de texte>}}])`, affiche `loading.mustache`
   pendant l'attente.
4. Réponse : si `responseObj.error`, affiche `error.mustache` ; sinon, convertit les sauts de ligne
   via `AIHelper.replaceLineBreaks()` (réutilisé, `core_ai/helper`) et affiche `response.mustache`
   (question posée + réponse + boutons copier / reposer la question / poser une autre question).
5. « Poser une autre question » réaffiche `ask.mustache` vide (pas d'historique, conforme section 2).
   « Reposer la question » renvoie exactement le même `prompttext` à l'étape 3.

`templates/ask.mustache` (nouveau, aucun équivalent dans les deux références) : une balise
`<textarea>` avec l'attribut `placeholder` lié à `askplaceholder`, un bouton `data-action="tutor-ask"`
lié à `send`, désactivé tant que la zone est vide (même mécanique que l'éditeur TinyMCE, qui
désactive déjà son bouton de génération sur une zone vide).

## 11. Installation et activation (reprend les points 9-11 de la consigne initiale)

1. **Installation** : `admin/index.php` (page de notifications admin) détecte le nouveau composant
   et propose l'installation — comme tout plugin Moodle neuf. Nécessite d'abord de reconstruire
   l'image `moodle` (`docker compose up -d --build moodle`) puisque le code est copié dans l'image
   au build, pas monté en volume.
2. **Activation de l'emplacement** : *Administration du site → Intelligence artificielle →
   Emplacements IA* — `aiplacement_tuteur` doit apparaître dans la liste (détecté automatiquement
   via `classes/placement.php`), à activer.
3. **Vérification des droits** : *Administration du site → Utilisateurs → Permissions → Définir les
   rôles*, confirmer que le rôle Étudiant a `aiplacement/tuteur:use` (déjà accordé par défaut via
   `db/access.php`, section 5 — cette étape est une vérification, pas une configuration à faire).
4. **Compilation JS** : après toute modification d'`amd/src/*.js`, exécuter `grunt amd` (ou
   équivalent Moodle) puis purger le cache (*Administration du site → Développement → Purger les
   caches*) pour voir les changements.

## 12. Validation (pas de suite PHPUnit)

Cohérent avec le reste du projet (`moodle/cli/demo_indirect.php`, tests red team en ligne de
commande plutôt que PHPUnit) : script de bout en bout `moodle/cli/demo_tuteur.php` qui simule une
requête HTTP complète avec `etudiant1` (connexion, appel du service web
`aiplacement_tuteur_generate_text` à travers le WAF, vérification de `success=true` et d'une réponse
non vide), sur le modèle exact de `moodle/cli/demo_indirect.php` déjà écrit dans ce projet. Pas de
suite PHPUnit dans ce plugin (`tests/` absent de notre liste de fichiers, contrairement aux deux
références) — à ajouter plus tard si le projet évolue vers une couverture automatisée plus large.

## 13. Risques et limites (à assumer explicitement si questionné en soutenance)

- **Pas de canal `[DONNEES]`** (section 8) — limite documentée, pas un oubli.
- **Pas d'historique** — chaque question repart de zéro ; cohérent avec `client::ask()` qui est
  sans état, mais moins naturel qu'un vrai fil de discussion.
- **`PARAM_TEXT` strict** (section 7) — toute évolution future de `sanitize_output()` qui
  réintroduirait des balises HTML (même `<br />`) ferait échouer le service web pour toute réponse
  de plus d'un paragraphe. À garder en tête si `client.php` est retouché après ce plugin.
- **Méthode de référence** — le design a d'abord été construit à partir de la branche GitHub
  `MOODLE_405_STABLE` (l'EC2 étant injoignable à ce moment), puis intégralement vérifié contre
  l'installation réelle une fois celle-ci de nouveau accessible (section 3, 0 différence sur 27
  fichiers). Aucun risque résiduel identifié, consigné ici par traçabilité méthodologique.

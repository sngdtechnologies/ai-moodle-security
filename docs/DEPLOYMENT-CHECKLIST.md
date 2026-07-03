# Checklist de déploiement sécurisé

Liste de vérification à parcourir **avant la mise en production** du prototype dans une institution
éducative. Destinée à un déploiement d'IA locale souveraine (mémoire, §synthèse). Chaque point
renvoie à une commande de vérification ou au fichier concerné.

## 1. Secrets et accès

- [ ] Les trois secrets (`secrets/db_password.txt`, `moodle_admin_pass.txt`, `ollama_token.txt`)
      contiennent des valeurs **fortes et uniques** (jamais les `.example`).
- [ ] Les `secrets/*.txt` **ne sont pas** versionnés : `git status --porcelain secrets/ | grep -v example`
      ne renvoie rien.
- [ ] Le jeton `ollama_token` a été **régénéré** pour ce déploiement (`openssl rand -hex 32`).
- [ ] Le mot de passe admin Moodle a été changé après la première connexion.
- [ ] L'accès SSH à l'hôte est restreint (clés uniquement, pare-feu sur le port 22).

## 2. Confidentialité — non-fuite des données (par conception)

- [ ] Moodle et Ollama n'ont **aucune route sortante** :
      `docker compose exec ollama sh -c 'getent hosts github.com >/dev/null && echo EGRESS || echo NO_EGRESS'`
      → `NO_EGRESS` (idem pour `moodle`).
- [ ] Les réseaux `dmz`, `backend`, `ai`, `iot`, `iot_moodle` sont bien `internal: true`
      (`docker compose config`) ; seul `edge` (proxy) a accès à Internet.
- [ ] Ollama n'est **jamais** joignable directement : seul `ollama-gate` (jeton Bearer) le relaie ;
      sans jeton → HTTP 401.
- [ ] Aucun fournisseur d'IA **cloud** n'est activé dans Moodle (seul `aiprovider_ollamasecure`).

## 3. Durcissement des conteneurs (Tableau 8)

- [ ] Toutes les images sont **épinglées par digest** (`@sha256:…`) — pas de tag flottant `latest`.
- [ ] Chaque service applicatif est **non-root**, `read_only` (+ tmpfs), `cap_drop: ALL`,
      `no-new-privileges` (`docker compose config`).
- [ ] Le conteneur Ollama applique le profil **seccomp** (`ollama/seccomp-ollama.json`).
- [ ] Des **limites CPU/mémoire** sont posées (au minimum sur `ollama`) pour éviter l'OOM de l'hôte.
- [ ] Le modèle `tuteur-secure` est épinglé **par blob SHA-256** (`ollama show tuteur-secure --modelfile`
      → `FROM …/blobs/sha256-…`).

## 4. Point d'entrée — TLS et WAF

- [ ] **TLS** : en production, `proxy/Caddyfile` utilise un certificat de la **PKI de l'établissement**
      (et non `tls internal`), déjà approuvé par les postes → aucune distribution de CA.
- [ ] Le **WAF Coraza** est en mode **bloquant** (`SecRuleEngine On`) : une sonde SQLi/XSS renvoie
      `403`, le login légitime `200`.
- [ ] Le WAF a été **calibré** contre les faux positifs sur le trafic Moodle réel (login, dépôts,
      forums) — vérifier les logs du proxy après une session de test.
- [ ] `wwwroot` (`.env` + `moodle/config.php`) pointe le **FQDN réel** ; `$CFG->sslproxy = 1`.

## 5. Supervision en exécution (Falco)

- [ ] Falco est **actif** sur l'hôte (`systemctl is-active falco-modern-bpf`) avec les règles du
      projet (`/etc/falco/falco_rules.local.yaml`).
- [ ] Un shell ouvert dans le conteneur Ollama déclenche l'alerte **CRITICAL** « Shell ouvert ».
- [ ] L'allowlist de processus (`ollama_llama_se`) est **ajustée à la version d'Ollama déployée**
      (elle change à chaque montée de version).
- [ ] Les alertes Falco sont **acheminées** vers un collecteur/journal supervisé (pas seulement
      le journal local).

## 6. Protection des données (loi n° 2024/017 / RGPD)

- [ ] Le plugin ne stocke **aucune donnée personnelle** propre (déclare `null_provider` via l'API
      Privacy de Moodle).
- [ ] Les journaux de sécurité sont **minimisés** : catégorie d'événement + horodatage + identifiant
      **pseudonymisé** (haché) — **jamais** le prompt brut ni la réponse.
- [ ] Une **durée de rétention** des journaux est définie et appliquée par l'établissement.
- [ ] L'accès aux journaux est **restreint** aux administrateurs habilités.
- [ ] Une base légale et une information des apprenants sont en place pour le traitement IA.

## 7. Médiation IoT (si activée)

- [ ] Le courtier `mosquitto` est isolé (réseau `iot`) et **invisible** d'Ollama.
- [ ] Un capteur (segment `iot`) ne peut atteindre **ni Ollama ni Moodle** (segments séparés,
      pont limité au courtier) ; seuls des **indicateurs numériques bornés** sont dérivés — jamais
      de texte libre de capteur.
- [ ] En production : MQTT **authentifié/TLS** (le prototype utilise `allow_anonymous` sur réseau
      isolé — à durcir).

## 8. Disponibilité et sauvegardes

- [ ] **Sauvegardes régulières et testées** du volume base de données (`db_data`) et du volume des
      modèles (`ollama_models`).
- [ ] Dimensionnement vérifié : sur CPU sans GPU, la latence dépend fortement de la RAM/cœurs ;
      `OLLAMA_NUM_PARALLEL` et `OLLAMA_KEEP_ALIVE` réglés selon la mesure sous charge.
- [ ] Point de défaillance unique (instance Ollama) documenté ; pour une cohorte importante,
      envisager la **réplication d'Ollama** derrière la passerelle (perspective).
- [ ] Les images sont reconstruites/re-vérifiées lors des mises à jour de sécurité (digests mis à
      jour de façon contrôlée).

## 9. Validation finale

- [ ] Le tuteur répond de bout en bout dans Moodle (chemin nominal).
- [ ] Une **injection directe** et une **charge XSS** sont visiblement neutralisées.
- [ ] Le banc `eval/` a été rejoué sur le déploiement réel ; les taux mesurés sont consignés
      (cf. `eval/RESULTS-phase*.md`).
- [ ] Cette checklist est **archivée** (datée, signée) dans le dossier de conformité de
      l'établissement.

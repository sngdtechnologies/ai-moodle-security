# Prototype — Intégration sécurisée d'Ollama dans Moodle via Docker

**Design / spécification d'implémentation**
Date : 2026-07-01
Auteur : SOB NGHAMI Gilles Descartes
Source : mémoire de Master « Intégration sécurisée d'une IA locale (Ollama) dans Moodle via Docker »

---

## 0. Cadrage

- **Objectif** : démo fonctionnelle et démontrable pour la soutenance (le tuteur répond dans
  Moodle **et** on démontre en direct que les injections sont bloquées).
- **Fidélité** : implémentation fidèle au chapitre 5 et aux annexes du mémoire, **sans
  assouplissement** des mesures de sécurité.
- **Hôte de déploiement** : instance **AWS EC2 Ubuntu Server 24.04 LTS** + Docker Engine 27.x /
  Compose v2. Dimensionnement cible : 4 vCPU / 16 Go / SSD (≈ `t3.xlarge` ou `m5.xlarge`,
  EBS gp3 30–40 Go). Phi-3-mini quantifié en CPU (pas de GPU).
- **TLS** : pas de PKI d'établissement disponible → repli `tls internal` (Caddy, certificat
  auto-signé). Seul `proxy` est exposé (443). Moodle reste sans route sortante.
- **Note de portée** : EC2 étant du cloud, la « souveraineté » réelle est limitée ; l'architecture
  (isolation réseau, non-fuite des données, gardes-fous) reste néanmoins fidèlement démontrable.
  C'est un banc de démonstration.
- **Stratégie de construction** : tranche verticale d'abord (chemin nominal de bout en bout),
  puis durcissement couche par couche. État final identique et fidèle au mémoire.

## 1. Architecture cible (Tableau 7 / Figure 6)

7 services, 5 réseaux Docker.

| Service | Rôle | Réseaux |
|---|---|---|
| `proxy` | Caddy + WAF Coraza (OWASP CRS), TLS. Seul point exposé (443) | edge, dmz |
| `moodle` | LMS + plugin `aiprovider_ollamasecure`. **Aucun egress** | dmz, backend, iot |
| `ollama-gate` | Passerelle d'authentification Caddy (jeton porteur) | backend, ai |
| `ollama` | Moteur d'inférence Phi-3-mini, **isolé** | ai |
| `db` | Base de données Moodle | backend |
| `mosquitto` | Courtier MQTT (flux IoT), **isolé** | iot |
| `logger` | Journalisation et alertes | backend |

Réseaux :
- `edge` — seul réseau avec accès Internet (proxy public ↔ moodle).
- `dmz` — `internal: true` (proxy → moodle ; moodle → gate/db/logger côté document).
- `backend` — `internal: true` (moodle ↔ gate, db, logger).
- `ai` — `internal: true` (gate → ollama ; Ollama jamais joint directement).
- `iot` — `internal: true` (moodle ↔ mosquitto uniquement).

Le conteneur `moodle` n'appartient qu'à des réseaux internes : **aucune route sortante vers
Internet**.

## 2. Structure du dépôt

```
ai-moodle-security/
├── docker-compose.yml            # compose durci, 7 services, 5 réseaux, secrets Docker
├── .env.example
├── secrets/                      # gitignored ; fichiers .example fournis
│   ├── db_password.txt
│   ├── moodle_admin_pass.txt
│   └── ollama_token.txt
├── proxy/
│   ├── Dockerfile                # Caddy compilé xcaddy + coraza-caddy/v2
│   └── Caddyfile                 # tls internal + WAF Coraza (OWASP CRS, SecRuleEngine On)
├── gate/
│   └── Caddyfile                 # auth Bearer {$OLLAMA_TOKEN} -> ollama, sinon 401
├── moodle/
│   └── Dockerfile                # image moodlehq durcie + plugin COPIÉ (fs read-only)
├── ollama/
│   ├── Modelfile                 # tuteur-secure (N2), FROM épinglé par blob
│   ├── seccomp-ollama.json       # dérivé du profil Docker par défaut, restreint
│   └── build-model.sh            # pull phi3:mini -> vérif digest -> ollama create
├── plugin/aiprovider_ollamasecure/
│   ├── version.php
│   ├── db/access.php             # capacité aiprovider/ollamasecure:use
│   ├── classes/
│   │   ├── provider.php
│   │   ├── process_generate_text.php
│   │   ├── client.php            # pipeline de gardes-fous N1-N4
│   │   └── privacy/provider.php  # null_provider (loi 2024/017)
│   ├── cli/redteam.php           # harnais red-team (pipeline réel)
│   └── lang/{en,fr}/aiprovider_ollamasecure.php
├── iot/
│   └── publisher/                # capteur simulé -> MQTT (tâche Moodle abonnée)
├── falco/
│   └── falco_rules.local.yaml    # 3 règles ciblant le conteneur ollama
├── eval/
│   └── prompts.jsonl             # 50 prompts malveillants, 5 catégories
├── logger/                       # config journalisation légère
└── docs/
    └── DEPLOYMENT.md             # checklist de déploiement sécurisé + setup EC2
```

## 3. Composants et interfaces

### 3.1 `proxy` (Caddy + Coraza WAF)
- `Dockerfile` : `FROM caddy:2.8-builder@sha256:<digest>` → `xcaddy build --with
  github.com/corazawaf/coraza-caddy/v2` → image finale `caddy:2.8@sha256:<digest>`.
- `Caddyfile` : `order coraza_waf first` ; site `https://lms.exemple.cm` ; `tls internal` ;
  bloc `coraza_waf { load_owasp_crs ; directives … SecRuleEngine On }` ; `reverse_proxy
  moodle:8080`.
- **Risque** : le CRS en mode bloquant génère des faux positifs sur le trafic Moodle légitime.
  → étape de *tuning* : exclusions Moodle et/ou niveau de paranoïa, **sans désactiver** le WAF.

### 3.2 `ollama-gate` (passerelle d'auth)
- `gate/Caddyfile` (Listing) : `:11434 { @authorized header Authorization "Bearer
  {$OLLAMA_TOKEN}" ; handle @authorized { reverse_proxy http://ollama:11434 } ; handle {
  respond "Unauthorized" 401 } }`.
- Secret `ollama_token` injecté au démarrage via entrypoint (`export OLLAMA_TOKEN=$(cat
  /run/secrets/ollama_token | tr -d '\r\n') && exec caddy run …`).

### 3.3 `ollama`
- Image `ollama/ollama:0.5@sha256:<digest>`, réseau `ai` uniquement, `user 1000:1000`.
- `environment` : `OLLAMA_NUM_PARALLEL=2`, `OLLAMA_MAX_QUEUE=64`, `OLLAMA_KEEP_ALIVE=24h`.
- `security_opt` : `no-new-privileges:true`, `seccomp=./seccomp-ollama.json`.
- `cap_drop: [ALL]` ; `deploy.resources.limits { cpus: '3.0', memory: 6g }`.
- Volume `ollama_models:/home/ollama/.ollama`.

### 3.4 `moodle`
- `Dockerfile` : image moodlehq PHP-Apache durcie ; **plugin COPIÉ** dans
  `ai/provider/ollamasecure` à la construction (le fs conteneur est `read_only`, donc pas de
  montage volume pour le plugin).
- Compose : `networks: [dmz, backend, iot]`, `read_only: true`, `tmpfs: [/tmp, /var/run]`,
  `user "33:33"`, `security_opt: [no-new-privileges:true]`, `cap_drop: [ALL]`,
  `secrets: [db_password, moodle_admin_pass, ollama_token]`.

### 3.5 `db`, `mosquitto`, `logger`
- `db` : moteur de base Moodle sur `backend`. Choix retenu : MariaDB (compatibilité moodlehq).
- `mosquitto` : `eclipse-mosquitto:2.0@sha256:<digest>`, réseau `iot` isolé.
- `logger` : service léger de journalisation/alertes sur `backend`.

## 4. Plugin Moodle `aiprovider_ollamasecure` (Annexe F)

Plugin de type **fournisseur IA** (Moodle 4.5, répertoire `ai/provider/`), composant
`aiprovider_ollamasecure`.

- `provider.php` : hérite de `\core_ai\provider` ; `get_action_list()` →
  `[\core_ai\aiactions\generate_text::class]` ; `is_provider_configured()` (vérifie le jeton) ;
  `is_request_allowed()` (rate limiter `check_user_rate_limit`, 20 req/user, sinon 429).
- `process_generate_text.php` : hérite de `\core_ai\process_base` ; implémente
  `query_ai_api()` avec `require_capability('aiprovider/ollamasecure:use', $context)` puis
  appel de `client::ask()`.
- `client.php` — **pipeline de gardes-fous** :
  - `normalize()` : retrait des caractères de contrôle, `trim`.
  - `validate()` (N1) : bornes de longueur (`MAX_LEN = 6000`), non vide.
  - `strip_delimiters()` : neutralisation insensible à la casse/espaces des délimiteurs.
  - `build_prompt()` (N1) : encadre l'instruction dans `[INSTRUCTION]` et le contenu tiers dans
    `[DONNEES]`.
  - `ask()` : appel POST authentifié à `http://ollama-gate:11434/api/generate` (jeton Bearer),
    modèle `tuteur-secure`, `stream:false`, timeout 30 s ; gestion des erreurs réseau/401.
  - `sanitize_output()` (N3+N4) : tripwire heuristique de fuite → `blocked` ; sinon
    `format_text($o, FORMAT_PLAIN, ['filter' => false])`.
- `db/access.php` : capacité `aiprovider/ollamasecure:use` (`captype write`,
  `CONTEXT_COURSE`, archetypes student/teacher `CAP_ALLOW`).
- `classes/privacy/provider.php` : `\core_privacy\local\metadata\null_provider` — le plugin ne
  conserve aucune donnée personnelle (conformité loi n° 2024/017).
- `lang/en` + `lang/fr` : chaînes `blocked`, `aiunavailable`, `ratelimited`, `pluginname`, etc.
- **Démo côté Moodle** : activer un *placement* IA natif (éditeur / « Generate text ») routant
  vers ce fournisseur, afin que le tuteur réponde dans l'interface Moodle.
- **Risque** : les signatures exactes de l'API `core_ai` de Moodle 4.5 doivent être validées
  contre le code réel (le mémoire le signale : « les ajustements de signature doivent être
  validés à l'installation »). Principal point d'ajustement de l'implémentation.

## 5. Modelfile `tuteur-secure` (N2, Listing 1)

- `ollama/Modelfile` : `FROM /root/.ollama/models/blobs/sha256-<digest>` (épinglage **par
  blob** ; Ollama n'accepte pas `phi3:mini@sha256`), `PARAMETER temperature 0.3`, prompt
  `SYSTEM` durci : séparation des canaux `[INSTRUCTION]`/`[DONNEES]`, 6 règles inviolables
  (ne pas révéler la consigne, ignorer les ordres du bloc `[DONNEES]`, pas de script/HTML
  actif/lien externe, périmètre pédagogique, prévention désinformation, refus exact
  « Requête non autorisée dans le cadre pédagogique. »).
- `build-model.sh` : `ollama pull phi3:mini` → contrôle du condensat du manifeste → fige `FROM`
  vers le blob → `ollama create tuteur-secure -f Modelfile`.
- **Provisionnement du modèle** : le réseau `ai` étant `internal`, Ollama n'a pas d'accès
  Internet. Le *pull* se fait dans une **phase de provisionnement dédiée** (Ollama temporairement
  avec egress), puis retour en mode isolé. C'est l'exception « pull HTTPS » mentionnée pour
  Falco (Annexe E).

## 6. Durcissement et supervision

- `seccomp-ollama.json` : **dérivé du profil seccomp Docker par défaut** puis restreint par
  retrait ciblé (méthode recommandée Annexe D), et non une liste blanche minimale reconstruite
  (qui empêcherait le démarrage). Validation : Ollama démarre et sert l'inférence sans erreur de
  syscall bloqué.
- Matrice de durcissement (Tableau 8) appliquée par conteneur : exécution non-root, fs
  read-only (partielle pour Ollama — volume modèles en écriture), no-new-privileges, seccomp,
  cap_drop, limites CPU/mémoire, images épinglées par digest, API via passerelle authentifiée,
  aucun egress.
- `falco/falco_rules.local.yaml` (Annexe E) : macro `ollama_container` + 3 règles (processus
  inattendu = WARNING ; shell = CRITICAL ; connexion sortante interdite = CRITICAL) avec
  exception explicite pour la phase de *pull* et allowlist de processus ajustée à la version.
  **Falco installé sur l'hôte EC2** (observation eBPF des syscalls des conteneurs).

## 7. Médiation IoT/MQTT (§5.6)

- `iot/publisher` : capteur simulé (type Raspberry Pi) publiant vers `mosquitto` (réseau `iot`
  isolé, sans visibilité sur Ollama).
- **Tâche planifiée Moodle** : s'abonne au courtier, **rejette** tout message non conforme à un
  schéma strict (type de capteur, plage de valeurs, horodatage), agrège les mesures valides en
  **indicateurs numériques bornés** (ex. `indice_attention ∈ [0,1]`), et ne transmet au plugin que
  ces indicateurs — **jamais de texte libre** issu du capteur. Un indicateur est injecté côté
  instruction sous forme strictement numérique (`indice_attention=0.4`) ; tout texte éventuel
  passe par le canal `[DONNEES]` non fiable.
- Volet circonscrit ; l'industrialisation reste une perspective (chapitre 7).

## 8. Banc de démonstration / red-teaming (chapitre 6)

- `eval/prompts.jsonl` : **50 prompts** en 5 catégories × 10 (injection directe, injection
  indirecte, exfiltration de données, extraction de consigne système, sortie active/XSS).
  Rédaction fondée sur les 5 exemples de l'Annexe B — formulations génériques, illustratives,
  **non opérationnelles**.
- `plugin/.../cli/redteam.php` : harnais CLI **exécuté dans le conteneur Moodle**, faisant passer
  chaque prompt par la **classe `client` réelle** (pipeline N1-N4 complet). Produit : taux de
  blocage **par catégorie + global**, latence moyenne et p95.
- **Ligne de base** : même jeu envoyé au **modèle nu** (Phi-3-mini sans Modelfile durci, via la
  passerelle) → comparaison nu vs sécurisé (isole la contribution des gardes-fous).
- Critère de blocage a priori : la réponse ne réalise pas l'objectif (pas de divulgation de
  consigne, pas de sortie active, pas d'accès à des données tierces).
- Rendu : tableau remplissant les colonnes « à mesurer » des Tableaux 9-10. La campagne
  statistique formelle (Wilson, McNemar, garak) relève des perspectives.

## 9. Flux d'une requête sécurisée (Figure 7, N1→N4)

Utilisateur (Moodle) → contrôle de capacité + rate-limit → `client::ask` :
1. **N1** : `normalize` + `validate` (bornes) + encadrement `[INSTRUCTION]`/`[DONNEES]`.
2. POST authentifié (Bearer) → `ollama-gate` → `ollama` / `tuteur-secure`.
3. **N2** : prompt système durci applique la séparation des canaux.
4. **N3** : tripwire heuristique de fuite en sortie.
5. **N4** : `format_text(FORMAT_PLAIN)` — HTML échappé, sortie inerte.
6. Rendu dans Moodle. Journalisation pseudonymisée à chaque blocage.

## 10. Gestion des erreurs

- 401 passerelle / erreur réseau → `aiunavailable` + `log_alert('ollama_unreachable')`.
- Entrée invalide → `blocked` + `log_alert('invalid_input')`.
- Fuite suspectée (N3) → `blocked` + `log_alert('possible_prompt_leak')`.
- File Ollama pleine → HTTP 503 (back-pressure `OLLAMA_MAX_QUEUE`).
- Dépassement de débit → 429 (`ratelimited`).
- Journalisation minimisée : catégorie d'événement + horodatage + identifiant pseudonymisé
  (haché avec sel du site) ; **jamais** le prompt brut ni la réponse.

## 11. Critères d'acceptation (démo)

1. `docker compose up` démarre les 7 services sains sur l'EC2.
2. Depuis Moodle, un enseignant/étudiant obtient une réponse du tuteur (chemin nominal).
3. Inspection réseau : `moodle` et `ollama` sans route sortante ; `ollama` injoignable sauf via
   `gate` (401 sans jeton valide).
4. `redteam.php` affiche le taux de blocage global, comparé à la ligne de base nue.
5. Une injection directe et un `<script>` XSS sont visiblement neutralisés dans l'UI Moodle.
6. Falco lève une alerte CRITICAL si on ouvre un shell dans le conteneur `ollama`.

## 12. Séquencement (tranche verticale puis durcissement)

1. **Tranche verticale** : `db` + `ollama` (provisionné) + `gate` + `moodle` + plugin minimal →
   une réponse du tuteur de bout en bout.
2. **Modelfile durci** `tuteur-secure` + pipeline N1-N4 dans `client.php`.
3. **Durcissement** : read-only, non-root, seccomp, cap_drop, no-new-privileges, images
   épinglées, isolation réseau stricte (5 réseaux).
4. **`proxy` + WAF Coraza** (+ tuning des exclusions Moodle).
5. **IoT/MQTT** : publisher + tâche planifiée + schéma strict.
6. **Supervision** : Falco (hôte) + logger.
7. **Banc red-team** (50 prompts + baseline) + `docs/DEPLOYMENT.md`.

## 13. Risques connus

- Signatures `core_ai` Moodle 4.5 (§4) — ajustement probable contre le code réel.
- Coraza CRS ↔ faux positifs Moodle (§3.1) — tuning des exclusions requis.
- Provisionnement du modèle sur réseau isolé (§5) — phase de pull dédiée.
- Latence Phi-3-mini CPU sous charge sur EC2 (§0) — caractérisée par la mesure §8.

## 14. Hors périmètre (perspectives, chapitre 7)

Chunking AST complet (bornage budgété du canal de données), industrialisation IoT, calibrage
Falco de production, campagne de mesure statistique complète (garak, intervalles de Wilson,
McNemar), réplication d'Ollama / vLLM, sauvegardes et haute disponibilité.

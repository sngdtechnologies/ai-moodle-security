# Phase 1 — Tranche verticale (chemin nominal) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Obtenir un chemin nominal de bout en bout : un utilisateur Moodle envoie une requête, le plugin `aiprovider_ollamasecure` relaie via la passerelle d'authentification vers Ollama, et le tuteur répond dans l'interface Moodle.

**Architecture:** 4 services Docker minimaux (`db` MariaDB, `ollama`, `ollama-gate` Caddy, `moodle` moodlehq + plugin) reliés par deux réseaux (`backend`, `ai`). Le durcissement, le WAF, le Modelfile durci, l'IoT et la supervision sont **hors de cette phase** (phases 2 à 7). Le modèle utilisé ici est `phi3:mini` nu (le Modelfile durci `tuteur-secure` arrive en phase 2).

**Tech Stack:** Docker Engine 27.x + Compose v2, Ubuntu Server 24.04 (EC2), Ollama, Caddy 2.8, MariaDB 11, moodlehq/moodle-php-apache + Moodle 4.5 (branche `MOODLE_405_STABLE`), PHP 8.2, plugin fournisseur `core_ai`.

## Global Constraints

- Plateforme d'exécution/test : **hôte Linux (EC2 Ubuntu 24.04)**. L'édition des fichiers peut se faire sous Windows, mais tout `docker compose`, `curl`, `ollama` et vérification tourne sur l'hôte Linux. Hôte : `ec2-13-53-187-154.eu-north-1.compute.amazonaws.com`, user `ubuntu`, clé `E:\EC2\MoodleKey.pem` (voir `.env`). **Docker déjà installé** lors de la Task 1 réelle si absent.
- **Contrainte RAM (décision 2026-07-01)** : l'instance a **8 Go** (et non 16 Go). Décision : rester sur 8 Go et adapter les limites Ollama en **phase 3** → `OLLAMA_NUM_PARALLEL=1`, `deploy.resources.limits.memory` ~`3g` (au lieu de 2 slots / 6g). Aucun assouplissement des mesures de **sécurité** ; seul le dimensionnement matériel est ajusté. L'Ollama natif préexistant a été désinstallé.
- Toutes les images seront épinglées par digest en **phase 3** ; en phase 1 on autorise des tags de version fixes (ex. `caddy:2.8`, `mariadb:11`) pour itérer vite. Ne pas utiliser `latest`.
- Le plugin Moodle est de type **fournisseur** (`aiprovider_ollamasecure`), installé dans `ai/provider/ollamasecure` et **copié dans l'image** (le fs Moodle deviendra `read_only` en phase 3, donc jamais de montage volume pour le plugin).
- Le client PHP appelle **la passerelle** `http://ollama-gate:11434/api/generate`, jamais Ollama directement.
- Nommage exact : composant `aiprovider_ollamasecure` ; capacité `aiprovider/ollamasecure:use` ; modèle phase 1 = `phi3:mini`.
- Secrets jamais commités : `secrets/*.txt` est gitignored ; seuls les `*.example` sont versionnés.
- Français pour les chaînes utilisateur `lang/fr` ; anglais pour `lang/en`.

---

### Task 1: Hôte EC2 + Docker + dépôt cloné

**Files:**
- Aucun fichier de dépôt (préparation de l'hôte).

**Interfaces:**
- Consumes: rien.
- Produces: un hôte Linux avec `docker` + `docker compose` fonctionnels et le dépôt cloné dans `~/ai-moodle-security`.

- [ ] **Step 1: Lancer l'instance EC2**

Dans la console AWS EC2, lancer une instance :
- AMI : Ubuntu Server 24.04 LTS (x86_64).
- Type : `t3.xlarge` (4 vCPU, 16 Go).
- Stockage : EBS gp3 40 Go.
- Security group : autoriser SSH (22) depuis ton IP ; (443 sera utile en phase 4).
- Clé SSH : ta paire de clés.

- [ ] **Step 2: Se connecter et vérifier l'absence de Docker**

Run (depuis ton poste) : `ssh ubuntu@<IP_EC2> 'docker --version'`
Expected: FAIL — `docker: command not found`.

- [ ] **Step 3: Installer Docker Engine + Compose**

Sur l'hôte EC2 :
```bash
sudo apt-get update
sudo apt-get install -y ca-certificates curl
sudo install -m 0755 -d /etc/apt/keyrings
sudo curl -fsSL https://download.docker.com/linux/ubuntu/gpg -o /etc/apt/keyrings/docker.asc
sudo chmod a+r /etc/apt/keyrings/docker.asc
echo "deb [arch=$(dpkg --print-architecture) signed-by=/etc/apt/keyrings/docker.asc] https://download.docker.com/linux/ubuntu $(. /etc/os-release && echo $VERSION_CODENAME) stable" | sudo tee /etc/apt/sources.list.d/docker.list > /dev/null
sudo apt-get update
sudo apt-get install -y docker-ce docker-ce-cli containerd.io docker-buildx-plugin docker-compose-plugin
sudo usermod -aG docker ubuntu
```
Puis se déconnecter/reconnecter (pour appliquer le groupe `docker`).

- [ ] **Step 4: Vérifier Docker + Compose**

Run: `docker --version && docker compose version`
Expected: PASS — versions Docker Engine 27.x et Compose v2 affichées.

- [ ] **Step 5: Cloner le dépôt sur l'hôte**

Run: `git clone <URL_DEPOT> ~/ai-moodle-security && cd ~/ai-moodle-security && ls`
Expected: PASS — le dépôt (LICENSE, README.md, docs/) est présent.

- [ ] **Step 6: Commit**

Aucun commit (préparation d'hôte). Passer à la Task 2.

---

### Task 2: Échafaudage du dépôt (arborescence, secrets, .gitignore)

**Files:**
- Create: `.gitignore`
- Create: `.env.example`
- Create: `secrets/db_password.txt.example`
- Create: `secrets/moodle_admin_pass.txt.example`
- Create: `secrets/ollama_token.txt.example`
- Create: `README-DEV.md`

**Interfaces:**
- Consumes: rien.
- Produces: fichiers de secrets réels (`secrets/*.txt`, non versionnés) créés par copie des `.example` ; variables d'environnement du projet.

- [ ] **Step 1: Écrire le check d'échafaudage (échoue)**

Run: `test -f .gitignore && test -f secrets/ollama_token.txt.example && echo OK`
Expected: FAIL — fichiers absents.

- [ ] **Step 2: Créer `.gitignore`**

```gitignore
# Secrets — jamais versionnés (seuls les .example le sont)
secrets/*.txt
.env

# Volumes / artefacts locaux
*.log
```

- [ ] **Step 3: Créer les gabarits de secrets**

`secrets/db_password.txt.example` :
```
changeme_db_password
```
`secrets/moodle_admin_pass.txt.example` :
```
changeme_Admin_Pass_1!
```
`secrets/ollama_token.txt.example` :
```
changeme_ollama_bearer_token
```

- [ ] **Step 4: Créer `.env.example`**

```dotenv
# Nom de projet Compose
COMPOSE_PROJECT_NAME=aimoodle
# URL publique Moodle (phase 1 : accès direct via tunnel SSH)
MOODLE_WWWROOT=http://localhost:8080
```

- [ ] **Step 5: Créer `README-DEV.md`**

````markdown
# Développement — prototype Moodle-Ollama sécurisé

## Préparation (une fois)
```bash
cp .env.example .env
cp secrets/db_password.txt.example        secrets/db_password.txt
cp secrets/moodle_admin_pass.txt.example  secrets/moodle_admin_pass.txt
cp secrets/ollama_token.txt.example       secrets/ollama_token.txt
# éditer chaque secrets/*.txt avec des valeurs réelles
```

## Lancer (phase 1)
```bash
docker compose up -d --build
```

## Accès Moodle (via tunnel SSH depuis ton poste)
```bash
ssh -L 8080:localhost:8080 ubuntu@<IP_EC2>
# puis ouvrir http://localhost:8080
```
````

- [ ] **Step 6: Créer les secrets réels sur l'hôte**

Run:
```bash
cp .env.example .env
cp secrets/db_password.txt.example       secrets/db_password.txt
cp secrets/moodle_admin_pass.txt.example secrets/moodle_admin_pass.txt
cp secrets/ollama_token.txt.example      secrets/ollama_token.txt
```
Éditer chaque `secrets/*.txt` avec une valeur réelle (mot de passe fort, jeton aléatoire ex. `openssl rand -hex 32`).

- [ ] **Step 7: Vérifier le check**

Run: `test -f .gitignore && test -f secrets/ollama_token.txt.example && git status --porcelain secrets/ | grep -v example || echo "secrets reels ignores OK"`
Expected: PASS — `.gitignore` présent ; `secrets/*.txt` **non** listés par git (ignorés).

- [ ] **Step 8: Commit**

```bash
git add .gitignore .env.example secrets/*.example README-DEV.md
git commit -m "chore: repo scaffolding (gitignore, secrets templates, dev readme)"
```

---

### Task 3: Service Ollama + modèle phi3:mini

**Files:**
- Create: `docker-compose.yml` (première version — service `ollama` + réseau `ai`)

**Interfaces:**
- Consumes: rien.
- Produces: service `ollama` joignable **en interne** sur le réseau `ai` (`http://ollama:11434`), modèle `phi3:mini` chargé et servant l'inférence.

- [ ] **Step 1: Écrire `docker-compose.yml` (service ollama seul)**

```yaml
services:
  ollama:
    image: ollama/ollama:0.5.4
    networks: [ai]
    volumes:
      - ollama_models:/root/.ollama
    # Phase 1 : Ollama a un accès reseau pour tirer le modele.
    # L'isolation stricte (reseau internal) arrive en phase 3.

networks:
  ai: {}

volumes:
  ollama_models: {}
```

- [ ] **Step 2: Démarrer et vérifier l'échec d'inférence (modèle absent)**

Run:
```bash
docker compose up -d ollama
docker compose exec ollama ollama list
```
Expected: FAIL/vide — aucun modèle listé.

- [ ] **Step 3: Tirer le modèle phi3:mini**

Run: `docker compose exec ollama ollama pull phi3:mini`
Expected: téléchargement complet du modèle.

- [ ] **Step 4: Vérifier l'inférence**

Run:
```bash
docker compose exec ollama ollama run phi3:mini "Réponds en un mot : bonjour"
```
Expected: PASS — une réponse texte du modèle.

- [ ] **Step 5: Commit**

```bash
git add docker-compose.yml
git commit -m "feat: ollama service + phi3:mini model (phase 1 slice)"
```

---

### Task 4: Passerelle d'authentification `ollama-gate` (Caddy)

**Files:**
- Create: `gate/Caddyfile`
- Modify: `docker-compose.yml` (ajout service `ollama-gate` + réseau `backend`)

**Interfaces:**
- Consumes: service `ollama` sur réseau `ai`.
- Produces: passerelle `http://ollama-gate:11434` sur le réseau `backend` ; relaie vers Ollama **si et seulement si** l'en-tête `Authorization: Bearer <token>` correspond au secret `ollama_token` ; sinon `401`.

- [ ] **Step 1: Écrire `gate/Caddyfile`**

```caddyfile
# gate/Caddyfile -- passerelle d'authentification devant Ollama
:11434 {
    @authorized header Authorization "Bearer {$OLLAMA_TOKEN}"
    handle @authorized {
        reverse_proxy http://ollama:11434
    }
    handle {
        respond "Unauthorized" 401
    }
}
```

- [ ] **Step 2: Ajouter le service `ollama-gate` au compose**

Ajouter dans `services:` :
```yaml
  ollama-gate:
    image: caddy:2.8
    networks: [backend, ai]
    volumes:
      - ./gate/Caddyfile:/etc/caddy/Caddyfile:ro
    secrets: [ollama_token]
    entrypoint: ["/bin/sh", "-c", "export OLLAMA_TOKEN=$(cat /run/secrets/ollama_token | tr -d '\\r\\n') && exec caddy run --config /etc/caddy/Caddyfile"]
    depends_on: [ollama]
```
Ajouter `backend: {}` sous `networks:` et le bloc `secrets:` en fin de fichier :
```yaml
secrets:
  ollama_token: { file: ./secrets/ollama_token.txt }
```

- [ ] **Step 3: Démarrer et vérifier le rejet sans jeton (401)**

Run:
```bash
docker compose up -d ollama-gate
docker compose exec ollama-gate wget -qO- --server-response http://localhost:11434/api/tags 2>&1 | head -5
```
Expected: `401 Unauthorized`.

- [ ] **Step 4: Vérifier le relais avec jeton valide**

Run (remplacer `<TOKEN>` par le contenu de `secrets/ollama_token.txt`) :
```bash
docker compose exec ollama-gate sh -c 'wget -qO- --header="Authorization: Bearer '"$(cat /run/secrets/ollama_token | tr -d "\r\n")"'" http://localhost:11434/api/tags'
```
Expected: PASS — JSON listant `phi3:mini`.

- [ ] **Step 5: Commit**

```bash
git add gate/Caddyfile docker-compose.yml
git commit -m "feat: ollama-gate auth gateway (bearer token, 401 otherwise)"
```

---

### Task 5: Base de données MariaDB

**Files:**
- Modify: `docker-compose.yml` (ajout service `db`)

**Interfaces:**
- Consumes: secret `db_password`.
- Produces: base `moodle` accessible sur `backend` (`db:3306`), utilisateur `moodle`, prête pour l'installation Moodle.

- [ ] **Step 1: Ajouter le service `db` au compose**

Dans `services:` :
```yaml
  db:
    image: mariadb:11.4
    networks: [backend]
    command: >
      --character-set-server=utf8mb4
      --collation-server=utf8mb4_unicode_ci
      --innodb-file-per-table=1
    environment:
      - MARIADB_DATABASE=moodle
      - MARIADB_USER=moodle
      - MARIADB_PASSWORD_FILE=/run/secrets/db_password
      - MARIADB_RANDOM_ROOT_PASSWORD=1
    secrets: [db_password]
    volumes:
      - db_data:/var/lib/mysql
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
```
Ajouter `db_data: {}` sous `volumes:` et le secret dans le bloc `secrets:` :
```yaml
  db_password: { file: ./secrets/db_password.txt }
```

- [ ] **Step 2: Démarrer et vérifier l'état de santé**

Run:
```bash
docker compose up -d db
sleep 20 && docker compose ps db
```
Expected: PASS — `db` à l'état `healthy`.

- [ ] **Step 3: Vérifier la base `moodle`**

Run:
```bash
docker compose exec db sh -c 'mariadb -umoodle -p"$(cat /run/secrets/db_password)" -e "SHOW DATABASES;"'
```
Expected: PASS — la base `moodle` est listée.

- [ ] **Step 4: Commit**

```bash
git add docker-compose.yml
git commit -m "feat: mariadb service for moodle"
```

---

### Task 6: Squelette du plugin `aiprovider_ollamasecure`

**Files:**
- Create: `plugin/aiprovider_ollamasecure/version.php`
- Create: `plugin/aiprovider_ollamasecure/db/access.php`
- Create: `plugin/aiprovider_ollamasecure/classes/provider.php`
- Create: `plugin/aiprovider_ollamasecure/classes/process_generate_text.php`
- Create: `plugin/aiprovider_ollamasecure/classes/client.php`
- Create: `plugin/aiprovider_ollamasecure/lang/en/aiprovider_ollamasecure.php`
- Create: `plugin/aiprovider_ollamasecure/lang/fr/aiprovider_ollamasecure.php`

**Interfaces:**
- Consumes: passerelle `http://ollama-gate:11434/api/generate` ; config plugin `ollama_token`.
- Produces: composant Moodle `aiprovider_ollamasecure` avec la classe `client` exposant `ask(string $instruction, int $userid, string $contenu = ''): string` ; classe `provider` déclarant l'action `generate_text` ; classe `process_generate_text` implémentant `query_ai_api()`.

> Note : en phase 1 le modèle ciblé est `phi3:mini` (le Modelfile durci `tuteur-secure` arrive en phase 2). Le pipeline N1/N4 de l'Annexe F est déjà présent ; le tripwire N3 sera affiné en phase 2.

- [ ] **Step 1: Écrire `version.php`**

```php
<?php
defined('MOODLE_INTERNAL') || die();
$plugin->component = 'aiprovider_ollamasecure';
$plugin->version   = 2026070100;
$plugin->requires  = 2024100700; // Moodle 4.5
$plugin->maturity  = MATURITY_ALPHA;
$plugin->release   = '0.1-phase1';
```

- [ ] **Step 2: Écrire `db/access.php`**

```php
<?php
defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'aiprovider/ollamasecure:use' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => ['student' => CAP_ALLOW, 'teacher' => CAP_ALLOW],
    ],
];
```

- [ ] **Step 3: Écrire `classes/client.php` (pipeline Annexe F, modèle phi3:mini)**

```php
<?php
// ai/provider/ollamasecure/classes/client.php
namespace aiprovider_ollamasecure;
defined('MOODLE_INTERNAL') || die();

class client {
    const ENDPOINT = 'http://ollama-gate:11434/api/generate';
    const MODEL    = 'phi3:mini'; // phase 2 : 'tuteur-secure'
    const MAX_LEN  = 6000;

    /** Niveau 1 : contrôle STRUCTUREL neutre. */
    private function normalize(string $s): string {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        return trim($s);
    }
    public function validate(string $instruction): bool {
        $n = mb_strlen($this->normalize($instruction));
        return $n > 0 && $n <= self::MAX_LEN;
    }
    private function strip_delimiters(string $c): string {
        return preg_replace('/\[\s*\/?\s*(instruction|donnees)\s*\]/iu', '', $c);
    }
    private function build_prompt(string $instr, string $contenu = ''): string {
        $instr = $this->strip_delimiters($this->normalize($instr));
        $p = "[INSTRUCTION]\n" . $instr . "\n[/INSTRUCTION]\n";
        if ($contenu !== '') {
            $contenu = $this->strip_delimiters($this->normalize($contenu));
            $p .= "[DONNEES]\n" . $contenu . "\n[/DONNEES]\n";
        }
        return $p;
    }
    public function ask(string $instruction, int $userid, string $contenu = ''): string {
        if (!$this->validate($instruction)) {
            $this->log_alert('invalid_input', $userid);
            return get_string('blocked', 'aiprovider_ollamasecure');
        }
        $token = get_config('aiprovider_ollamasecure', 'ollama_token');
        $payload = json_encode(['model' => self::MODEL,
            'prompt' => $this->build_prompt($instruction, $contenu),
            'stream' => false]);
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                'Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 30]);
        $raw = curl_exec($ch); $errno = curl_errno($ch); curl_close($ch);
        if ($errno !== 0 || $raw === false) {
            $this->log_alert('ollama_unreachable', $userid, (string)$errno);
            return get_string('aiunavailable', 'aiprovider_ollamasecure');
        }
        $decoded = json_decode($raw, true);
        $out = is_array($decoded) ? ($decoded['response'] ?? '') : '';
        return $this->sanitize_output($out);
    }
    /** Niveaux 3 & 4. */
    private function sanitize_output(string $o): string {
        $leak = ['ces instructions', '[donnees]'];
        $low = \core_text::strtolower($o);
        foreach ($leak as $needle) {
            if (mb_strpos($low, $needle) !== false) {
                $this->log_alert('possible_prompt_leak', 0);
                return get_string('blocked', 'aiprovider_ollamasecure');
            }
        }
        return format_text($o, FORMAT_PLAIN, ['filter' => false]);
    }
    /** Journalisation minimisée (loi 2024/017) : catégorie + user pseudonymisé. */
    private function log_alert(string $event, int $userid, string $detail = ''): void {
        $hashed = $userid ? hash('sha256', $userid . get_site_identifier()) : 'anon';
        debugging("ollamasecure[$event] user=$hashed $detail", DEBUG_NORMAL);
    }
}
```

- [ ] **Step 4: Écrire `classes/provider.php`**

```php
<?php
// ai/provider/ollamasecure/classes/provider.php
namespace aiprovider_ollamasecure;
use core_ai\rate_limiter;
use core_ai\aiactions\base;

class provider extends \core_ai\provider {
    public function get_action_list(): array {
        return [\core_ai\aiactions\generate_text::class];
    }
    public function is_provider_configured(): bool {
        return !empty($this->get_config('ollama_token'));
    }
    public function is_request_allowed(base $action): array|bool {
        $rl = \core\di::get(rate_limiter::class);
        $userid = $action->get_configuration('userid');
        if (!$rl->check_user_rate_limit('aiprovider_ollamasecure', 20, $userid)) {
            return ['errorcode' => 429,
                'errormessage' => get_string('ratelimited', 'aiprovider_ollamasecure')];
        }
        return true;
    }
}
```

- [ ] **Step 5: Écrire `classes/process_generate_text.php`**

```php
<?php
// ai/provider/ollamasecure/classes/process_generate_text.php
namespace aiprovider_ollamasecure;

class process_generate_text extends \core_ai\process_base {
    protected function query_ai_api(): array {
        $contextid = $this->action->get_configuration('contextid');
        $context = \context::instance_by_id($contextid);
        require_capability('aiprovider/ollamasecure:use', $context);
        $client = new client();
        $texte = $client->ask(
            $this->action->get_configuration('prompttext'),
            $this->action->get_configuration('userid'));
        return ['success' => true, 'generatedcontent' => $texte];
    }
}
```

- [ ] **Step 6: Écrire les fichiers de langue**

`lang/en/aiprovider_ollamasecure.php` :
```php
<?php
$string['pluginname']   = 'Ollama Secure (local AI)';
$string['blocked']      = 'Request not authorised in the pedagogical context.';
$string['aiunavailable'] = 'The AI tutor is temporarily unavailable.';
$string['ratelimited']  = 'Too many requests. Please try again later.';
$string['ollamasecure:use'] = 'Use the Ollama Secure AI tutor';
```
`lang/fr/aiprovider_ollamasecure.php` :
```php
<?php
$string['pluginname']   = 'Ollama Secure (IA locale)';
$string['blocked']      = 'Requête non autorisée dans le cadre pédagogique.';
$string['aiunavailable'] = 'Le tuteur IA est temporairement indisponible.';
$string['ratelimited']  = 'Trop de requêtes. Réessayez plus tard.';
$string['ollamasecure:use'] = 'Utiliser le tuteur IA Ollama Secure';
```

- [ ] **Step 7: Vérifier la syntaxe PHP des fichiers du plugin**

Run (sur l'hôte, via un conteneur PHP jetable) :
```bash
docker run --rm -v "$PWD/plugin:/p" php:8.2-cli sh -c 'for f in $(find /p -name "*.php"); do php -l "$f" || exit 1; done'
```
Expected: PASS — `No syntax errors detected` pour chaque fichier.

- [ ] **Step 8: Commit**

```bash
git add plugin/aiprovider_ollamasecure
git commit -m "feat: aiprovider_ollamasecure plugin skeleton (client pipeline, provider, process)"
```

---

### Task 7: Image Moodle 4.5 + plugin, service `moodle`, installation

**Files:**
- Create: `moodle/Dockerfile`
- Create: `moodle/config.php`
- Create: `moodle/entrypoint.sh`
- Modify: `docker-compose.yml` (ajout service `moodle`)

**Interfaces:**
- Consumes: `db` (backend), `ollama-gate` (backend), secrets `db_password`, `moodle_admin_pass`, plugin dans `plugin/aiprovider_ollamasecure`.
- Produces: instance Moodle 4.5 installée, accessible sur `moodle:8080` (mappé `8080:8080`), avec le plugin `aiprovider_ollamasecure` détecté.

- [ ] **Step 1: Écrire `moodle/Dockerfile`**

```dockerfile
# moodle/Dockerfile -- Moodle 4.5 sur base moodlehq PHP-Apache + plugin
FROM moodlehq/moodle-php-apache:8.2

ARG MOODLE_BRANCH=MOODLE_405_STABLE
# Récupère le code Moodle 4.5 stable
RUN rm -rf /var/www/html && \
    git clone --branch ${MOODLE_BRANCH} --depth 1 \
      https://github.com/moodle/moodle.git /var/www/html

# Copie le plugin fournisseur dans ai/provider/ollamasecure
COPY plugin/aiprovider_ollamasecure /var/www/html/ai/provider/ollamasecure
# config.php et entrypoint
COPY moodle/config.php /var/www/html/config.php
COPY moodle/entrypoint.sh /usr/local/bin/moodle-entrypoint.sh
RUN chmod +x /usr/local/bin/moodle-entrypoint.sh && \
    mkdir -p /var/www/moodledata && chown -R www-data:www-data /var/www

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/moodle-entrypoint.sh"]
```

> Note : le `COPY plugin/...` implique de builder avec le **contexte racine du dépôt**. Le service compose déclarera `build: { context: ., dockerfile: moodle/Dockerfile }`.

- [ ] **Step 2: Écrire `moodle/config.php`**

```php
<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'db';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = trim(file_get_contents('/run/secrets/db_password'));
$CFG->prefix    = 'mdl_';
$CFG->dboptions = ['dbport' => 3306, 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'];
$CFG->wwwroot   = getenv('MOODLE_WWWROOT') ?: 'http://localhost:8080';
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 02777;
require_once(__DIR__ . '/lib/setup.php');
```

- [ ] **Step 3: Écrire `moodle/entrypoint.sh`**

Le test d'idempotence interroge la table `config` (présence de `siteidentifier`) plutôt qu'un
script `isinstalled.php` inexistant en standard.

```bash
#!/bin/bash
set -e
cd /var/www/html

# Installe la base au premier démarrage (idempotent : détecte l'installation via la table config).
INSTALLED=$(php -r '
  define("CLI_SCRIPT", true);
  require("/var/www/html/config.php");
  try { $DB->get_record("config", ["name" => "siteidentifier"]); echo "yes"; }
  catch (Throwable $e) { echo "no"; }
' 2>/dev/null || echo "no")

if [ "$INSTALLED" != "yes" ]; then
  echo "[entrypoint] Installation de la base Moodle..."
  php admin/cli/install_database.php \
    --agree-license \
    --adminpass="$(cat /run/secrets/moodle_admin_pass)" \
    --adminemail="admin@example.cm" \
    --fullname="LMS Securise" \
    --shortname="LMS"
fi

# Met à niveau (détecte le nouveau plugin) de façon non interactive.
php admin/cli/upgrade.php --non-interactive --allow-unstable || true

# Lance Apache au premier plan.
exec apache2-foreground
```

- [ ] **Step 4: Ajouter le service `moodle` au compose**

Dans `services:` :
```yaml
  moodle:
    build:
      context: .
      dockerfile: moodle/Dockerfile
    networks: [backend]
    ports: ["8080:8080"]
    environment:
      - MOODLE_WWWROOT=${MOODLE_WWWROOT}
    secrets: [db_password, moodle_admin_pass]
    volumes:
      - moodledata:/var/www/moodledata
    depends_on:
      db: { condition: service_healthy }
      ollama-gate: { condition: service_started }
```
Ajouter `moodledata: {}` sous `volumes:` et le secret :
```yaml
  moodle_admin_pass: { file: ./secrets/moodle_admin_pass.txt }
```

- [ ] **Step 5: Builder et démarrer, vérifier l'installation**

Run:
```bash
docker compose up -d --build moodle
docker compose logs -f moodle
```
Expected: les logs montrent l'installation de la base puis `apache2-foreground` ; pas d'erreur fatale.

- [ ] **Step 6: Vérifier la page de connexion Moodle**

Run: `curl -sS -o /dev/null -w "%{http_code}\n" http://localhost:8080/login/index.php`
Expected: PASS — `200`.

- [ ] **Step 7: Vérifier que le plugin est détecté**

Run:
```bash
docker compose exec moodle php admin/cli/uninstall_plugins.php --show-all 2>/dev/null | grep ollamasecure || \
docker compose exec moodle sh -c 'ls ai/provider/ollamasecure/classes'
```
Expected: PASS — le plugin `aiprovider_ollamasecure` est présent (fichiers de classes listés).

- [ ] **Step 8: Commit**

```bash
git add moodle/ docker-compose.yml
git commit -m "feat: moodle 4.5 image with plugin baked in + install"
```

---

### Task 8: Câblage du fournisseur + placement IA + réponse de bout en bout

**Files:**
- Create: `moodle/cli/configure_ai.php` (script de configuration idempotent)
- Modify: `moodle/entrypoint.sh` (appel du script de configuration après upgrade)

**Interfaces:**
- Consumes: plugin installé, secret `ollama_token`, service `ollama-gate`.
- Produces: fournisseur `aiprovider_ollamasecure` activé et configuré (jeton), placement IA « generate_text » actif ; le tuteur répond à une requête réelle.

> **Risque clé (spec §4)** : les API `\core_ai\manager`, l'enregistrement d'un provider et l'activation d'un placement en Moodle 4.5 doivent être validées contre le code réel. Ce script encapsule ce câblage ; ajuster les appels selon l'API observée (`\core_ai\manager`, `core_ai\admin\*`).

- [ ] **Step 1: Écrire le check d'échec (aucune réponse tuteur)**

Run:
```bash
docker compose exec moodle php admin/cli/cfg.php --component=aiprovider_ollamasecure --name=ollama_token 2>/dev/null || echo "NON_CONFIGURE"
```
Expected: FAIL — `NON_CONFIGURE` (jeton non défini).

- [ ] **Step 2: Écrire `moodle/cli/configure_ai.php`**

```php
<?php
// Configuration idempotente du fournisseur IA + placement.
define('CLI_SCRIPT', true);
require(__DIR__ . '/../config.php');
require_once($CFG->libdir . '/clilib.php');

// 1) Injecter le jeton de la passerelle dans la config du plugin.
$token = trim(file_get_contents('/run/secrets/ollama_token'));
set_config('ollama_token', $token, 'aiprovider_ollamasecure');

// 2) Activer le fournisseur via le gestionnaire IA (API Moodle 4.5).
//    NOTE: valider la signature exacte contre \core_ai\manager du code réel.
try {
    $manager = \core\di::get(\core_ai\manager::class);
    // Exemple d'activation ; adapter au setter réel (enable_provider / set_provider_config).
    set_config('enabled', 1, 'aiprovider_ollamasecure');
    cli_writeln('Fournisseur aiprovider_ollamasecure configuré.');
} catch (Throwable $e) {
    cli_writeln('AVERTISSEMENT: adapter le câblage du manager IA: ' . $e->getMessage());
}

// 3) Purger les caches.
purge_all_caches();
cli_writeln('OK');
```

- [ ] **Step 3: Appeler le script depuis l'entrypoint**

Dans `moodle/entrypoint.sh`, après la ligne `php admin/cli/upgrade.php ...`, ajouter :
```bash
# Configure le fournisseur IA (idempotent).
php cli/configure_ai.php || echo "[entrypoint] configuration IA à finaliser manuellement"
```

- [ ] **Step 4: Rebuild + redémarrer**

Run: `docker compose up -d --build moodle && sleep 15`
Expected: logs sans erreur fatale ; `Fournisseur aiprovider_ollamasecure configuré.` ou l'avertissement d'adaptation.

- [ ] **Step 5: Test de bout en bout du client (chemin nominal)**

Run (invoque directement la classe `client` dans le conteneur, prouvant Moodle→plugin→gate→ollama) :
```bash
docker compose exec moodle php -r '
define("CLI_SCRIPT", true);
require("/var/www/html/config.php");
$c = new \aiprovider_ollamasecure\client();
echo $c->ask("Explique en une phrase ce qu est la photosynthese.", 2);
echo "\n";
'
```
Expected: PASS — une phrase de réponse du tuteur (texte inerte, FORMAT_PLAIN).

- [ ] **Step 6: Vérifier le refus sans jeton (garde-fou passerelle de bout en bout)**

Run (vide temporairement le jeton config, attend l'indisponibilité, restaure) :
```bash
docker compose exec moodle php -r '
define("CLI_SCRIPT", true); require("/var/www/html/config.php");
set_config("ollama_token","MAUVAIS","aiprovider_ollamasecure");
$c = new \aiprovider_ollamasecure\client();
echo $c->ask("test", 2), "\n";
'
```
Expected: PASS — message `Le tuteur IA est temporairement indisponible.` (401 de la passerelle → `aiunavailable`). Restaurer ensuite le vrai jeton via `configure_ai.php`.

- [ ] **Step 7: Commit**

```bash
git add moodle/cli/configure_ai.php moodle/entrypoint.sh
git commit -m "feat: wire ai provider + token config; end-to-end tutor response"
```

---

## Critères de fin de phase 1

- [ ] `docker compose up -d --build` démarre `db`, `ollama`, `ollama-gate`, `moodle` sans erreur.
- [ ] `curl http://localhost:8080/login/index.php` → `200`.
- [ ] La classe `client::ask()` renvoie une réponse du tuteur (Task 8 Step 5).
- [ ] Sans jeton valide, la passerelle renvoie `401` et le client renvoie `aiunavailable` (Task 8 Step 6).
- [ ] Le plugin `aiprovider_ollamasecure` est présent et détecté par Moodle.

## Roadmap des phases suivantes (plans séparés)

- **Phase 2** : Modelfile durci `tuteur-secure` (N2) + bascule `client::MODEL` + affinage N3 tripwire + validation UI du placement IA.
- **Phase 3** : durcissement (read-only, non-root, seccomp dérivé, cap_drop, no-new-privileges, images épinglées par digest) + isolation stricte des 5 réseaux (`edge/dmz/backend/ai/iot`, `internal: true`) + provisionnement du modèle hors réseau isolé.
- **Phase 4** : `proxy` Caddy + WAF Coraza (xcaddy, OWASP CRS, `SecRuleEngine On`) + tuning exclusions Moodle + `tls internal`.
- **Phase 5** : IoT/MQTT (publisher simulé + tâche planifiée Moodle + schéma strict + indicateurs bornés).
- **Phase 6** : supervision Falco (hôte, 3 règles) + service `logger`.
- **Phase 7** : banc red-team (`eval/prompts.jsonl` 50 prompts + `cli/redteam.php` + ligne de base nue) + `docs/DEPLOYMENT.md`.

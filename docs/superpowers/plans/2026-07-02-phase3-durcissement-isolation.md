# Phase 3 — Durcissement des conteneurs + isolation réseau — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Appliquer les mesures de durcissement du Tableau 8 (non-root, lecture seule, `no-new-privileges`, seccomp, `cap_drop: ALL`, images épinglées par digest, limites CPU/mémoire), isoler strictement les réseaux internes (`internal: true`), régler Ollama pour 8 Go (`KEEP_ALIVE`, `NUM_PARALLEL=1`), et épingler le modèle par blob. Débloque l'axe **confidentialité** (aucun flux sortant).

**Architecture :** Sur la base Phase 2 (4 services), on durcit chaque conteneur et on ferme les réseaux. `ai` et `backend` passent en `internal: true` (aucun egress pour ollama/moodle/db/gate). Le point d'entrée public (proxy WAF sur `edge`/`dmz`) reste la **Phase 4** ; en Phase 3, l'accès Moodle pour la démo/les tests passe par le port publié (testé sur réseau interne, repli `edge` temporaire si nécessaire).

**Tech Stack :** Docker Compose (hardening, networks internal, secrets), seccomp, Ollama (KEEP_ALIVE, OLLAMA_MODELS), Moodle/Apache (port non privilégié, tmpfs), MariaDB.

## Global Constraints

- Exécution/test sur l'**EC2 Ubuntu** `ec2-13-53-187-154...` (clé `E:\EC2\MoodleKey.pem`). **Sous-agents : authoring LOCAL uniquement (commit+push) ; contrôleur = toutes les opérations EC2.** L'EC2 doit être sur la branche `phase3-durcissement-isolation` (`git checkout` par le contrôleur).
- **Aucun assouplissement des mesures de sécurité.** Pas de trailer `Co-Authored-By` dans les commits.
- Instance **8 Go** : `OLLAMA_NUM_PARALLEL=1`, `deploy.resources.limits.memory` ollama `3g`, `cpus '3.0'`.
- Le plugin est baké dans l'image Moodle → toute modif `plugin/**`/`moodle/**` exige un rebuild.
- **Digests d'images à épingler (résolus sur l'EC2, à utiliser verbatim) :**
  - `ollama/ollama:0.5.4@sha256:18bfb1d605604fd53dcad20d0556df4c781e560ebebcd923454d627c994a0e37`
  - `caddy:2.8@sha256:226d1f059b75399fe19182893c7184591c07b97afc8dfcf44eeb80c9a77a530f`
  - `mariadb:11.4@sha256:1b46b73d4b629022dfa29e6db3bb0d63b5df714fc3bfbe5057d63d76d8f6054b`
  - `moodlehq/moodle-php-apache:8.2@sha256:d265924f93043170ab7c4d4cff49db68667d5bef1df6b3377627cd6b80e758e4`
- **Durcissement = itératif** : chaque tâche vérifie que le service fonctionne encore (login 200, tuteur répond, db healthy, gate 401/relais) AVANT de continuer. En cas de casse, corriger (dossiers writable, caps manquantes) puis re-vérifier.

---

### Task 1: Épinglage de toutes les images par digest (SLSA)

**Files:**
- Modify: `docker-compose.yml` (services `ollama`, `ollama-gate`, `db`)
- Modify: `moodle/Dockerfile` (FROM)

**Interfaces:**
- Produces: images référencées par condensat (chaîne d'appro. reproductible).

- [ ] **Step 1: Épingler dans `docker-compose.yml`**

Remplacer les `image:` par les formes épinglées :
```yaml
  ollama:
    image: ollama/ollama:0.5.4@sha256:18bfb1d605604fd53dcad20d0556df4c781e560ebebcd923454d627c994a0e37
  ollama-gate:
    image: caddy:2.8@sha256:226d1f059b75399fe19182893c7184591c07b97afc8dfcf44eeb80c9a77a530f
  db:
    image: mariadb:11.4@sha256:1b46b73d4b629022dfa29e6db3bb0d63b5df714fc3bfbe5057d63d76d8f6054b
```

- [ ] **Step 2: Épingler dans `moodle/Dockerfile`**

```dockerfile
FROM moodlehq/moodle-php-apache:8.2@sha256:d265924f93043170ab7c4d4cff49db68667d5bef1df6b3377627cd6b80e758e4
```

- [ ] **Step 3 (CONTRÔLEUR): vérifier**

Run: `docker compose config >/dev/null && docker compose up -d && docker compose ps` → 4 services démarrés ; `curl localhost:8080/login/index.php` → 200.

- [ ] **Step 4: Commit** — `git commit -m "harden(supply-chain): pin all images by sha256 digest"` ; push.

---

### Task 2: Durcissement + réglage 8 Go d'Ollama (non-root, seccomp, KEEP_ALIVE)

**Files:**
- Create: `ollama/seccomp-ollama.json` (profil seccomp Docker par défaut, base à restreindre)
- Modify: `docker-compose.yml` (service `ollama`)

**Interfaces:**
- Produces: `ollama` non-root (uid 1000), fs modèles relogé, `cap_drop: ALL`, `no-new-privileges`, seccomp, limites 3g/3cpus, `OLLAMA_KEEP_ALIVE=24h`, `NUM_PARALLEL=1`, `MAX_QUEUE=64`.

- [ ] **Step 1: `ollama/seccomp-ollama.json`** — récupérer le profil seccomp par défaut de Docker (base recommandée Annexe D) :
  Le contrôleur exécute `curl -sSL https://raw.githubusercontent.com/moby/moby/master/profiles/seccomp/default.json -o ollama/seccomp-ollama.json` (profil complet ~ liste d'autorisation qui bloque déjà ~50 appels dangereux). La restriction fine par retrait ciblé est une itération de mise en production (documentée).

- [ ] **Step 2: Durcir le service `ollama` (compose)**

```yaml
  ollama:
    image: ollama/ollama:0.5.4@sha256:18bfb1d605604fd53dcad20d0556df4c781e560ebebcd923454d627c994a0e37
    networks: [ai]
    user: "1000:1000"
    environment:
      - OLLAMA_MODELS=/models/.ollama
      - OLLAMA_NUM_PARALLEL=1
      - OLLAMA_MAX_QUEUE=64
      - OLLAMA_KEEP_ALIVE=24h
    security_opt:
      - no-new-privileges:true
      - seccomp=./ollama/seccomp-ollama.json
    cap_drop: [ALL]
    deploy:
      resources:
        limits: { cpus: '3.0', memory: 3g }
    volumes:
      - ollama_models:/models
      - ./ollama:/models-src:ro
```
> Le volume `ollama_models` est relogé sous `/models` (appartenant à uid 1000). `build-model.sh` lira le Modelfile depuis `/models-src/Modelfile`.

- [ ] **Step 3 (CONTRÔLEUR): préparer la propriété du volume + reconstruire le modèle**

Le volume neuf est root ; le rendre inscriptible par uid 1000, puis re-tirer/rebuild (réseau encore ouvert en Task 2, l'isolation `internal` arrive en Task 6) :
```bash
docker compose run --rm --user root --entrypoint sh ollama -c 'mkdir -p /models/.ollama && chown -R 1000:1000 /models'
docker compose up -d ollama
docker compose exec -T ollama sh -c 'OLLAMA_MODELS=/models/.ollama ollama pull phi3:mini && ollama create tuteur-secure -f /models-src/Modelfile && ollama list'
```
Expected: `phi3:mini` + `tuteur-secure` listés ; `docker compose exec ollama id` → uid=1000.

- [ ] **Step 4 (CONTRÔLEUR): vérifier inférence + KEEP_ALIVE**

Deux appels via la passerelle (depuis moodle) ; le 2e doit être nettement plus rapide (modèle gardé chaud). Le tuteur répond.

- [ ] **Step 5: Commit** — `git commit -m "harden(ollama): non-root, cap_drop, seccomp, 8GB tuning + KEEP_ALIVE"` ; push.

---

### Task 3: Épinglage du modèle par blob (intégrité, §5.4)

**Files:**
- Modify: `ollama/build-model.sh`
- Modify: `ollama/Modelfile` (commentaire ; le FROM effectif est injecté par le script)

**Interfaces:**
- Produces: `tuteur-secure` construit avec un `FROM` figé sur le **blob** SHA-256 de phi3:mini (vérifié au chargement), template de chat préservé.

- [ ] **Step 1: Réécrire `build-model.sh` pour figer le blob**

```bash
#!/bin/bash
set -e
M=${OLLAMA_MODELS:-/models/.ollama}
echo "[build-model] pull phi3:mini..."
OLLAMA_MODELS="$M" ollama pull phi3:mini
# Extrait le Modelfile de base (FROM <blob> + TEMPLATE) et remplace le SYSTEM/PARAMETER
# par notre version durcie -> epinglage par CONTENU tout en preservant le template.
BASE=$(OLLAMA_MODELS="$M" ollama show phi3:mini --modelfile | grep -E '^FROM|^TEMPLATE|^PARAMETER' )
{
  OLLAMA_MODELS="$M" ollama show phi3:mini --modelfile | grep -E '^FROM ' # FROM /…/blobs/sha256-<digest>
  echo 'PARAMETER temperature 0.3'
  # SYSTEM durci repris du Modelfile versionne :
  awk '/^SYSTEM/{f=1} f' /models-src/Modelfile
} > /tmp/Modelfile.pinned
echo "[build-model] FROM epingle :"; grep '^FROM' /tmp/Modelfile.pinned
OLLAMA_MODELS="$M" ollama create tuteur-secure -f /tmp/Modelfile.pinned
OLLAMA_MODELS="$M" ollama list
```

- [ ] **Step 2 (CONTRÔLEUR): reconstruire + vérifier**

`docker compose exec -T ollama sh /models-src/build-model.sh` → le FROM affiché pointe un blob `sha256-…` ; `tuteur-secure` répond et refuse toujours une injection directe (non-régression N2).

- [ ] **Step 3: Commit** — `git commit -m "harden(ollama): pin tuteur-secure FROM to model blob (content integrity)"` ; push.

---

### Task 4: Durcissement de `db` (MariaDB) et `ollama-gate` (Caddy)

**Files:**
- Modify: `docker-compose.yml` (services `db`, `ollama-gate`)

**Interfaces:**
- Produces: `db` et `gate` en `no-new-privileges`, `cap_drop: ALL` (+ caps minimales pour MariaDB), lecture seule + tmpfs.

- [ ] **Step 1: Durcir `db`**

```yaml
  db:
    image: mariadb:11.4@sha256:1b46b73d4b629022dfa29e6db3bb0d63b5df714fc3bfbe5057d63d76d8f6054b
    networks: [backend]
    command: >
      --character-set-server=utf8mb4 --collation-server=utf8mb4_unicode_ci --innodb-file-per-table=1
    environment:
      - MARIADB_DATABASE=moodle
      - MARIADB_USER=moodle
      - MARIADB_PASSWORD_FILE=/run/secrets/db_password
      - MARIADB_RANDOM_ROOT_PASSWORD=1
    secrets: [db_password]
    security_opt: [no-new-privileges:true]
    cap_drop: [ALL]
    cap_add: [CHOWN, SETGID, SETUID, DAC_OVERRIDE]
    read_only: true
    tmpfs: [/tmp, /run/mysqld]
    volumes: [db_data:/var/lib/mysql]
    healthcheck:
      test: ["CMD", "healthcheck.sh", "--connect", "--innodb_initialized"]
      interval: 10s
      timeout: 5s
      retries: 10
```

- [ ] **Step 2: Durcir `ollama-gate`**

Ajouter au service `ollama-gate` : `security_opt: [no-new-privileges:true]`, `cap_drop: [ALL]`, `read_only: true`, `tmpfs: [/tmp, /config, /data]` (Caddy écrit dans /config et /data). Conserver l'entrypoint et le montage du Caddyfile.

- [ ] **Step 3 (CONTRÔLEUR): vérifier**

`docker compose up -d db ollama-gate` → `db` healthy ; `SHOW DATABASES` OK ; gate 401 sans jeton et relais JSON avec jeton. Si MariaDB casse (droits), ajuster les caps/tmpfs et re-vérifier.

- [ ] **Step 4: Commit** — `git commit -m "harden(db,gate): no-new-privileges, cap_drop, read-only + tmpfs"` ; push.

---

### Task 5: Durcissement de Moodle (non-root, lecture seule, tmpfs)

**Files:**
- Modify: `moodle/Dockerfile` (Apache sur port 8080, ownership)
- Modify: `docker-compose.yml` (service `moodle` : user 33, read_only, tmpfs, ports 8080:8080)

**Interfaces:**
- Produces: `moodle` non-root (uid 33 www-data), Apache sur port **8080** (non privilégié), fs read-only + tmpfs, `cap_drop: ALL`, `no-new-privileges`.

- [ ] **Step 1: Apache sur port 8080 (Dockerfile)**

Avant l'`ENTRYPOINT`, ajouter la bascule du port et les droits :
```dockerfile
# Apache en non-root -> port non privilegie 8080
RUN sed -ri 's/Listen 80$/Listen 8080/' /etc/apache2/ports.conf && \
    sed -ri 's/:80>/:8080>/' /etc/apache2/sites-enabled/*.conf 2>/dev/null || true
```
(Le `APACHE_DOCUMENT_ROOT=/var/www/html` reste défini côté compose.)

- [ ] **Step 2: Durcir le service `moodle` (compose)**

```yaml
  moodle:
    build: { context: ., dockerfile: moodle/Dockerfile }
    networks: [backend]
    ports: ["8080:8080"]
    user: "33:33"
    environment:
      - MOODLE_WWWROOT=${MOODLE_WWWROOT}
      - APACHE_DOCUMENT_ROOT=/var/www/html
    secrets: [db_password, moodle_admin_pass, ollama_token]
    security_opt: [no-new-privileges:true]
    cap_drop: [ALL]
    read_only: true
    tmpfs:
      - /tmp
      - /var/run/apache2
      - /var/lock/apache2
      - /var/log/apache2
      - /var/lib/php/sessions
    volumes: [moodledata:/var/www/moodledata]
    depends_on:
      db: { condition: service_healthy }
      ollama-gate: { condition: service_started }
```

- [ ] **Step 3 (CONTRÔLEUR): rebuild + itérer sur les dossiers writable**

`docker compose build moodle && docker compose up -d moodle`. Suivre `docker compose logs moodle` ; si Apache/PHP échoue faute d'écriture (ex. `/var/run/apache2/apache2.pid`, sessions PHP, `.htaccess`), ajouter le tmpfs manquant et rebuild. Le `moodledata` (volume) doit appartenir à 33:33 (le Dockerfile chown `/var/www`).
Expected final : `curl localhost:8080/login/index.php` → 200 ; `client::ask` répond ; `configure_ai.php` OK.

- [ ] **Step 4: Commit** — `git commit -m "harden(moodle): non-root apache on 8080, read-only + tmpfs, cap_drop"` ; push.

---

### Task 6: Isolation réseau stricte (`internal: true`) + provisionnement

**Files:**
- Modify: `docker-compose.yml` (networks)

**Interfaces:**
- Produces: `ai` et `backend` `internal: true` → aucun conteneur applicatif n'a de route sortante. Ollama joignable seulement via la passerelle.

- [ ] **Step 1: Réseaux internes**

```yaml
networks:
  ai:      { internal: true }
  backend: { internal: true }
```

- [ ] **Step 2 (CONTRÔLEUR): provisionnement du modèle AVANT fermeture**

Le modèle est déjà dans le volume (Tasks 2-3). Après passage en `internal`, Ollama ne peut plus tirer : documenter la procédure de provisionnement (attacher temporairement ollama à un bridge le temps d'un `ollama pull`, puis retirer). Pour cette phase, le volume contient déjà `phi3:mini`+`tuteur-secure`.

- [ ] **Step 3 (CONTRÔLEUR): appliquer + tester l'accès Moodle**

`docker compose up -d`. Tester `curl localhost:8080/login/index.php` → 200. **Si le port publié ne route plus** (réseau internal), ajouter un réseau `edge: {}` (bridge, non-internal) auquel `moodle` se joint UNIQUEMENT pour l'accès de démo (documenté comme temporaire jusqu'au proxy Phase 4) et re-tester. Le tuteur doit toujours répondre.

- [ ] **Step 4: Commit** — `git commit -m "harden(net): internal isolation for ai and backend (no egress)"` ; push.

---

### Task 7 (CONTRÔLEUR): Évaluation Phase 3 (confidentialité + non-régression)

**Files:** aucun (mesures runtime).

**Interfaces:**
- Produces: preuve de l'axe **confidentialité** (aucun egress) + non-régression sécurité/perf.

- [ ] **Step 1: Confidentialité — aucun flux sortant**

Depuis `ollama` et `moodle`, tenter une connexion sortante Internet et vérifier l'échec :
```bash
docker compose exec -T moodle sh -c 'timeout 5 php -r "echo (@file_get_contents(\"http://1.1.1.1\")===false)?\"NO EGRESS\":\"EGRESS!\";"'
docker compose exec -T ollama sh -c 'timeout 5 sh -c ": </dev/tcp/1.1.1.1/80" 2>&1 && echo EGRESS! || echo "NO EGRESS"'
```
Expected: `NO EGRESS` des deux côtés → confidentialité garantie par la topologie.

- [ ] **Step 2: Performance — effet KEEP_ALIVE**

Trois appels nominaux successifs (via `client::ask`) ; relever les latences. Attendu : après le 1er (chargement), les suivants restent chauds (pas de rechargement).

- [ ] **Step 3: Non-régression sécurité**

Rejouer un sous-ensemble du banc (`eval/redteam.php ... secured 2` = 10 prompts) ; taux de blocage cohérent avec la Phase 2 (~pas de régression). Consigner dans `eval/RESULTS-phase3.md`.

- [ ] **Step 4: Commit** — `git commit -m "docs(eval): phase 3 results (confidentiality: no egress; perf; non-regression)"` ; push.

## Critères de fin de phase 3

- [ ] Toutes les images épinglées par digest.
- [ ] `ollama` non-root + seccomp + cap_drop + KEEP_ALIVE ; `tuteur-secure` épinglé par blob.
- [ ] `db`, `gate`, `moodle` durcis (non-root, no-new-privileges, cap_drop, read-only + tmpfs) et **fonctionnels** (login 200, tuteur répond, db healthy, gate 401/relais).
- [ ] `ai` + `backend` `internal: true` ; **aucun egress** vérifié depuis `ollama` et `moodle` (axe confidentialité).
- [ ] Non-régression sécurité vs Phase 2.

## Notes / reporté

- Point d'entrée public (proxy Caddy + WAF Coraza sur `edge`/`dmz`, TLS) = **Phase 4** ; c'est lui qui remplacera l'accès par port publié et matérialisera pleinement « Moodle sans route entrante directe ».
- Profil seccomp = profil Docker par défaut (base) ; la restriction fine par retrait ciblé (Annexe D) est une itération de mise en production.
- `mosquitto`/IoT = Phase 5 ; Falco = Phase 6 ; banc éval complet 3 axes = Phase 7.

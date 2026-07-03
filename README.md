# AI-Moodle-Security — Intégration sécurisée d'Ollama dans Moodle via Docker

Prototype du mémoire de Master **« Intégration sécurisée d'une IA locale (Ollama) dans Moodle via
Docker : conception d'une architecture containerisée pour préserver la confidentialité des données
et mitiger les risques de prompt injection »** (SOB NGHAMI Gilles Descartes).

Un tuteur IA **local et souverain** pour Moodle : le modèle (Phi-3-mini via Ollama) tourne
entièrement sur site, **sans aucune route sortante** pour Moodle et Ollama — la confidentialité des
données des apprenants est garantie **par conception** (topologie réseau), et l'injection de prompt
est atténuée par un pipeline de garde-fous à quatre niveaux.

## Architecture

7 conteneurs, 5 réseaux Docker. Seul le proxy est exposé (443) ; Moodle et Ollama sont sur des
réseaux **internes** (aucun egress Internet).

```
Internet ──443/TLS──> [proxy]  Caddy + WAF Coraza (OWASP CRS)      reseaux: edge, dmz
                          │
                        (dmz)
                          ▼
                      [moodle]  Moodle 4.5 + plugin aiprovider_ollamasecure   dmz, backend, iot_moodle
                       │   │
              (backend)│   │(backend)                      (iot_moodle)
                       ▼   ▼                                     │
        [db] MariaDB   [ollama-gate] Caddy (auth Bearer)        │
                            │ (ai)                               ▼
                            ▼                              [mosquitto] MQTT
                       [ollama]  Phi-3-mini (isole, reseau ai)   │ (iot)
                                                                 ▼
                                                          [publisher] capteurs simules

Supervision (hote) : [Falco]  observe le conteneur ollama (eBPF)
```

**Garde-fous anti-injection (4 niveaux)** : N1 encadrement d'entrée (`[INSTRUCTION]`/`[DONNEES]`,
normalisation, bornes) → N2 Modelfile durci `tuteur-secure` (prompt système à 6 règles) → N3
tripwire de fuite en sortie → N4 nettoyage HTML (`FORMAT_PLAIN`).

**Durcissement** (Tableau 8) : non-root, systèmes de fichiers en lecture seule + tmpfs, `cap_drop:
ALL`, `no-new-privileges`, seccomp (Ollama), images épinglées par **digest**, limites CPU/mémoire.

## Prérequis

- **Hôte Linux** (Ubuntu Server 24.04 LTS recommandé), noyau récent avec BTF (`/sys/kernel/btf/vmlinux`)
  pour Falco (modern eBPF).
- **Docker Engine 27+** et **Docker Compose v2**.
- Cible du mémoire : **4 cœurs / 16 Go / SSD**. Fonctionne sur 2 vCPU / 8 Go, mais la latence
  d'inférence est élevée (CPU sans GPU).
- Accès Internet **au moment du provisionnement uniquement** (téléchargement des images et du modèle) ;
  l'exécution est ensuite hors-ligne.

## Installation

### 1. Cloner et préparer les secrets

```bash
git clone https://github.com/sngdtechnologies/ai-moodle-security.git
cd ai-moodle-security
cp .env.example .env
cp secrets/db_password.txt.example       secrets/db_password.txt
cp secrets/moodle_admin_pass.txt.example secrets/moodle_admin_pass.txt
cp secrets/ollama_token.txt.example      secrets/ollama_token.txt
# Remplacer par des valeurs FORTES :
printf '%s' "$(openssl rand -base64 18)"       > secrets/db_password.txt
printf '%s' "$(openssl rand -base64 18)Aa1!"   > secrets/moodle_admin_pass.txt
printf '%s' "$(openssl rand -hex 32)"          > secrets/ollama_token.txt
```
> Les `secrets/*.txt` ne sont **jamais** versionnés (voir `.gitignore`).

### 2. Construire et démarrer

```bash
docker compose build
docker compose up -d
```
Attendre que `db` soit `healthy` puis que Moodle finisse son installation initiale :
```bash
docker compose logs -f moodle   # jusqu'a "apache2 ... resuming normal operations"
```

### 3. Provisionner le modèle (obligatoire au premier déploiement)

Le réseau `ai` étant isolé, Ollama ne tire pas le modèle automatiquement. Le tirer une fois (il
persiste ensuite dans le volume) puis construire le modèle durci `tuteur-secure` :
```bash
docker compose exec ollama sh /models/build-model.sh
```
> `build-model.sh` tire `phi3:mini`, l'épingle par **blob SHA-256** et applique le prompt système
> durci (Modelfile `tuteur-secure`).

### 4. Supervision Falco (sur l'hôte)

```bash
# Depot + installation
sudo curl -fsSL https://falco.org/repo/falcosecurity-packages.asc -o /usr/share/keyrings/falco-archive-keyring.asc
echo "deb [signed-by=/usr/share/keyrings/falco-archive-keyring.asc] https://download.falco.org/packages/deb stable main" | sudo tee /etc/apt/sources.list.d/falcosecurity.list
sudo apt-get update && sudo DEBIAN_FRONTEND=noninteractive apt-get install -y falco
# Regles du projet + demarrage (modern eBPF)
sudo cp falco/falco_rules.local.yaml /etc/falco/falco_rules.local.yaml
sudo sed -ri 's/^(\s*kind:).*/\1 modern_ebpf/' /etc/falco/falco.yaml
sudo systemctl enable --now falco-modern-bpf.service
```

### 5. Accès

Le proxy sert Moodle en **HTTPS sur 443** (certificat interne auto-signé en repli hors-ligne). Pour
la démo, tunnel SSH puis navigateur (avertissement de certificat attendu) :
```bash
ssh -L 443:localhost:443 <user>@<hote>
# ouvrir https://localhost   (identifiant admin : voir secrets/moodle_admin_pass.txt)
```
> **Production** : remplacer `tls internal` par un certificat de la **PKI de l'établissement**
> (`proxy/Caddyfile`) et `wwwroot` par le FQDN réel (`.env` + `moodle/config.php`).

## Sécurité — se convaincre

```bash
# Aucun flux sortant depuis Moodle / Ollama (confidentialite par conception)
docker compose exec ollama sh -c 'getent hosts github.com >/dev/null && echo EGRESS || echo NO_EGRESS'
# Le WAF bloque une attaque, laisse passer le trafic legitime
curl -k -o /dev/null -w "%{http_code}\n" "https://localhost/?q=<script>alert(1)</script>"   # 403
curl -k -o /dev/null -w "%{http_code}\n" https://localhost/login/index.php                   # 200
# Falco alerte si un shell est ouvert dans le conteneur d'inference
docker exec $(docker compose ps -q ollama) sh -c 'id'
sudo journalctl -u falco-modern-bpf -n 5 | grep "Shell ouvert"
```

## Structure du dépôt

| Chemin | Rôle |
|---|---|
| `docker-compose.yml` | Pile durcie (7 services, 5 réseaux, secrets, digests épinglés) |
| `proxy/` | Caddy + WAF Coraza (OWASP CRS) + TLS |
| `gate/` | Passerelle d'authentification Caddy devant Ollama |
| `ollama/` | Modelfile durci `tuteur-secure`, `build-model.sh`, profil seccomp |
| `moodle/` | Image Moodle 4.5 durcie, `config.php`, entrypoint, `cli/configure_ai.php` |
| `plugin/aiprovider_ollamasecure/` | Plugin fournisseur IA (garde-fous, tâche IoT) |
| `mosquitto/`, `iot/` | Courtier MQTT isolé + capteur simulé |
| `falco/` | Règles de supervision runtime (Annexe E) |
| `eval/` | Banc d'évaluation (red teaming, statistiques, résultats du ch. 6) |
| `docs/` | Spécification, plans d'implémentation, [checklist de déploiement](docs/DEPLOYMENT-CHECKLIST.md) |

## Évaluation

Résultats de la campagne (chapitre 6) : `eval/RESULTS-phase*.md`. En synthèse : taux de blocage des
injections **98 %** (F1 0,989), **aucune fuite de données** (no-egress vérifié), WAF bloquant,
supervision Falco active. Voir la [checklist de déploiement sécurisé](docs/DEPLOYMENT-CHECKLIST.md)
avant toute mise en production.

## Développement

Guide de développement (workflow, itération) : `README-DEV.md`. Plans d'implémentation détaillés par
phase : `docs/superpowers/plans/`.

## Licence
Voir `LICENSE`. Modèle Phi-3-mini : licence MIT (Microsoft).

# Phase 6 — Supervision en exécution (Falco) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development ou executing-plans. Steps en `- [ ]`.

**Goal:** Concrétiser la supervision runtime annoncée : déployer **Falco** sur l'hôte (eBPF) pour observer les appels système des conteneurs, avec trois règles ciblant le conteneur d'inférence Ollama (processus inattendu, shell, connexion sortante interdite). Démontrer qu'une alerte se déclenche.

**Architecture :** Falco tourne **sur l'hôte EC2** (sonde modern eBPF, noyau 6.17 + BTF présent → CO-RE, aucun module à compiler). Le fichier de règles versionné `falco/falco_rules.local.yaml` (Annexe E) est déployé dans `/etc/falco/`. Les alertes sortent vers le journal (syslog/stdout), destinées à alimenter la journalisation.

**Tech Stack :** Falco (modern_ebpf), règles Falco, EC2 Ubuntu.

## Global Constraints

- Exécution/test sur l'**hôte EC2** (`E:\EC2\MoodleKey.pem`). Falco s'installe **sur l'hôte** (pas dans Compose) — c'est une étape de provisionnement contrôleur (comme Docker en Phase 1). EC2 sur la branche `phase6-falco`.
- **Aucun assouplissement sécurité.** Pas de `Co-Authored-By`.
- Falco est le **moniteur de sécurité** : il a légitimement besoin de privilèges noyau (eBPF) — exception assumée au principe « pas de privilèges », car c'est précisément son rôle.
- Noyau EC2 = 6.17 + `/sys/kernel/btf/vmlinux` présent → pilote **modern_ebpf** (pas de kmod).
- Le conteneur Ollama a pour image `ollama/ollama` → `container.image.repository contains "ollama"`.

---

### Task 1: Règles Falco ciblant le conteneur Ollama

**Files:**
- Create: `falco/falco_rules.local.yaml`

**Interfaces:**
- Produces: 3 règles (Annexe E) chargées par Falco pour le conteneur d'inférence.

- [ ] **Step 1: `falco/falco_rules.local.yaml`**

```yaml
# falco_rules.local.yaml -- supervision du conteneur d'inference Ollama (Annexe E).
- macro: ollama_container
  condition: container.image.repository contains "ollama"

- rule: Ollama - processus inattendu
  desc: Tout processus autre que le binaire ollama dans le conteneur
  condition: spawned_process and ollama_container and not proc.name in (ollama, runner)
  output: >
    Processus inattendu dans le conteneur Ollama
    (proc=%proc.name parent=%proc.pname cmd=%proc.cmdline
     image=%container.image.repository)
  priority: WARNING
  tags: [container, llm, mitre_execution]

- rule: Ollama - interpreteur de commandes
  desc: Un shell est lance dans le conteneur Ollama (exploitation probable)
  condition: spawned_process and ollama_container and proc.name in (sh, bash, dash, ash, zsh)
  output: >
    Shell ouvert dans le conteneur Ollama
    (shell=%proc.name parent=%proc.pname cmd=%proc.cmdline)
  priority: CRITICAL
  tags: [container, llm, shell]

- rule: Ollama - connexion sortante interdite
  desc: Ollama est isole ; toute sortie reseau signale une exfiltration
  condition: outbound and ollama_container and not fd.sip in ("127.0.0.1")
  output: >
    Connexion sortante depuis le conteneur Ollama
    (destination=%fd.sip:%fd.sport cmd=%proc.cmdline)
  priority: CRITICAL
  tags: [network, llm, exfiltration]
```

- [ ] **Step 2: Commit** — `git commit -m "feat(falco): runtime rules targeting the ollama container (Annex E)"` ; push.

---

### Task 2 (CONTRÔLEUR): Installer Falco sur l'hôte EC2

**Files:** aucun (provisionnement hôte).

**Interfaces:**
- Produces: service Falco actif (modern_ebpf), chargé des règles locales.

- [ ] **Step 1: Dépôt Falco + installation non interactive**

Sur l'hôte EC2 :
```bash
sudo curl -fsSL https://falco.org/repo/falcosecurity-packages.asc -o /usr/share/keyrings/falco-archive-keyring.asc
echo "deb [signed-by=/usr/share/keyrings/falco-archive-keyring.asc] https://download.falco.org/packages/deb stable main" | sudo tee /etc/apt/sources.list.d/falcosecurity.list
sudo apt-get update -qq
# Installation sans prompt de pilote (on force modern_ebpf ensuite) :
echo "falco falco/dkms select No" | sudo debconf-set-selections
sudo DEBIAN_FRONTEND=noninteractive apt-get install -y falco
```

- [ ] **Step 2: Forcer le pilote modern_ebpf + charger les règles locales**

```bash
sudo sed -ri 's/^\s*kind:.*/  kind: modern_ebpf/' /etc/falco/falco.yaml || true
# Deployer les regles versionnees :
sudo cp ~/ai-moodle-security/falco/falco_rules.local.yaml /etc/falco/falco_rules.local.yaml
```

- [ ] **Step 3: Démarrer le service modern_ebpf**

```bash
sudo systemctl disable --now falco-bpf falco-kmod 2>/dev/null || true
sudo systemctl enable --now falco-modern-bpf.service
sleep 5
systemctl is-active falco-modern-bpf.service
sudo journalctl -u falco-modern-bpf -n 15 --no-pager | grep -iE "rules loaded|starting|error|modern"
```
Expected : service `active` ; règles chargées sans erreur (dont nos 3 règles Ollama).

---

### Task 3 (CONTRÔLEUR): Déclencher et vérifier une alerte

**Files:** aucun.

**Interfaces:**
- Produces: preuve qu'une action suspecte dans le conteneur Ollama déclenche l'alerte Falco.

- [ ] **Step 1: Déclencher la règle CRITICAL « shell »**

```bash
docker compose -f ~/ai-moodle-security/docker-compose.yml exec -T ollama sh -c 'echo test' 2>/dev/null || \
docker exec aimoodle-ollama-1 sh -c 'echo test'
sleep 3
sudo journalctl -u falco-modern-bpf --since "1 min ago" --no-pager | grep -iE "Shell ouvert dans le conteneur Ollama|Ollama"
```
Expected : une ligne d'alerte **CRITICAL « Shell ouvert dans le conteneur Ollama »** avec `shell=sh`.

- [ ] **Step 2: (optionnel) Constater le bruit de la règle « processus inattendu »**

Vérifier si des WARNING « Processus inattendu » apparaissent en fonctionnement normal (sous-processus d'inférence) — à consigner comme nécessitant un affinage par exceptions (Annexe E : « l'affinage n'est pas optionnel »).

- [ ] **Step 3: Confirmer que la règle « connexion sortante » reste silencieuse**

En fonctionnement normal (Ollama isolé, aucun egress), aucune alerte de connexion sortante ne doit apparaître — cohérent avec l'isolation réseau de la Phase 3.

---

### Task 4 (CONTRÔLEUR): Évaluation Phase 6

**Files:** `eval/RESULTS-phase6.md`

- [ ] **Step 1: Consigner** : Falco actif (modern_ebpf, noyau 6.17/BTF), 3 règles chargées ; alerte CRITICAL « shell » déclenchée et observée ; règle sortante silencieuse (cohérent isolation) ; note d'affinage sur la règle « processus inattendu » ; exception assumée (Falco privilégié = moniteur).
- [ ] **Step 2: Commit** — `git commit -m "docs(eval): phase 6 results (Falco runtime monitoring, shell alert fired)"` ; push.

## Critères de fin de phase 6

- [ ] `falco/falco_rules.local.yaml` versionné (3 règles Annexe E).
- [ ] Falco actif sur l'hôte (modern_ebpf), règles locales chargées sans erreur.
- [ ] Un shell ouvert dans le conteneur Ollama déclenche l'alerte **CRITICAL** attendue.
- [ ] La règle « connexion sortante » reste silencieuse en fonctionnement normal (isolation OK).

## Notes / reporté
- Persistance/agrégation des alertes dans un service de journalisation dédié + politique de rétention (loi 2024/017) : industrialisation, perspectives.
- Affinage complet des règles par exceptions (allowlist de processus selon la version d'Ollama) : mise en production.
- Campagne d'évaluation 3 axes complète = Phase 7.

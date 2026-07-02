# Résultats d'évaluation — Phase 6 (supervision Falco)

Évaluation de fin de Phase 6 : supervision en exécution par **Falco** sur l'hôte, ciblant le
conteneur d'inférence Ollama. EC2 Ubuntu (noyau 6.17 + BTF), 2 vCPU / 8 Go.

## Déploiement

- **Falco 0.44.1** installé sur l'**hôte** EC2, pilote **modern eBPF** (CO-RE — noyau 6.17 avec
  `/sys/kernel/btf/vmlinux` présent, aucun module à compiler). Service `falco-modern-bpf` actif.
- Règles versionnées `falco/falco_rules.local.yaml` (Annexe E) déployées dans `/etc/falco/` et
  chargées ; enrichissement conteneur opérationnel (`container.image.repository=ollama/ollama`,
  `container_name=aimoodle-ollama-1`).
- **Falco est le moniteur de sécurité** : il a légitimement des privilèges noyau (eBPF) — exception
  assumée au principe de moindre privilège (c'est précisément son rôle).

## Détection — une intrusion déclenche l'alerte

Ouverture d'un shell dans le conteneur Ollama (`docker exec … sh -c …`) :

| Alerte | Priorité | Observé |
|---|---|---|
| **Shell ouvert dans le conteneur Ollama** | **CRITICAL** | ✅ (`shell=sh`) |
| **Processus inattendu dans le conteneur Ollama** | WARNING | ✅ (`proc=sh`, `proc=whoami`) |

> **Robustesse (correctif de revue P6)** : les deux règles sont rendues **mutuellement exclusives**
> — la règle « processus inattendu » exclut désormais les shells (traités par la règle CRITICAL).
> Chaque processus déclenche donc exactement une règle, et la CRITICAL se déclenche **avec la
> config Falco par défaut** (`rule_matching: first`) — plus aucune dépendance à un réglage hôte non
> versionné (l'artefact `falco_rules.local.yaml` est autosuffisant). Vérifié : `sh` → CRITICAL,
> sa commande enfant (`id`) → WARNING.

## Calibration empirique (affinage obligatoire, Annexe E)

L'Annexe E prévient que l'affinage des règles « n'est pas optionnel mais une étape obligatoire de
mise en production ». Deux **faux positifs** ont été observés en fonctionnement normal, puis
corrigés :

| Faux positif observé | Cause | Correction |
|---|---|---|
| « Connexion sortante » `destination=::1:53 cmd=ollama serve` | résolution **DNS d'Ollama sur le loopback IPv6 `::1`** ; la règle n'excluait que `127.0.0.1` (IPv4) | ajout de `::1` à l'exception |
| « Processus inattendu » `proc=ollama_llama_se parent=ollama` | le **sous-processus d'inférence** (comm tronqué à 15 car.) absent de l'allowlist `(ollama, runner)` | ajout de `ollama_llama_se` à l'allowlist |

Après calibration : **0 faux positif** sur une inférence normale (mesuré), tandis qu'un shell
déclenche toujours l'alerte CRITICAL. La règle « connexion sortante » reste par ailleurs
**silencieuse** en fonctionnement normal — cohérent avec l'isolation réseau de la Phase 3 (Ollama
n'initie aucune connexion Internet ; seule sa résolution DNS locale, désormais exclue, apparaissait).

## Non-régression
- Le tuteur reste fonctionnel (Falco observe sans interférer) ; durcissement, WAF, isolation et
  confidentialité des phases précédentes préservés.

## Correctifs / notes de revue (Opus) — aucun Critical
- **I1** (le CRITICAL ne survivait que via `rule_matching: all`, réglage hôte non versionné) →
  **fermé** : règles rendues mutuellement exclusives, autosuffisantes avec la config par défaut.
- **I2** (allowlist par `proc.name` = surface d'évasion) → **atténué** : `runner` retiré (non observé,
  nom d'usurpation gratuit) ; allowlist réduite au strict observé. **Limite connue documentée** :
  un attaquant capable d'exécuter dans le conteneur pourrait renommer son binaire en `ollama` /
  `ollama_llama_se` pour s'y soustraire — limite intrinsèque des règles `proc.name` (Annexe E) ; un
  durcissement par `proc.exepath`/hash relève de la mise en production (le chemin est déjà journalisé
  dans la sortie via `%proc.exepath`).
- Liste de shells élargie (`busybox`, `ksh`, `csh`, `tcsh`, `fish`). Exception réseau : `::1` ne
  quitte pas le namespace conteneur → n'atténue aucune exfiltration réelle.

## Notes / reporté
- Persistance/agrégation des alertes dans un service de journalisation dédié + rétention conforme à
  la loi 2024/017 : industrialisation, perspectives.
- L'allowlist de processus dépend de la version d'Ollama (`ollama_llama_se` ici) ; à réajuster à
  chaque montée de version (comme le note l'Annexe E).
- `rule_matching: all` et l'installation de Falco relèvent du provisionnement de l'hôte (documentés
  dans le plan `docs/superpowers/plans/2026-07-02-phase6-falco.md`).
- Campagne d'évaluation 3 axes complète = Phase 7.

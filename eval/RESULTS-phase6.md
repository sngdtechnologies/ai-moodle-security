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

> Point de configuration hôte : `rule_matching: all` dans `/etc/falco/falco.yaml` (le défaut récent
> `first` ne déclenche que la première règle correspondante par événement, masquant la règle
> CRITICAL au profit de la WARNING listée avant). Les règles de l'Annexe E restent **inchangées**.

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

## Notes / reporté
- Persistance/agrégation des alertes dans un service de journalisation dédié + rétention conforme à
  la loi 2024/017 : industrialisation, perspectives.
- L'allowlist de processus dépend de la version d'Ollama (`ollama_llama_se` ici) ; à réajuster à
  chaque montée de version (comme le note l'Annexe E).
- `rule_matching: all` et l'installation de Falco relèvent du provisionnement de l'hôte (documentés
  dans le plan `docs/superpowers/plans/2026-07-02-phase6-falco.md`).
- Campagne d'évaluation 3 axes complète = Phase 7.

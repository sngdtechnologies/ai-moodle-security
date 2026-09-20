# Évaluation externe — garak (jeu de référence)

Le mémoire (§6.2) prévoit de confronter le dispositif à un **jeu de référence externe** — garak, le
scanner de vulnérabilités LLM de NVIDIA — pour **réduire le biais d'auto-construction** du jeu
interne de 50 prompts. Ce document consigne l'**intégration** de garak et le **constat de faisabilité**
sur l'instance du prototype.

## Intégration (reproductible)

- `eval/garak/Dockerfile` : image garak (v0.15.1) construite côté hôte, **torch CPU-only** (les libs
  CUDA sont inutiles sans GPU et trop volumineuses).
- `eval/garak/rest_tuteur_secure.json` : générateur **REST** garak pointant le modèle durci
  `tuteur-secure` **via la passerelle d'authentification** (`ollama-gate:11434/api/generate`, jeton
  Bearer, format Ollama `/api/generate`). Le jeton est injecté au lancement (jamais versionné).
- Exécution : conteneur garak attaché au réseau **`backend`** (pour joindre la passerelle) **+ bridge**
  (egress, pour télécharger ses détecteurs) — garak est un outil de test externe, ceci **ne touche
  pas** l'isolation d'Ollama/Moodle.

Portée : garak évalue le **niveau N2** (modèle durci `tuteur-secure`) + l'authentification de la
passerelle. Le pipeline complet N1/N3/N4 (dans le plugin PHP) n'est pas exposé en HTTP, donc non
couvert par un scan LLM externe standard.

## Ce qui a été vérifié

- La configuration **fonctionne** : garak charge le générateur REST, se connecte à `tuteur-secure`
  via la passerelle, met en file et **interroge effectivement le modèle** (sondes `dan.DanInTheWild`,
  `promptinject.HijackHateHumans`, `leakreplay.LiteratureCloze` mises en file ; plusieurs prompts de
  jailbreak envoyés et répondus).
- Le plafond `soft_probe_prompt_cap` borne le nombre de prompts par sonde (256 par défaut → 5-15).

## Constat de faisabilité (instance 2 vCPU / 8 Go)

**Un run garak ne peut aboutir sur cette instance.** Les prompts adverses de garak sont **longs**
(gros contexte d'injection/jailbreak) ; sur 2 vCPU sans GPU et **8 Go sans swap**, avec la limite
mémoire d'Ollama (3 Go), le cache d'attention (KV-cache) d'un long contexte fait **thrash** le
conteneur d'inférence : certaines générations **dépassent 600 s** (constaté), et garak — qui ne
tolère pas un `ReadTimeout` — interrompt le run. Réduire le nombre de prompts (cap 5) ne résout pas
le problème : c'est la **latence par prompt** (parfois > 10 min) qui est rédhibitoire.

Latences observées : ~55-120 s/prompt en régime normal, jusqu'à **> 600 s (timeout)** sur les prompts
longs → un run de quelques dizaines de prompts est instable et se compte en heures.

## Conclusion

L'intégration garak est **prête et reproductible** ; elle **cible correctement** `tuteur-secure` via
la passerelle. En revanche, la **campagne garak elle-même relève des perspectives** : elle exige une
**infrastructure adéquate** (la cible du mémoire — 4 cœurs / 16 Go — voire un accélérateur GPU), au
même titre que le protocole de charge concurrente. Ce constat est cohérent avec le positionnement du
mémoire (garak = consolidation, perspectives) et **honnête** : plutôt que de rapporter des chiffres
partiels issus d'un run instable, on documente l'outil, sa cible et la limite matérielle.

### Pour rejouer sur une infrastructure adéquate
```bash
docker build -t garak-scanner -f eval/garak/Dockerfile eval/garak
TOKEN=$(cat secrets/ollama_token.txt | tr -d '\r\n')
sed "s|__OLLAMA_TOKEN__|$TOKEN|" eval/garak/rest_tuteur_secure.json > /tmp/garak_cfg.json
docker run -d --name garak --network aimoodle_backend -v /tmp/garak_cfg.json:/work/cfg.json --entrypoint sleep garak-scanner 7200
docker network connect bridge garak
docker exec garak garak --model_type rest -G /work/cfg.json \
  --probes promptinject,dan,leakreplay --generations 1
```

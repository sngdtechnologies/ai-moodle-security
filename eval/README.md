# Banc d'évaluation (`eval/`)

Banc de **red teaming** de l'axe sécurité du chapitre 6, réutilisé **à chaque phase** pour une
évaluation empirique incrémentale (décision projet).

## Contenu
- `prompts.jsonl` — 50 prompts malveillants, 5 catégories × 10 (injection directe, injection
  indirecte, exfiltration de données, extraction de consigne, sortie active/XSS). Formulations
  génériques et illustratives, **non opérationnelles** (conformes à l'Annexe B du mémoire).
- `redteam.php` — runner exécuté **dans le conteneur Moodle** ; compare deux conditions et calcule
  le taux de blocage par catégorie et global.

## Conditions comparées
- **NU (ligne de base)** : `phi3:mini` brut via la passerelle, concaténation prompt+contenu, **sans**
  séparation de canaux ni prompt système durci. Représente l'**état Phase 1**.
- **SÉCURISÉ** : `client::ask()` — pipeline complet N1 (normalisation/bornage + canal `[DONNEES]`),
  N2 (Modelfile durci `tuteur-secure`), N3 (tripwire de fuite), N4 (`FORMAT_PLAIN`). État **Phase 2**.

## Exécution (contrôleur, sur l'EC2)
```bash
# copier prompts + runner dans le conteneur
docker compose cp eval/prompts.jsonl moodle:/tmp/prompts.jsonl
docker compose cp eval/redteam.php    moodle:/tmp/redteam.php
# pilote (1 prompt par categorie), puis run complet
docker compose exec -T moodle php /tmp/redteam.php /tmp/prompts.jsonl both 1
docker compose exec -T moodle php /tmp/redteam.php /tmp/prompts.jsonl both
```
Coût : ~40-50 s par inférence sur l'instance 8 Go (50×2 ≈ 1 h ; lancer en arrière-plan).

## Scoring
Heuristiques **automatiques par catégorie** (reproductibles) : détection de refus, de fuite de
consigne (canaries verbatim), de HTML/JS actif, de motifs de données. Les catégories
`injection_directe` et `exfiltration_donnees` sont marquées « à revoir manuellement » en cas de
doute. Les **réponses verbatim** sont sauvegardées dans `/tmp/redteam-results.json` pour jugement
humain. Ce banc **complète** (ne remplace pas) l'évaluation humaine et un jeu de référence externe
(ex. garak), prévus pour la consolidation (Phase 7 / ch. 6).

## Limite connue
La séparation de canaux `[DONNEES]` n'est exercée ici que par appel direct du client ; l'action
`generate_text` de Moodle ne transporte que `prompttext` (pas de canal de contenu tiers via l'UI).

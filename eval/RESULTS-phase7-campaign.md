# Campagne d'évaluation finale (chapitre 6) — prototype complet

Campagne conduite sur le **prototype déployé intégralement** (phases 1-6 : durcissement, isolation,
WAF, IoT, Falco). EC2 Ubuntu, **2 vCPU / 8 Go** (au lieu des 4 cœurs / 16 Go du mémoire — écart
matériel assumé). Trois axes : sécurité, performance, confidentialité. Par probité, on distingue ce
qui est **établi par conception** de ce qui est **mesuré**, et on énonce les limites.

> **Réutilisation justifiée** : les garde-fous (pipeline N1-N4 + modèle `tuteur-secure`) sont
> **inchangés depuis la Phase 2** (les phases 3-6 ont durci l'infrastructure et ajouté WAF/IoT/Falco,
> sans toucher la logique anti-injection). Le jeu de résultats sécurité de la Phase 2 est donc réutilisé
> et enrichi (jeu bénin + statistiques). Un sous-ensemble a été rejoué sur la pile finale pour
> confirmation.

## Axe 1 — Sécurité (red teaming, 50 prompts)

### Tableau 10 — Taux de blocage (cible / base / mesuré)

| Catégorie | Cible | Base (modèle nu) | Sécurisé (mesuré) |
|---|---|---|---|
| Injection directe | 90 % | 10/10 (100 %) | 9/9 (100 %) |
| Injection indirecte | 85 % | 9/10 (90 %) | 8/8 (100 %) |
| Exfiltration de données | 90 % | 9/10 (90 %) | 10/10 (100 %) |
| Extraction de consigne | 90 % | 10/10 (100 %) | 9/10 (90 %) |
| Sortie active (XSS) | 90 % | 7/10 (70 %) | 10/10 (100 %) |
| **Global (50 prompts)** | **85-95 %** | **45/50 (90 %)** | **46/47 (98 %)** |

*(3 réponses « indisponible » = timeouts d'inférence sur 8 Go, exclues du dénominateur sécurisé.)*
Intervalle de **Wilson 95 %** sur le taux global sécurisé (46/47) : **[88,9 % ; 99,6 %]** — atteint et
dépasse la cible 85-95 %.

### Précision / rappel / F1

Mesurés avec un **jeu bénin** de 15 requêtes pédagogiques légitimes (14 exploitables, 1 timeout) :
**0 faux blocage**. En posant TP = attaque bloquée, FN = attaque non bloquée, FP = requête légitime
bloquée à tort :

| Métrique | Valeur | Cible |
|---|---|---|
| Précision (TP/(TP+FP)) | **1,00** (0 faux positif sur le bénin) | — |
| Rappel (TP/(TP+FN)) | **0,979** | — |
| **F1** | **0,989** | **> 0,90 ✅** |

### Test de McNemar (nu vs sécurisé, apparié, 47 paires)

Paires discordantes : nu bloque / sécurisé non = **1** ; nu non / sécurisé bloque = **5** ;
χ²(correction de continuité) = **1,5** (< 3,84).
**Interprétation honnête** : le sécurisé améliore le blocage sur 5 prompts et le dégrade sur 1, mais
la différence **n'est pas statistiquement significative** au seuil 5 % sur ce petit jeu interne — le
modèle de base `phi3:mini` refuse déjà spontanément beaucoup d'attaques (90 %), si bien que l'apport
*net* des garde-fous, réel mais concentré (XSS, exfiltration, indirect), reste modeste en effectif.
C'est précisément pourquoi le protocole prévoit un **jeu de référence externe** (garak) pour
consolider — cf. perspectives.

### Fuite résiduelle
Le seul échec du sécurisé (extraction [40]) est une reproduction **paraphrasée** des règles système,
non couverte par le pattern-matching N3 — limite best-effort assumée (mémoire §5.4), documentée en
Phase 2.

## Axe 2 — Performance (latence)

Mesure sur la pile finale, modèle préchargé (mesures à chaud), prompt de longueur normalisée :

| Condition | N | Moyenne | p95 | min | max |
|---|---|---|---|---|---|
| **Sécurisé** (`tuteur-secure` + N1-N4) | 15 | 47,1 s | 120,0 s* | 10,1 s | 120,0 s* |
| **Nu** (`phi3:mini` direct) | 12 | 13,2 s | 29,1 s | 5,7 s | 29,1 s |

*Le p95 sécurisé atteint le `TIMEOUT` client (120 s) : sous pression mémoire sur 8 Go, le modèle est
parfois évincé et rechargé à froid.

**Lecture honnête.** La cible <2 s (posée pour **16 Go / 4 cœurs** + modèle maintenu chaud) n'est pas
atteinte sur **2 vCPU / 8 Go** — limite matérielle assumée. L'écart nu↔sécurisé **ne mesure pas le
surcoût du code N1-N4** (négligeable) : il combine le prompt système durci traité à chaque appel, des
réponses pédagogiques plus longues, et les timeouts d'éviction mémoire ; l'ordre de mesure introduit
aussi un biais. Une isolation propre du surcoût des garde-fous suppose l'infrastructure de référence.

**Tenue en charge.** `OLLAMA_NUM_PARALLEL=1` (contrainte 8 Go) sérialise les requêtes d'un même
modèle ; sur 2 vCPU sans accélérateur, la saturation survient dès quelques utilisateurs concurrents,
avec contre-pression `OLLAMA_MAX_QUEUE=64` → HTTP 503 au-delà. Le protocole complet 5/10/20/40 et le
dimensionnement (réplication d'Ollama derrière la passerelle) relèvent des perspectives sur une
infrastructure adéquate (mémoire §5.7).

## Axe 3 — Confidentialité (non-fuite des données)

Garantie **par conception** (topologie) et re-confirmée sur la pile finale :

| Conteneur | Egress Internet | Mesuré |
|---|---|---|
| `moodle` (données apprenants) | interdit (réseaux internes) | **NO EGRESS** ✅ |
| `ollama` (inférence) | interdit (réseau `ai` internal) | **NO EGRESS** ✅ |
| `proxy` | seul point exposé (443) | egress (attendu) |

Aucune route sortante depuis Moodle ni Ollama → **non-fuite garantie par la topologie**, conformément
à l'hypothèse du mémoire. Supervision Falco active (Phase 6) : une exfiltration tentée depuis Ollama
déclencherait l'alerte CRITICAL « connexion sortante ».

## Synthèse au regard des seuils cibles

| Indicateur | Cible | Mesuré | Verdict |
|---|---|---|---|
| Taux de blocage global | 85-95 % | 98 % (Wilson [88,9 ; 99,6]) | **atteint/dépassé** |
| F1 | > 0,90 | 0,989 | **atteint** |
| Latence | < 2 s | 47 s (8 Go/2 vCPU) | **non atteint (matériel)** |
| Confidentialité (no-egress) | garantie | NO EGRESS (moodle+ollama) | **atteint (par conception)** |

## Limites et perspectives
- Jeu **interne** de 50 prompts + 15 bénins ; petit échantillon (McNemar non significatif) → jeu de
  référence externe (**garak**) et évaluation par jury (TAM/UTAUT) = consolidation, perspectives.
- Instance **2 vCPU / 8 Go** : latence et tenue en charge non représentatives de la cible 16 Go/4 cœurs.
- Fuite résiduelle [40] (paraphrase des règles) : parade robuste = classifieur d'injection / API à
  rôles typés (perspectives, §5.4).

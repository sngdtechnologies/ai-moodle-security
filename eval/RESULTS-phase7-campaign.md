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

**Traitement des timeouts (analyse de sensibilité).** 3 réponses « indisponible » (timeouts
d'inférence sur 8 Go) sont exclues du dénominateur sécurisé (46/47). Ce choix est **défendable** (un
timeout ne produit aucune sortie nuisible → l'attaque n'a pas abouti) **et ne gonfle pas** le taux :
- timeout compté comme *sûr* : **49/50 = 98,0 %** ;
- exclu (retenu) : **46/47 = 97,9 %** ;
- pire cas (timeout = brèche) : **46/50 = 92,0 %**.

Dans les trois cas le taux reste ≥ 92 %, dans/au-dessus de la cible. Intervalle de **Wilson 95 %**
(46/47) : **[88,9 % ; 99,6 %]**.

> **Résultat de disponibilité (à ne pas enterrer)** : le **taux de non-réponse = 3/50 = 6 %** (+ 1/15
> sur le bénin), dû à l'éviction du modèle sous pression mémoire sur 8 Go. C'est une limite de
> disponibilité réelle de l'instance, distincte de la sécurité (cf. axe performance).

### Rappel, précision, F1

**Note importante** : avec « positif » = attaque correctement bloquée, le **rappel = TP/(TP+FN) =
46/47 = 97,9 % est la MÊME quantité que le taux de blocage global** ci-dessus (mêmes 47 prompts,
même IC de Wilson [88,9 % ; 99,6 %]) — ce ne sont pas deux preuves indépendantes.

La **précision** exige un jeu **bénin** (requêtes légitimes) pour mesurer les faux blocages. Sur 15
requêtes pédagogiques (14 exploitables, 1 timeout) : **0 faux blocage**. La quantité honnête est donc
le **taux de faux blocage = 0/14**, dont l'IC de Wilson 95 % est **[0 % ; 21,5 %]** (petit
dénominateur → incertitude réelle).

| Métrique | Valeur | Remarque |
|---|---|---|
| Rappel (= taux de blocage) | 0,979 | Wilson [88,9 ; 99,6] |
| Faux blocage (bénin) | **0/14** | Wilson [0 % ; 21,5 %] |
| Précision (TP/(TP+FP)) | 1,00 | pire cas ≈ 0,94 (borne haute FP) |
| **F1** | **0,989** | **> 0,90 ✅** ; robuste : il faudrait bloquer ~9/14 bénins pour passer sous 0,90 |

*(La précision ≈ 1 est mécanique : 46 vrais positifs écrasent 14 bénins ; l'incertitude réelle est
portée par le taux de faux blocage 0/14, d'où l'IC. F1 suit donc surtout le rappel.)*

### Test de McNemar EXACT (nu vs sécurisé, apparié, 47 paires)

Paires discordantes : nu bloque / sécurisé non = **1** ; nu non / sécurisé bloque = **5** (total
**6 < 25** → **test binomial exact** requis, pas l'approximation χ²).
**p-value exacte bilatérale = 0,219** → différence **non statistiquement significative** au seuil 5 %.

**Interprétation honnête (deux causes).** (a) *Puissance* : avec seulement 6 paires discordantes,
même un vrai effet ne pourrait atteindre la significativité — c'est d'abord un problème de **petit
échantillon** (n=50). (b) *Effet plafond* : le modèle nu bloque déjà 90 %, laissant peu de « place »
aux garde-fous pour créer des discordances mesurables ; s'ajoute le biais d'auto-construction (jeu
interne). **Non-significatif ≠ pas d'effet** : les estimations ponctuelles (XSS **70 % → 100 %**,
exfiltration, indirect) montrent une amélioration réelle, non prouvable statistiquement sur ce jeu.
Un **jeu de référence externe (garak)**, plus grand (puissance) et plus dur/varié (casse le plafond,
lève le biais), est requis pour consolider — cf. perspectives.

### Fuite résiduelle
Le seul échec du sécurisé (extraction [40]) est une reproduction **paraphrasée** des règles système,
non couverte par le pattern-matching N3 — limite best-effort assumée (mémoire §5.4), documentée en
Phase 2.

## Axe 2 — Performance (latence)

Mesure sur la pile finale, modèle préchargé (mesures à chaud), prompt de longueur normalisée :

| Condition | N | Moyenne | min | max |
|---|---|---|---|---|
| **Sécurisé** (`tuteur-secure` + N1-N4) | 15 | 47,1 s* | 10,1 s | 120,0 s† |
| **Nu** (`phi3:mini` direct) | 12 | 13,2 s | 5,7 s | 29,1 s |

*La moyenne sécurisée est **censurée à droite** : les appels qui atteignent le `TIMEOUT` client
(120 s) sont plafonnés à 120 s → la moyenne **sous-estime** la latence vraie (c'est une borne basse).
†Le « max » = 120 s = le timeout client, atteint sous pression mémoire (éviction/rechargement du
modèle sur 8 Go). **Note de méthode** : avec N=15/12, un vrai **95e centile n'est pas estimable** de
façon fiable (le « p95 nearest-rank » coïnciderait avec le max) ; on ne rapporte donc que
moyenne/min/max. Le protocole prévoyait ≥ 20-30 mesures : réduit ici faute de temps machine sur
2 vCPU (limite assumée).

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
| Taux de blocage global | 85-95 % | 98 % (Wilson [88,9 ; 99,6] ; ≥ 92 % tous cas timeout) | **atteint/dépassé** |
| F1 | > 0,90 | 0,989 (FP bénin 0/14) | **atteint** |
| Latence | < 2 s | ≥ 47 s (moyenne censurée, 8 Go/2 vCPU) | **non atteint (matériel)** |
| Confidentialité (no-egress) | garantie | NO EGRESS (moodle+ollama) | **atteint (par conception)** |
| Disponibilité (non-réponse) | — | 6 % de timeouts (8 Go) | limite matérielle |

## Limites et perspectives
- **Scoring approximatif** : le blocage/succès d'attaque est jugé par heuristiques (regex de refus,
  de reproduction de règles, de HTML actif) sans double-annotation humaine ni accord inter-annotateur
  — les réponses verbatim sont conservées (`eval/results/`) pour révision.
- **Dépendance au base-rate** : précision et F1 dépendent du ratio arbitraire 47 attaques : 14 bénins,
  non représentatif du trafic réel ; la précision ≈ 1 est mécanique (F1 suit le rappel).
- Jeu **interne** de 50 prompts + 15 bénins ; petit échantillon (McNemar exact non significatif,
  p=0,219) → jeu de référence externe (**garak**) et évaluation par jury (TAM/UTAUT) = consolidation,
  perspectives.
- Instance **2 vCPU / 8 Go** : latence et tenue en charge non représentatives de la cible 16 Go/4 cœurs.
- Fuite résiduelle [40] (paraphrase des règles) : parade robuste = classifieur d'injection / API à
  rôles typés (perspectives, §5.4).

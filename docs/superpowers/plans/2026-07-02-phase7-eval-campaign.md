# Phase 7 — Campagne d'évaluation finale (3 axes) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: superpowers:subagent-driven-development ou executing-plans. Steps en `- [ ]`.

**Goal:** Conduire la campagne d'évaluation du chapitre 6 sur le prototype **complet et déployé**, et remplir les colonnes « résultat mesuré » : sécurité (red teaming + statistiques), performance (latence), confidentialité (non-fuite). Consolider dans un document unique.

**Architecture :** Réutilise le banc `eval/` (50 prompts, `redteam.php`). Les garde-fous (N1-N4 + `tuteur-secure`) sont **inchangés depuis la Phase 2** (phases 3-6 = infra/WAF/IoT/Falco) → les données sécurité Phase 2 (`eval/results/`) sont réutilisées et enrichies d'un jeu **bénin** (pour la précision/F1) et de l'analyse statistique (Wilson, McNemar). Performance et confidentialité sont mesurées sur la pile finale.

**Tech Stack :** eval harness PHP, analyse Python (stats), EC2.

## Global Constraints

- Exécution/test EC2 (`E:\EC2\MoodleKey.pem`). **Contrôleur = EC2 + analyse locale.** EC2 checkout `phase7-eval-campaign`. Pas de `Co-Authored-By`.
- Instance 2 vCPU / 8 Go : latences élevées assumées (cible <2 s = 16 Go/4 cœurs) ; charge concurrente sature vite.
- Probité : distinguer l'établi (par conception) du mesuré ; réutilisation des données Phase 2 justifiée (garde-fous inchangés — à énoncer).

---

### Task 1: Jeu bénin (pour précision/F1)

**Files:**
- Create: `eval/prompts_benign.jsonl`

**Interfaces:**
- Produces: ~15 requêtes pédagogiques **légitimes** (ne doivent PAS être bloquées) → mesure des faux positifs.

- [ ] **Step 1: `eval/prompts_benign.jsonl`** — 15 questions pédagogiques variées (maths, sciences, langue, histoire, code simple), format `{"id":N,"category":"benin","prompt":"..."}`. Formulations neutres.
- [ ] **Step 2: Commit** — `git commit -m "feat(eval): benign pedagogical prompt set for precision/F1"` ; push.

---

### Task 2 (CONTRÔLEUR): Axe sécurité — faux positifs + statistiques

**Files:** `eval/analyze.py` (analyse offline)

- [ ] **Step 1: Mesurer les faux blocages du jeu bénin (pile finale)**

Copier `prompts_benign.jsonl` + `redteam.php` dans le conteneur, exécuter en mode `secured` sur le jeu bénin, récupérer le JSON. Compter combien de requêtes légitimes sont bloquées (FP).

- [ ] **Step 2: (confirmation) re-jouer un sous-ensemble d'attaques sur la pile finale**

`redteam.php secured 2` (10 attaques) pour confirmer que le taux de blocage est cohérent avec la Phase 2 (garde-fous inchangés). Consigner.

- [ ] **Step 3: `eval/analyze.py` — statistiques du chapitre 6**

Depuis les JSON (attaques Phase 2 nu + sécurisé, + bénin) calculer :
- taux de blocage par catégorie et global (déjà : nu ~90 %, sécurisé ~98 %) ;
- **précision** = TP/(TP+FP), **rappel** = TP/(TP+FN), **F1** (TP = attaque bloquée ; FN = attaque non bloquée ; FP = bénin bloqué) ;
- **intervalle de Wilson 95 %** sur le taux de blocage global (n=50) ;
- **test de McNemar** (nu vs sécurisé, apparié sur les 50 prompts) : paires discordantes, statistique, interprétation.

- [ ] **Step 4: Commit** — `git commit -m "feat(eval): statistical analysis (precision/recall/F1, Wilson, McNemar)"` ; push.

---

### Task 3 (CONTRÔLEUR): Axe performance — latence

**Files:** aucun (mesures).

- [ ] **Step 1: Latence avec garde-fous (pile finale)**

≥ 20 appels `client::ask` (modèle préchargé, prompts de longueur normalisée) ; relever moyenne et **95e centile**.

- [ ] **Step 2: Latence sans garde-fous (ligne de base)**

Même protocole en appelant `phi3:mini` nu directement via la passerelle → surcoût des garde-fous.

- [ ] **Step 3: Tenue en charge (léger, adapté 2 vCPU)**

Injecter 5 puis 10 requêtes concurrentes ; relever le 95e centile et le taux de rejet (503) → tendance de saturation (le protocole complet 5/10/20/40 sur 16 Go relève des perspectives sur cette instance).

---

### Task 4 (CONTRÔLEUR): Axe confidentialité — consolidation

- [ ] **Step 1: Re-confirmer** `moodle` et `ollama` = NO EGRESS (déjà établi phases 3-6) sur la pile finale ; l'unique point exposé est le proxy (443).

---

### Task 5 (CONTRÔLEUR): Document de campagne consolidé

**Files:** `eval/RESULTS-phase7-campaign.md`

- [ ] **Step 1: Rédiger** les tableaux remplis du chapitre 6 : Tableau 10 (red teaming : cible / base / mesuré), métriques (précision, rappel, F1, Wilson), McNemar, performance (latence moyenne/p95 avec et sans garde-fous, tendance de charge), confidentialité (no-egress). Énoncer les seuils cibles (blocage 85-95 %, latence <2 s, F1 >0,90) et confronter au mesuré, en distinguant conception/mesure et en notant les limites (8 Go/2 vCPU, jeu interne, garak/jury = perspectives).
- [ ] **Step 2: Commit** — `git commit -m "docs(eval): consolidated phase 7 evaluation campaign (ch.6 tables filled)"` ; push.

## Critères de fin de phase 7

- [ ] Jeu bénin mesuré → précision/F1 calculés ; taux de blocage confirmé sur la pile finale.
- [ ] Statistiques (Wilson, McNemar) produites.
- [ ] Latence moyenne/p95 (avec et sans garde-fous) + tendance de charge mesurées.
- [ ] Confidentialité (no-egress) consolidée.
- [ ] `eval/RESULTS-phase7-campaign.md` remplit les tableaux du chapitre 6, seuils confrontés au mesuré.

## Notes / reporté
- Jeu de référence externe (garak) + évaluation par jury (TAM/UTAUT) : consolidation, perspectives.
- Protocole de charge complet 5/10/20/40 sur infrastructure 16 Go/4 cœurs : perspectives.

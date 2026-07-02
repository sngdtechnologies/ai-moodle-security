# Résultats d'évaluation — Phase 3 (durcissement + isolation)

Évaluation de fin de Phase 3. Débloque l'axe **confidentialité** du chapitre 6, en plus de la
non-régression sécurité et d'une observation de performance. EC2 Ubuntu, 2 vCPU / 8 Go, CPU.

## Axe confidentialité — aucun flux sortant (garanti par la topologie)

Réseaux : seul `edge` a un accès Internet (proxy public) ; `dmz`, `backend`, `ai` sont
`internal: true`. Vérification depuis les conteneurs :

| Conteneur | Réseaux | Egress Internet | Résultat |
|---|---|---|---|
| `moodle` | dmz, backend (internes) | interdit | **NO EGRESS** ✅ |
| `ollama` | ai (interne) | interdit | **NO EGRESS** ✅ |
| `db` | backend (interne) | interdit | interne |
| `ollama-gate` | backend, ai (internes) | interdit | interne |
| `proxy` | edge, dmz | autorisé (seul point) | egress OK (attendu) |

Méthode : tentative de résolution/connexion externe depuis `moodle` (PHP `file_get_contents`)
et `ollama` (résolution DNS externe) → échec des deux côtés ; `proxy` (edge) réussit. La
**non-fuite des données d'apprenants est ainsi garantie par la conception** (aucune route
sortante pour Moodle et Ollama), conformément au mémoire.

> Note : le point d'entrée public de Phase 3 est un proxy Caddy **relais simple** (pas encore de
> TLS ni de WAF). La Phase 4 ajoute TLS + WAF Coraza (OWASP CRS) à ce proxy. Moodle est déjà, dès
> la Phase 3, sans route entrante directe (joignable uniquement via le proxy).

## Durcissement appliqué (Tableau 8)

| Mesure | moodle | ollama | db | gate | proxy |
|---|---|---|---|---|---|
| Non-root | uid 33 | uid 1000 | mysql | oui | oui |
| Lecture seule (+tmpfs) | oui | oui (+volume modèles) | oui | oui | oui |
| `no-new-privileges` | oui | oui | oui | oui | oui |
| `cap_drop: ALL` | oui | oui | oui (+CHOWN/SETUID/SETGID) | oui | oui |
| seccomp | défaut | **profil Docker par défaut, épinglé** | défaut | défaut | défaut |
| Image épinglée (digest) | oui | oui | oui | oui | oui |
| Limites CPU/mém | — | 2 vCPU / 3 Go | — | — | — |
| Aucun egress | oui | oui | oui | oui | non (edge) |

Le modèle `tuteur-secure` est épinglé **par blob** (`FROM …/blobs/sha256-633fc5be…`, template de
chat préservé) — intégrité de la chaîne d'approvisionnement (§5.4).

## Performance — effet de KEEP_ALIVE

`OLLAMA_KEEP_ALIVE=24h` garde le modèle chargé : après le 1er appel (à froid ~48 s sur 2 vCPU),
les appels suivants tombent à **~7-8 s** (modèle chaud, plus de rechargement). Sur 8 Go/2 cœurs,
la latence reste loin de la cible <2 s du mémoire (qui suppose 16 Go/4 cœurs) : c'est le compromis
matériel assumé de l'instance.

## Sécurité — non-régression

Les garde-fous (pipeline N1-N4, modèle `tuteur-secure`) sont **inchangés** en Phase 3 (seule
l'infrastructure a été durcie) : le taux de blocage reste celui mesuré en Phase 2 (sécurisé 98 %,
cf. `RESULTS-phase2.md`). Une injection directe reste refusée sous la pile durcie+isolée (vérifié).

## Corrections post-revue (revue finale Opus)
- **I1** : `ollama` passe en `read_only: true` + `tmpfs [/tmp]` (c'était le seul service applicatif
  sans rootfs en lecture seule, alors qu'il traite l'entrée non fiable). Corrigé.
- **M4** : tentative de retrait de `DAC_OVERRIDE` de `db` → MariaDB **ne démarre plus** sous
  `read_only`. La capacité est donc **requise** et conservée (moindre privilège vérifié empiriquement).
- **M3** : `mem_limit` ajoutés (moodle 1.5g, db 1g, gate/proxy 256m) pour éviter l'OOM de l'hôte 8 Go.
- **M1** : le profil seccomp est le profil **Docker par défaut** appliqué explicitement (durcissement
  **neutre**, base à restreindre en production, cf. Annexe D) — le tableau est reformulé en conséquence.
- **M2 (limite connue)** : `tmpfs` de Docker Compose n'expose pas `noexec`/`nosuid`/`nodev` (seulement
  size/mode) ; le durcissement `noexec` des tmpfs relève d'un déploiement via l'API Docker ou d'une
  itération de production.
- **M5 (assumé)** : sessions PHP et logs Apache sur tmpfs sont volatils (purge au redémarrage) —
  acceptable en prototype ; la persistance des journaux relève du service de journalisation (Phase 6).

## Notes / reporté
- Provisionnement du modèle : le réseau `ai` étant `internal`, Ollama ne peut plus tirer de modèle ;
  le volume contient déjà `phi3:mini` + `tuteur-secure`. Un nouveau pull suppose d'attacher
  temporairement Ollama à un réseau à egress, puis de refermer.
- seccomp = profil Docker par défaut (base) ; la restriction fine par retrait ciblé (Annexe D) est
  une itération de mise en production.
- Point d'entrée : TLS + WAF Coraza = Phase 4.

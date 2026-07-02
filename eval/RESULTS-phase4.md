# Résultats d'évaluation — Phase 4 (TLS + WAF Coraza)

Évaluation de fin de Phase 4. Le proxy public devient un véritable point d'entrée durci : TLS
(autorité interne) + pare-feu applicatif **Coraza** chargé de l'**OWASP Core Rule Set** en mode
bloquant, devant Moodle. EC2 Ubuntu, 2 vCPU / 8 Go.

## WAF OWASP CRS — mode bloquant (`SecRuleEngine On`)

Proxy = Caddy 2.11.2 recompilé avec `github.com/corazawaf/coraza-caddy/v2` (OWASP CRS **4.25.0**,
paranoia level 1, scoring d'anomalie). Sondes depuis l'hôte via HTTPS :

| Requête | Code | Attendu |
|---|---|---|
| `GET /login/index.php` (légitime) | **200** | passe ✅ |
| `GET /` (accueil) | **200** | passe ✅ |
| `POST /login` (logintoken + username + mot de passe à caractères spéciaux) | **303** | passe ✅ |
| `GET /course/view.php?id=1` | **303** | passe ✅ |
| **SQLi** `?id=1' OR '1'='1` | **403** | bloqué ✅ (règles 942xxx) |
| **XSS** `?q=<script>alert(1)</script>` | **403** | bloqué ✅ (941160, 941390 → score 20) |
| **Path traversal** `?f=../../../../etc/passwd` | **403** | bloqué ✅ (930100/120, 932160 → score 40) |

Aucun **faux positif** journalisé sur le trafic Moodle légitime (login, POST, navigation) au
paranoia level 1. Le tuning fin (montée de paranoïa, exclusions par règle) reste une itération de
production ; il n'a pas été nécessaire pour les flux de démonstration.

## TLS
- `tls internal` : Caddy émet un certificat auto-signé (autorité interne) pour l'hôte `localhost`.
  Accès démo par tunnel SSH sur `https://localhost` (avertissement de certificat attendu).
- L'installation du certificat racine dans le magasin système du conteneur échoue (conteneur durci
  read-only/sans `certutil`) — **sans effet** : le certificat est bien généré et servi.
- Production : certificat de la PKI de l'établissement (aucune distribution de CA côté client).

## Moodle derrière le proxy SSL
- `$CFG->sslproxy = 1`, `wwwroot = https://localhost` : Moodle génère des URLs HTTPS ; le TLS est
  terminé par le proxy, le trafic proxy→moodle reste en HTTP sur le réseau interne `dmz`.
- Login accessible en **HTTPS via le proxy** (200).

## Non-régression
- Tuteur toujours fonctionnel de bout en bout (~3-4 s à chaud) — le chemin d'inférence est interne
  (moodle→gate→ollama). Le rendu HTML final transite par le proxy ; l'inspection des **réponses**
  par le CRS est **désactivée** (`SecResponseBodyAccess Off`) pour ne pas scanner/bufferiser la
  sortie pédagogique du tuteur (code/maths) — sa sûreté est assurée par le niveau N4 (`FORMAT_PLAIN`).
  Le WAF protège donc les requêtes **entrantes** (attaques applicatives) ; la sortie relève de N4.
- Confidentialité inchangée : `moodle` et `ollama` = **NO EGRESS**.

## Note d'implémentation (versions)
Le module `coraza-caddy/v2` récent impose un couplage strict de version avec Caddy : v2.5.0 exige
Caddy **2.11.2** et Go ≥ 1.25. Le proxy est donc bâti sur la chaîne **Caddy 2.11.2** (builder Go
1.26), tandis que le reste de la pile reste sur Caddy 2.8. Images builder et runtime épinglées par
digest.

## Reporté
- Tuning CRS de production (paranoia + exclusions Moodle fines) selon l'usage réel.
- IoT/MQTT = Phase 5 ; Falco = Phase 6 ; campagne d'évaluation 3 axes = Phase 7.

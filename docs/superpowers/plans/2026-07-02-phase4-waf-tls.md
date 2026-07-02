# Phase 4 — Proxy public : TLS + WAF Coraza (OWASP CRS) — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans. Steps use checkbox (`- [ ]`) syntax.

**Goal:** Transformer le proxy relais minimal (Phase 3) en véritable point d'entrée durci : TLS (autorité interne de Caddy, repli hors-ligne) et pare-feu applicatif **Coraza** chargé de l'**OWASP Core Rule Set** en mode bloquant, devant Moodle.

**Architecture :** L'image `proxy` est recompilée avec `xcaddy --with coraza-caddy/v2` (WAF). Le Caddyfile public termine le TLS (`tls internal`), applique le WAF (CRS, `SecRuleEngine On`) puis relaie vers `moodle:8080`. Moodle passe derrière SSL (`$CFG->sslproxy`). Le CRS est ensuite affiné pour ne pas bloquer le trafic Moodle légitime.

**Tech Stack :** Caddy 2.8 + xcaddy, `github.com/corazawaf/coraza-caddy/v2` (OWASP CRS embarqué), TLS interne, Moodle `sslproxy`.

## Global Constraints

- Exécution/test sur l'**EC2 Ubuntu** `ec2-13-53-187-154...` (clé `E:\EC2\MoodleKey.pem`). **Sous-agents : authoring LOCAL ; contrôleur = EC2.** EC2 sur la branche `phase4-waf-tls` (checkout par le contrôleur).
- **Aucun assouplissement sécurité.** Pas de trailer `Co-Authored-By`.
- Instance 2 vCPU / 8 Go. Le proxy reste durci (Phase 3 : `read_only`, tmpfs, `cap_drop: ALL`, `no-new-privileges`, fscap retirée).
- **Digests épinglés (verbatim) :**
  - builder : `caddy:2.8-builder@sha256:9f86856dd089cd85f546b6a022079c9f157ab62bc1d688ecd9b5d474ea81a487`
  - runtime : `caddy:2.8@sha256:226d1f059b75399fe19182893c7184591c07b97afc8dfcf44eeb80c9a77a530f`
- **Module WAF :** `github.com/corazawaf/coraza-caddy/v2` (embarque l'OWASP CRS ; directive `load_owasp_crs`).
- **Le WAF CRS en mode bloquant génère des faux positifs sur Moodle** (formulaires POST riches) — la Task 4 (tuning) est **itérative** : login et actions Moodle doivent rester fonctionnels, WAF activé.

---

### Task 1: Recompiler l'image proxy avec le WAF Coraza (xcaddy)

**Files:**
- Modify: `proxy/Dockerfile`

**Interfaces:**
- Produces: image `proxy` = Caddy compilé avec `coraza-caddy/v2`, fscap retirée, durcie.

- [ ] **Step 1: `proxy/Dockerfile` multi-étapes**

```dockerfile
# proxy/Dockerfile -- Caddy public compile avec le WAF Coraza (OWASP CRS).
FROM caddy:2.8-builder@sha256:9f86856dd089cd85f546b6a022079c9f157ab62bc1d688ecd9b5d474ea81a487 AS build
RUN xcaddy build \
    --with github.com/corazawaf/coraza-caddy/v2

FROM caddy:2.8@sha256:226d1f059b75399fe19182893c7184591c07b97afc8dfcf44eeb80c9a77a530f
COPY --from=build /usr/bin/caddy /usr/bin/caddy
# Retire la file-capability (incompatible no-new-privileges). Le proxy ecoutera
# sur 443 : la capacite de bind sera accordee par cap_add NET_BIND_SERVICE (compose).
RUN cp /usr/bin/caddy /usr/bin/caddy.nocap && rm /usr/bin/caddy && mv /usr/bin/caddy.nocap /usr/bin/caddy
```

- [ ] **Step 2 (CONTRÔLEUR): builder + vérifier le module**

Run: `docker compose build proxy` (télécharge les modules Go — le build a Internet via l'hôte).
Puis : `docker compose run --rm --entrypoint caddy proxy list-modules 2>/dev/null | grep -i coraza`
Expected: `http.handlers.coraza_waf` listé.

- [ ] **Step 3: Commit** — `git commit -m "feat(proxy): compile Caddy with Coraza WAF module (xcaddy)"` ; push.

---

### Task 2: Caddyfile public — TLS interne + WAF CRS + reverse_proxy

**Files:**
- Modify: `proxy/Caddyfile`
- Modify: `docker-compose.yml` (proxy : ports 443/80, `cap_add: NET_BIND_SERVICE`)

**Interfaces:**
- Consumes: image proxy avec Coraza (Task 1).
- Produces: proxy en **HTTPS (443)**, TLS interne, WAF CRS en mode bloquant, relais vers `moodle:8080`.

- [ ] **Step 1: `proxy/Caddyfile`**

```caddyfile
{
	order coraza_waf first
}

:443 {
	tls internal

	coraza_waf {
		load_owasp_crs
		directives `
			Include @coraza.conf-recommended
			Include @crs-setup.conf.example
			Include @owasp_crs/*.conf
			SecRuleEngine On
		`
	}

	reverse_proxy moodle:8080
}
```
> `:443` (sans nom d'hôte) sert le TLS interne pour tout hôte (accès démo par tunnel SSH sur
> `https://localhost`). En production, remplacer par `https://lms.etablissement.cm` + certificat PKI.

- [ ] **Step 2: Ajuster le service `proxy` (compose)**

Remplacer `ports: ["8080:8080"]` par `ports: ["443:443", "80:80"]` et ajouter la capacité de bind
des ports privilégiés (compatible avec `cap_drop: ALL` + `no-new-privileges`) :
```yaml
    cap_drop: [ALL]
    cap_add: [NET_BIND_SERVICE]
```
(Conserver `read_only`, `tmpfs`, `mem_limit`, `security_opt`, le montage du Caddyfile, `depends_on`.)

- [ ] **Step 3 (CONTRÔLEUR): déployer** — `docker compose up -d proxy` ; le proxy démarre et écoute
  sur 443. (L'accès Moodle sera vérifié en Task 3, après bascule SSL de Moodle.)

- [ ] **Step 4: Commit** — `git commit -m "feat(proxy): TLS internal + Coraza OWASP CRS (SecRuleEngine On)"` ; push.

---

### Task 3: Moodle derrière le proxy SSL

**Files:**
- Modify: `moodle/config.php` (`sslproxy`)
- Modify: `.env.example` (`MOODLE_WWWROOT` en https) + `.env` (contrôleur, sur l'EC2)

**Interfaces:**
- Consumes: proxy HTTPS (Task 2).
- Produces: Moodle servi en **HTTPS via le proxy**, URLs cohérentes.

- [ ] **Step 1: `config.php` — proxy SSL**

Ajouter avant `require_once(...setup.php)` :
```php
$CFG->sslproxy = 1; // TLS termine par le proxy Caddy ; trafic interne en HTTP.
```

- [ ] **Step 2: `.env.example` — wwwroot https**

```dotenv
MOODLE_WWWROOT=https://localhost
```

- [ ] **Step 3 (CONTRÔLEUR): mettre à jour `.env` sur l'EC2 + rebuild + purge**

```bash
sed -i 's#^MOODLE_WWWROOT=.*#MOODLE_WWWROOT=https://localhost#' .env
docker compose build moodle && docker compose up -d moodle
docker compose exec -T moodle php admin/cli/cfg.php --name=wwwroot --set=https://localhost 2>/dev/null || true
docker compose exec -T moodle php admin/cli/purge_caches.php
```

- [ ] **Step 4 (CONTRÔLEUR): vérifier l'accès HTTPS via le proxy**

Run (via l'EC2, en acceptant le certificat auto-signé) :
```bash
curl -k -sS -o /dev/null -w "HTTPS login: %{http_code}\n" https://localhost/login/index.php
```
Expected: `200` (ou `303`/redirection cohérente). Consigner.

- [ ] **Step 5: Commit** — `git commit -m "feat(moodle): run behind SSL proxy (sslproxy, https wwwroot)"` ; push.

---

### Task 4 (CONTRÔLEUR + ITÉRATIF): Tuning CRS pour Moodle

**Files:**
- Modify: `proxy/Caddyfile` (exclusions / niveau de paranoïa)

**Interfaces:**
- Produces: WAF **actif et bloquant** qui laisse passer le trafic Moodle légitime.

- [ ] **Step 1: Établir la base de faux positifs**

Se connecter réellement à Moodle (login admin + navigation) via HTTPS et relever les requêtes
bloquées à tort (HTTP 403 du WAF) dans les logs du proxy :
```bash
docker compose logs proxy 2>&1 | grep -iE "coraza|denied|403" | tail -30
```

- [ ] **Step 2: Ajouter les exclusions ciblées (itératif)**

Dans le bloc `directives` du Caddyfile, AVANT `Include @owasp_crs/*.conf`, réduire les faux
positifs par un niveau de paranoïa bas et/ou des exclusions de règles par ID observées :
```
SecAction "id:900000,phase:1,nolog,pass,setvar:tx.paranoia_level=1"
# Exclusions ciblees (exemples, a renseigner selon les 403 observes) :
# SecRuleRemoveById 942100
# SecRuleUpdateTargetById 932130 "!ARGS:sesskey"
```
Rebuild non nécessaire (Caddyfile monté en volume) : `docker compose restart proxy`. Répéter
jusqu'à ce que login + actions Moodle passent, **WAF toujours en `SecRuleEngine On`**.

- [ ] **Step 3: Vérifier que le WAF bloque toujours une attaque**

Le WAF doit continuer à bloquer une attaque applicative évidente :
```bash
curl -k -sS -o /dev/null -w "SQLi probe: %{http_code}\n" "https://localhost/login/index.php?id=1%27%20OR%20%271%27=%271"
curl -k -sS -o /dev/null -w "XSS probe: %{http_code}\n" "https://localhost/?q=<script>alert(1)</script>"
```
Expected: `403` (bloqué par le CRS) pour au moins ces sondes, tandis que le login légitime = 200.

- [ ] **Step 4: Commit** — `git commit -m "tune(proxy): CRS exclusions so Moodle works with WAF blocking on"` ; push.

---

### Task 5 (CONTRÔLEUR): Évaluation Phase 4

**Files:** `eval/RESULTS-phase4.md`

- [ ] **Step 1: Consigner** dans `eval/RESULTS-phase4.md` : TLS actif (cert interne), WAF CRS
  bloquant, exclusions Moodle appliquées, sondes SQLi/XSS bloquées (403) vs login légitime (200),
  tuteur toujours fonctionnel de bout en bout, confidentialité inchangée (Moodle/Ollama no egress).
- [ ] **Step 2: Commit** — `git commit -m "docs(eval): phase 4 results (TLS + WAF CRS blocking)"` ; push.

## Critères de fin de phase 4

- [ ] Image proxy compilée avec le module `coraza_waf`.
- [ ] Proxy en HTTPS (TLS interne) ; Moodle accessible via HTTPS derrière le proxy (login 200).
- [ ] WAF CRS en `SecRuleEngine On` : sondes SQLi/XSS bloquées (403), trafic Moodle légitime OK.
- [ ] Tuteur fonctionnel ; confidentialité (no egress) et durcissement Phase 3 préservés.

## Notes / reporté
- TLS : autorité interne de Caddy (repli hors-ligne). En production : certificat de la PKI de
  l'établissement (aucune distribution de CA côté client). Documenté.
- Le tuning CRS livré vise la faisabilité (Moodle fonctionne, WAF bloquant) ; un durcissement fin
  par montée de paranoïa relève de la mise en production.
- IoT/MQTT = Phase 5 ; Falco = Phase 6 ; campagne d'évaluation 3 axes = Phase 7.

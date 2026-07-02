# proxy/Dockerfile -- Caddy public compile avec le WAF Coraza (OWASP CRS).
# NB : le module coraza-caddy/v2 recent exige Caddy >= 2.9 et Go >= 1.25 ; on utilise
# donc la chaine Caddy 2.10 pour le proxy (le reste de la pile reste sur Caddy 2.8).
FROM caddy:2.10-builder@sha256:01668408cc26e2e00c9d067c30cb43b2ba14ad1f2808beda55503cb2a31f59dc AS build
RUN xcaddy build \
    --with github.com/corazawaf/coraza-caddy/v2@v2.5.0

FROM caddy:2.10@sha256:c3d7ee5d2b11f9dc54f947f68a734c84e9c9666c92c88a7f30b9cba5da182adb
COPY --from=build /usr/bin/caddy /usr/bin/caddy
# Retire la file-capability (incompatible no-new-privileges). Le proxy ecoute sur
# 443 : la capacite de bind est accordee par cap_add NET_BIND_SERVICE (compose).
RUN cp /usr/bin/caddy /usr/bin/caddy.nocap && rm /usr/bin/caddy && mv /usr/bin/caddy.nocap /usr/bin/caddy

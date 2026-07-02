# proxy/Dockerfile -- Caddy public compile avec le WAF Coraza (OWASP CRS).
# NB : coraza-caddy/v2 v2.5.0 exige exactement Caddy 2.11.2 (Go >= 1.25). On utilise
# donc la chaine Caddy 2.11.2 pour le proxy ; le reste de la pile reste sur Caddy 2.8.
FROM caddy:2.11.2-builder@sha256:1ecefa333507828a592aaecc68d5f62a993787057429c04e9b0438a65c980a30 AS build
RUN xcaddy build \
    --with github.com/corazawaf/coraza-caddy/v2@v2.5.0

FROM caddy:2.11.2@sha256:25cdc846626b62d05f6b633b9b40c2c9f6ef89b515dc76133cefd920f7dbe562
COPY --from=build /usr/bin/caddy /usr/bin/caddy
# Retire la file-capability (incompatible no-new-privileges). Le proxy ecoute sur
# 443 : la capacite de bind est accordee par cap_add NET_BIND_SERVICE (compose).
RUN cp /usr/bin/caddy /usr/bin/caddy.nocap && rm /usr/bin/caddy && mv /usr/bin/caddy.nocap /usr/bin/caddy

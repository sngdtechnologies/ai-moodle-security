# proxy/Dockerfile -- Caddy public compile avec le WAF Coraza (OWASP CRS).
FROM caddy:2.8-builder@sha256:9f86856dd089cd85f546b6a022079c9f157ab62bc1d688ecd9b5d474ea81a487 AS build
# v2.1.0 : derniere version compatible Go 1.23 du builder (v2.2.0+ exigent Go >= 1.24/1.25).
RUN xcaddy build \
    --with github.com/corazawaf/coraza-caddy/v2@v2.1.0

FROM caddy:2.8@sha256:226d1f059b75399fe19182893c7184591c07b97afc8dfcf44eeb80c9a77a530f
COPY --from=build /usr/bin/caddy /usr/bin/caddy
# Retire la file-capability (incompatible no-new-privileges). Le proxy ecoute sur
# 443 : la capacite de bind est accordee par cap_add NET_BIND_SERVICE (compose).
RUN cp /usr/bin/caddy /usr/bin/caddy.nocap && rm /usr/bin/caddy && mv /usr/bin/caddy.nocap /usr/bin/caddy

# proxy/Dockerfile -- Caddy proxy public (Phase 3 : relais simple, durci).
# Phase 4 : recompilation avec le module WAF Coraza (xcaddy) + TLS.
FROM caddy:2.8@sha256:226d1f059b75399fe19182893c7184591c07b97afc8dfcf44eeb80c9a77a530f
# Retire la file-capability du binaire (incompatible no-new-privileges) ; le proxy
# ecoute sur le port non privilegie 8080.
RUN cp /usr/bin/caddy /usr/bin/caddy.nocap && rm /usr/bin/caddy && mv /usr/bin/caddy.nocap /usr/bin/caddy

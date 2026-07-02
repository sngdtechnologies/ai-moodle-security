# gate/Dockerfile -- Caddy passerelle d'auth, durci.
FROM caddy:2.8@sha256:226d1f059b75399fe19182893c7184591c07b97afc8dfcf44eeb80c9a77a530f
# Le binaire caddy porte une file-capability (cap_net_bind_service) pour binder les
# ports < 1024. Elle est INCOMPATIBLE avec no-new-privileges (exec EPERM) et INUTILE
# ici : la passerelle ecoute sur le port non privilegie 11434. Une simple copie du
# binaire (sans --preserve) supprime les xattrs de capability.
RUN cp /usr/bin/caddy /usr/bin/caddy.nocap && rm /usr/bin/caddy && mv /usr/bin/caddy.nocap /usr/bin/caddy

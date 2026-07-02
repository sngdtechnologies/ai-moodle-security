# moodle/Dockerfile -- Moodle 4.5 sur base moodlehq PHP-Apache + plugin
FROM moodlehq/moodle-php-apache:8.2@sha256:d265924f93043170ab7c4d4cff49db68667d5bef1df6b3377627cd6b80e758e4

ARG MOODLE_BRANCH=MOODLE_405_STABLE
# Récupère le code Moodle 4.5 stable.
# NB: l'image de base a WORKDIR /var/www/html ; on se place hors de ce dossier
# avant de le supprimer, sinon git échoue (cwd supprimé sous ses pieds).
WORKDIR /
RUN rm -rf /var/www/html && \
    git clone --branch ${MOODLE_BRANCH} --depth 1 \
      https://github.com/moodle/moodle.git /var/www/html

# Copie le plugin fournisseur dans ai/provider/ollamasecure
COPY plugin/aiprovider_ollamasecure /var/www/html/ai/provider/ollamasecure
# config.php et entrypoint
COPY moodle/config.php /var/www/html/config.php
COPY moodle/entrypoint.sh /usr/local/bin/moodle-entrypoint.sh
# Script de configuration IA (invoqué par l'entrypoint via cli/configure_ai.php).
RUN mkdir -p /var/www/html/cli
COPY moodle/cli/configure_ai.php /var/www/html/cli/configure_ai.php
RUN chmod +x /usr/local/bin/moodle-entrypoint.sh && \
    mkdir -p /var/www/moodledata && chown -R www-data:www-data /var/www

# Non-root : Apache doit ecouter sur un port non privilegie (>=1024).
RUN sed -ri 's/\bListen 80\b/Listen 8080/' /etc/apache2/ports.conf; \
    sed -ri 's/:80>/:8080>/' /etc/apache2/sites-enabled/*.conf 2>/dev/null; true

# Restaure le WORKDIR attendu (on l'avait mis a / pour le clone).
WORKDIR /var/www/html

EXPOSE 8080
ENTRYPOINT ["/usr/local/bin/moodle-entrypoint.sh"]

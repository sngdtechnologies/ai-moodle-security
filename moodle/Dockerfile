# moodle/Dockerfile -- Moodle 4.5 sur base moodlehq PHP-Apache + plugin
FROM moodlehq/moodle-php-apache:8.2

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

# Restaure le WORKDIR attendu (on l'avait mis a / pour le clone).
WORKDIR /var/www/html

EXPOSE 80
ENTRYPOINT ["/usr/local/bin/moodle-entrypoint.sh"]

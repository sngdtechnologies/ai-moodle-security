#!/bin/bash
set -e
cd /var/www/html

# Installe la base au premier démarrage (idempotent : détecte l'installation via la table config).
INSTALLED=$(php -r '
  define("CLI_SCRIPT", true);
  require("/var/www/html/config.php");
  try { $DB->get_record("config", ["name" => "siteidentifier"]); echo "yes"; }
  catch (Throwable $e) { echo "no"; }
' 2>/dev/null || echo "no")

if [ "$INSTALLED" != "yes" ]; then
  echo "[entrypoint] Installation de la base Moodle..."
  php admin/cli/install_database.php \
    --agree-license \
    --adminpass="$(cat /run/secrets/moodle_admin_pass)" \
    --adminemail="admin@example.cm" \
    --fullname="LMS Securise" \
    --shortname="LMS"
fi

# Met à niveau (détecte le nouveau plugin) de façon non interactive.
php admin/cli/upgrade.php --non-interactive --allow-unstable || true

# Configure le fournisseur IA (idempotent).
php cli/configure_ai.php || echo "[entrypoint] configuration IA à finaliser manuellement"

# Lance Apache au premier plan.
exec apache2-foreground

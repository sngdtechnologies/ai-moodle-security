#!/bin/bash
# ops/setup-host.sh -- configuration de l'HOTE EC2 (une fois ; idempotent ; necessite sudo) :
#   1. journald : taille et duree bornees. Les logs de tous les conteneurs y sont centralises
#      (driver "journald" dans docker-compose.yml), avec Falco, SSH et la tache cron ci-dessous.
#   2. cron : lance la tache de mediation IoT chaque minute. Le conteneur Moodle n'a pas de cron :
#      sans cela, la tache ne s'execute jamais toute seule.
# Usage (depuis la racine du depot) :  bash ops/setup-host.sh
set -e
DIR="$(cd "$(dirname "$0")/.." && pwd)"
USR="$(id -un)"

echo "[setup-host] journald : SystemMaxUse=1G, retention 1 an"
sudo mkdir -p /etc/systemd/journald.conf.d
sudo tee /etc/systemd/journald.conf.d/aimoodle.conf >/dev/null <<'EOF'
[Journal]
Storage=persistent
SystemMaxUse=1G
MaxRetentionSec=1year
EOF
sudo systemctl restart systemd-journald

echo "[setup-host] cron : tache IoT chaque minute (utilisateur $USR, dossier $DIR)"
sudo tee /etc/cron.d/aimoodle-iot >/dev/null <<EOF
# Moodle n'a pas de cron dans son conteneur : on declenche la tache de mediation IoT chaque minute.
# La sortie va dans le journal : journalctl -t aimoodle-iot
PATH=/usr/local/bin:/usr/bin:/bin
* * * * * $USR cd $DIR && docker compose exec -T moodle php /var/www/html/admin/cli/scheduled_task.php --execute='\\aiprovider_ollamasecure\\task\\iot_mediation' 2>&1 | logger -t aimoodle-iot
EOF
sudo chmod 644 /etc/cron.d/aimoodle-iot

echo "[setup-host] termine."
journalctl --disk-usage
cat /etc/cron.d/aimoodle-iot | tail -1

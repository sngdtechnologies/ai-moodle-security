#!/bin/bash
# ops/setup-host.sh -- configuration de l'HOTE EC2 (une fois ; idempotent ; necessite sudo) :
#   1. journald : taille et duree bornees. Les logs de tous les conteneurs y sont centralises
#      (driver "journald" dans docker-compose.yml), avec Falco, SSH et le cron ci-dessous.
#   2. cron Moodle : le conteneur n'a pas de daemon cron ; l'hote declenche chaque minute le vrai
#      cron de Moodle (admin/cli/cron.php), qui execute les taches planifiees selon LEUR planning
#      (dont la mediation IoT, toutes les 5 min). Sans lui, aucune tache Moodle ne s'execute.
#      --keep-alive=0 : une passe puis arret (sinon il reste en veille active et ecrit ~300 lignes/min
#      dans le journal). "flock" evite qu'un cron lent se chevauche avec le suivant.
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

echo "[setup-host] cron Moodle : chaque minute (utilisateur $USR, dossier $DIR)"
sudo rm -f /etc/cron.d/aimoodle-iot   # ancienne version : ne lancait que la tache IoT, en forcant son planning
sudo tee /etc/cron.d/aimoodle-cron >/dev/null <<EOF
# Cron de Moodle (le conteneur n'a pas de daemon cron). Journal : journalctl -t aimoodle-cron.
# Seuls les evenements utiles sont journalises (echecs, tache IoT, debut/fin de passe) : ~19 taches
# routinieres par minute ecriraient sinon ~110 lignes/min. Le detail complet reste dans la table task_log.
PATH=/usr/local/bin:/usr/bin:/bin
* * * * * $USR cd $DIR && flock -n /tmp/aimoodle-cron.lock docker compose exec -T moodle php /var/www/html/admin/cli/cron.php --keep-alive=0 2>&1 | grep --line-buffered -E 'iot_mediation|failed|rror|xception|Cron run|Cron completed|Server Time' | logger -t aimoodle-cron
EOF
sudo chmod 644 /etc/cron.d/aimoodle-cron

echo "[setup-host] termine."
journalctl --disk-usage
tail -1 /etc/cron.d/aimoodle-cron

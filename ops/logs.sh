#!/bin/bash
# ops/logs.sh -- point d'entree UNIQUE pour consulter les logs de tout le systeme.
# A lancer sur l'EC2, depuis la racine du depot :  bash ops/logs.sh [commande] [duree|filtre]
#
#   resume  (defaut)  tableau de bord : etat des services + derniers evenements de chaque source
#   live [filtre]     flux temps reel UNIFIE (conteneurs + Falco + tache IoT). Ctrl+C pour quitter.
#                     Filtre optionnel (regex, insensible a la casse), ex :  live 'denied|ollama'
#   waf     [duree]   requetes bloquees par le WAF + regles declenchees
#   gate    [duree]   requetes vers la passerelle Ollama (statut, IP source ; les 401 = mauvais jeton)
#   ia      [duree]   generations Ollama (statut, duree) + journal des appels IA de Moodle
#   plugin  [duree]   alertes du plugin (entree invalide, fuite de consigne N3, Ollama injoignable)
#   falco   [duree]   alertes Falco sur le conteneur Ollama
#   iot     [duree]   tache IoT (executee par le cron Moodle) + messages retenus des capteurs
#   cron    [duree]   cron Moodle : dernieres executions, taches en echec, retard
#   export  [duree]   ecrit tout dans ~/logs-<date>.txt (preuve / annexe)
#
# duree = fenetre journald (defaut 30m), ex : 10m, 2h, 1d.
# Sources : journald (conteneurs via le driver Docker "journald", Falco, cron) + base Moodle.
cd "$(dirname "$0")/.." || exit 1
CMD="${1:-resume}"
ARG="${2:-}"
SINCE="30m"; FILTER=""
if [ "$CMD" = "live" ]; then FILTER="$ARG"; else SINCE="${ARG:-30m}"; fi
P="${COMPOSE_PROJECT_NAME:-aimoodle}"
N=8   # lignes affichees par section

J()   { sudo journalctl --no-pager "$@"; }
svc() { J -o cat --since "-$SINCE" -t "${P}-$1-1"; }          # journal d'un service (message seul)
ind() { sed 's/^/  /'; }
sources() {                                                   # criteres journald : conteneurs + Falco + cron Moodle
  local n; for n in $(docker compose ps -a --format '{{.Name}}'); do printf 'SYSLOG_IDENTIFIER=%s\n+\n' "$n"; done
  printf '_SYSTEMD_UNIT=falco-modern-bpf.service\n+\nSYSLOG_IDENTIFIER=aimoodle-cron\n'
}

etat() { echo "== Services"; docker compose ps --format 'table {{.Name}}\t{{.Status}}' | ind; }

waf() {
  echo "== WAF : requetes bloquees ($SINCE, heure UTC)"
  out=$(svc proxy | grep "Access denied (phase 2)" | sed -E 's/.*"ts":([0-9.]+).*\[uri \\"([^\\]*).*/\1 \2/' \
        | awk '{printf "%s  %s\n", strftime("%H:%M:%S",$1), $2}' | tail -n $N)
  [ -n "$out" ] && echo "$out" | ind || echo "  (aucun blocage)"
  echo "== WAF : regles declenchees (nombre, id, message)"
  svc proxy | grep -oE '\[id \\"[0-9]+\\"\] \[rev \\"\\"\] \[msg \\"[^\\]*' \
    | sed -E 's/\[rev .*\[msg \\"/ /; s/\[id \\"//; s/\\"\]//' | sort | uniq -c | sort -rn | head -n $N | ind
}

gate() {
  echo "== Passerelle Ollama : requetes ($SINCE) -- heure UTC, statut, methode, URL, IP source, duree"
  out=$(svc ollama-gate | grep 'http.log.access' \
        | sed -E 's/.*"ts":([0-9.]+).*"remote_ip":"([^"]*)".*"method":"([A-Z]+)".*"uri":"([^"]*)".*"duration":([0-9.]+).*"status":([0-9]+).*/\1 \6 \3 \4 \2 \5/' \
        | awk '{printf "%s  %s %s %s  de %s  (%.1fs)\n", strftime("%H:%M:%S",$1), $2, $3, $4, $5, $6}' | tail -n $N)
  [ -n "$out" ] && echo "$out" | ind || echo "  (aucune requete)"
}

ia() {
  echo "== Ollama : generations ($SINCE) -- statut et duree de chaque appel du tuteur"
  out=$(svc ollama | grep '"/api/generate"' | tail -n $N)
  [ -n "$out" ] && echo "$out" | ind || echo "  (aucun appel)"
  echo "== Moodle : journaux en base (appels IA, activite, connexions, tache IoT)"
  docker compose exec -T moodle sh -c 'cat > /tmp/show_logs.php' < moodle/cli/show_logs.php
  docker compose exec -T moodle php /tmp/show_logs.php 5 2>&1 | ind
}

plugin() {
  echo "== Plugin IA : alertes ($SINCE)"
  out=$(svc moodle | grep 'ollamasecure\[' | tail -n $N)
  [ -n "$out" ] && echo "$out" | ind || echo "  (aucune alerte)"
}

falco() {
  echo "== Falco : alertes sur le conteneur Ollama ($SINCE)"
  out=$(J --since "-$SINCE" -u falco-modern-bpf | grep -i ollama | cut -c1-230 | tail -n $N)
  [ -n "$out" ] && echo "$out" | ind || echo "  (aucune alerte)"
}

iot() {
  echo "== Tache IoT (planifiee toutes les 5 min dans Moodle) : dernieres executions ($SINCE)"
  out=$(J -o cat --since "-$SINCE" -t aimoodle-cron | grep iot_mediation | tail -n 4)
  [ -n "$out" ] && echo "$out" | ind || echo "  (aucune execution : cron installe ? -> bash ops/setup-host.sh)"
  echo "== Messages retenus des capteurs"
  docker compose exec -T mosquitto mosquitto_sub -h localhost -t '#' -v -W 2 -C 4 2>/dev/null | cut -c1-140 | ind
}

cron_() {
  echo "== Cron Moodle ($SINCE) : dernieres executions (lance chaque minute par l'hote)"
  out=$(J -o cat --since "-$SINCE" -t aimoodle-cron | grep -E "^Cron run completed correctly|^Cron completed at" | tail -n 3)
  [ -n "$out" ] && echo "$out" | cut -c1-140 | ind || echo "  (aucune execution : cron installe ? -> bash ops/setup-host.sh)"
  echo "== Cron Moodle : taches en echec"
  out=$(J -o cat --since "-$SINCE" -t aimoodle-cron | grep -E "task failed" | sort | uniq -c | sort -rn | head -n $N)
  [ -n "$out" ] && echo "$out" | cut -c1-200 | ind || echo "  (aucun echec)"
}

live() {
  echo "Flux unifie : conteneurs ${P}-*, Falco, tache IoT.  Ctrl+C pour quitter.${FILTER:+  Filtre : $FILTER}"
  mapfile -t args < <(sources)
  if [ -n "$FILTER" ]; then J -f -n 20 -o short-iso "${args[@]}" | grep --line-buffered -iE "$FILTER"
  else J -f -n 20 -o short-iso "${args[@]}" | grep --line-buffered -v "Sensitive file opened"; fi   # bruit hote Falco
}

export_() {
  f="$HOME/logs-$(date +%F_%H%M).txt"
  mapfile -t args < <(sources)
  J -o short-iso --since "-$SINCE" "${args[@]}" > "$f"
  echo "Exporte : $f ($(wc -l < "$f") lignes, fenetre $SINCE)"
}

case "$CMD" in
  resume) etat; echo; waf; echo; gate; echo; ia; echo; plugin; echo; falco; echo; iot; echo; cron_ ;;
  live)   live ;;
  waf)    waf ;;
  gate)   gate ;;
  ia)     ia ;;
  plugin) plugin ;;
  falco)  falco ;;
  iot)    iot ;;
  cron)   cron_ ;;
  export) export_ ;;
  *)      sed -n '2,20p' "$0" | sed 's/^# \{0,1\}//' ;;
esac

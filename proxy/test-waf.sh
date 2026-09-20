#!/bin/bash
# proxy/test-waf.sh -- verifie le WAF (Coraza + OWASP CRS) :
#   - les ATTAQUES doivent etre bloquees (HTTP 403) ;
#   - les usages LEGITIMES de Moodle ne doivent PAS etre bloques (tout code sauf 403).
# A lancer sur l'hote EC2 (le proxy ecoute sur 443) :  bash proxy/test-waf.sh
H="${PROXY_HOST:-lms.pkfokam}"
pass=0; fail=0

req() { curl -sk -o /dev/null -w "%{http_code}" --resolve "$H:443:127.0.0.1" "$@"; }

expect_block() {   # description, puis arguments curl
  local d="$1"; shift; local c; c=$(req "$@")
  if [ "$c" = "403" ]; then echo "  OK   bloque ($c)  $d"; pass=$((pass+1))
  else echo "  KO   NON bloque ($c)  $d   <-- attaque qui passe"; fail=$((fail+1)); fi
}
expect_allow() {
  local d="$1"; shift; local c; c=$(req "$@")
  if [ "$c" != "403" ]; then echo "  OK   autorise ($c)  $d"; pass=$((pass+1))
  else echo "  KO   BLOQUE ($c)  $d   <-- faux positif"; fail=$((fail+1)); fi
}

U="https://$H"
echo "== Attaques (doivent etre bloquees)"
expect_block "injection SQL"                "$U/login/index.php?id=1%27%20OR%201=1--"
expect_block "XSS dans l'URL"               "$U/login/index.php?q=%3Cscript%3Ealert(1)%3C/script%3E"
expect_block "traversee de repertoire"      "$U/login/index.php?f=../../../../etc/passwd"
expect_block "commande Unix dans une VALEUR" -X POST "$U/course/modedit.php" -d "name=x;/bin/cat /etc/passwd"
expect_block "text/plain hors service.php"  -X POST "$U/login/index.php" -H "Content-Type: text/plain" -d "x"

echo "== Usages Moodle legitimes (ne doivent PAS etre bloques)"
expect_allow "page de connexion"                          "$U/login/index.php"
expect_allow "formulaire d'activite (champ groupmode)"    -X POST "$U/course/modedit.php" -d "groupmode=0&name=Test"
expect_allow "formulaire de cours (champ groupmodeforce)" -X POST "$U/course/edit.php" -d "groupmodeforce=0&fullname=Test"
expect_allow "appel AJAX Moodle en text/plain"            -X POST "$U/lib/ajax/service.php?sesskey=abc" -H "Content-Type: text/plain" -d "[]"

echo; echo "Resultat : $pass OK, $fail KO"
[ "$fail" -eq 0 ]

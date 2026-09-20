<?php
// moodle/cli/show_logs.php -- resume des journaux Moodle utiles pour la soutenance (lecture seule) :
//   1. appels IA (ai_action_register)  2. activite recente (logstore_standard_log)
//   3. echecs de connexion sur 24 h    4. executions de la tache IoT (task_log)
// Usage (hote EC2) :
//   docker compose exec -T moodle sh -c "cat > /tmp/show_logs.php" < moodle/cli/show_logs.php
//   docker compose exec -T moodle php /tmp/show_logs.php [nb_lignes]
// NB : les heures affichees sont celles du fuseau de Moodle (UTC+1 constate), Docker/Falco sont en UTC.
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');
global $DB;
$n = isset($argv[1]) ? max(1, (int)$argv[1]) : 8;

echo "== 1. Appels IA (qui, quoi, succes ?)\n";
$sql = "SELECT r.id, u.username, r.actionname, r.success, r.errorcode, r.timecreated
          FROM {ai_action_register} r LEFT JOIN {user} u ON u.id = r.userid ORDER BY r.id DESC";
foreach ($DB->get_records_sql($sql, null, 0, $n) as $r) {
    printf("  #%-3d %s  %-10s %-14s succes=%s code=%s\n", $r->id, date('d/m H:i:s', $r->timecreated),
        $r->username, $r->actionname, $r->success, $r->errorcode);
}

echo "\n== 2. Activite recente (journal standard)\n";
$sql = "SELECT l.id, l.timecreated, u.username, l.eventname, l.ip
          FROM {logstore_standard_log} l LEFT JOIN {user} u ON u.id = l.userid ORDER BY l.id DESC";
foreach ($DB->get_records_sql($sql, null, 0, $n) as $r) {
    printf("  %s  %-10s %-52s %s\n", date('d/m H:i:s', $r->timecreated), $r->username, $r->eventname, $r->ip);
}

echo "\n== 3. Echecs de connexion (24 h)\n  ";
echo $DB->count_records_select('logstore_standard_log', 'eventname = :e AND timecreated > :t',
    ['e' => '\core\event\user_login_failed', 't' => time() - 86400]), "\n";

echo "\n== 4. Tache IoT (mediation MQTT) : dernieres executions\n";
foreach ($DB->get_records_sql("SELECT id, timestart, result FROM {task_log} WHERE classname LIKE :c ORDER BY id DESC",
        ['c' => '%iot_mediation%'], 0, 3) as $r) {
    printf("  #%d %s  resultat=%s (0 = succes)\n", $r->id, date('d/m H:i:s', (int)$r->timestart), $r->result);
}

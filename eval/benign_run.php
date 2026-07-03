<?php
// eval/benign_run.php -- passe le jeu benin par le client securise et compte les
// FAUX BLOCAGES (une requete legitime bloquee = faux positif -> precision).
// Execute DANS le conteneur moodle : php eval/benign_run.php <prompts_benign.jsonl>
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$file = $argv[1] ?? '/tmp/prompts_benign.jsonl';
$blocked = get_string('blocked', 'aiprovider_ollamasecure');
$unavail = get_string('aiunavailable', 'aiprovider_ollamasecure');

$client = new \aiprovider_ollamasecure\client();
$lines = array_filter(array_map('trim', file($file)));
$results = [];
$fp = 0; $ok = 0; $unav = 0;
foreach ($lines as $ln) {
    $p = json_decode($ln, true);
    if (!$p) { continue; }
    $out = $client->ask($p['prompt'], 2);
    $isblocked = ($out === $blocked);
    $isunavail = ($out === $unavail);
    if ($isblocked) { $fp++; }
    elseif ($isunavail) { $unav++; }   // timeout : ni FP ni bon, exclu
    else { $ok++; }
    $results[] = ['id' => $p['id'], 'blocked' => $isblocked, 'unavailable' => $isunavail,
                  'out' => mb_substr($out, 0, 100)];
    fwrite(STDERR, "benin {$p['id']} " . ($isblocked ? "BLOQUE(FP)" : ($isunavail ? "indispo" : "ok")) . "\n");
}
$total_valides = $fp + $ok;   // hors timeouts
echo "\n=== JEU BENIN ===\n";
echo "legitimes traites (hors timeouts) : $total_valides ; passes(TN)=$ok ; FAUX BLOCAGES(FP)=$fp ; timeouts=$unav\n";
file_put_contents('/tmp/benign-results.json', json_encode($results, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
echo "details -> /tmp/benign-results.json\n";

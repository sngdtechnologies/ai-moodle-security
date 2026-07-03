<?php
// eval/perf_run.php -- mesure de latence (axe performance, ch.6).
// Usage DANS le conteneur moodle : php eval/perf_run.php <secured|nu> <N>
define('CLI_SCRIPT', true);
require('/var/www/html/config.php');

$mode = $argv[1] ?? 'secured';
$N    = isset($argv[2]) ? (int)$argv[2] : 15;
$prompt = "Explique en une phrase un concept scientifique simple.";

$tok = trim(@file_get_contents('/run/secrets/ollama_token'));
function ask_nu($tok, $text) {
    $ch = curl_init('http://ollama-gate:11434/api/generate');
    curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER=>1, CURLOPT_TIMEOUT=>150,
        CURLOPT_POSTFIELDS=>json_encode(['model'=>'phi3:mini','prompt'=>$text,'stream'=>false]),
        CURLOPT_HTTPHEADER=>['Content-Type: application/json','Authorization: Bearer '.$tok]]);
    curl_exec($ch); curl_close($ch);
}

$client = ($mode === 'secured') ? new \aiprovider_ollamasecure\client() : null;
// Prechauffage (modele charge) : 1 appel non compte.
if ($mode === 'secured') { $client->ask($prompt, 2); } else { ask_nu($tok, $prompt); }

$lat = [];
for ($i = 0; $i < $N; $i++) {
    $t = microtime(true);
    if ($mode === 'secured') { $client->ask($prompt, 2); } else { ask_nu($tok, $prompt); }
    $dt = microtime(true) - $t;
    $lat[] = $dt;
    fwrite(STDERR, sprintf("  %s #%d : %.1fs\n", $mode, $i+1, $dt));
}
sort($lat);
$mean = array_sum($lat)/count($lat);
$p95  = $lat[(int)ceil(0.95*count($lat))-1];
printf("\n[%s] N=%d  moyenne=%.1fs  p95=%.1fs  min=%.1fs  max=%.1fs\n",
    $mode, $N, $mean, $p95, $lat[0], $lat[count($lat)-1]);

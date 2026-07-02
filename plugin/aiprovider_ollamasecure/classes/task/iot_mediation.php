<?php
// ai/provider/ollamasecure/classes/task/iot_mediation.php
namespace aiprovider_ollamasecure\task;

defined('MOODLE_INTERNAL') || die();

/**
 * Mediation des flux IoT : draine le courtier MQTT, valide le schema, rejette le
 * non conforme, agrege un indicateur numerique BORNE. Aucun texte libre de capteur
 * n'est jamais propage cote instruction ; un capteur compromis ne peut ni injecter
 * d'instruction, ni atteindre Ollama (isole au niveau reseau).
 */
class iot_mediation extends \core\task\scheduled_task {
    const BROKER = 'mosquitto';
    const TOPIC  = 'capteurs/#';

    public function get_name(): string {
        return get_string('task_iot_mediation', 'aiprovider_ollamasecure');
    }

    public function execute(): void {
        // Draine les valeurs RETAINED disponibles (CLI mosquitto_sub). 'timeout 10'
        // borne l'etablissement de connexion (si le broker ne repond pas au niveau
        // TCP) ; '-W 3' borne la reception des messages.
        $cmd = sprintf('timeout 10 mosquitto_sub -h %s -t %s -C 20 -W 3 2>/dev/null',
            escapeshellarg(self::BROKER), escapeshellarg(self::TOPIC));
        $raw = shell_exec($cmd);
        $lines = $raw ? array_filter(array_map('trim', explode("\n", $raw))) : [];

        $attention = [];  // valeurs de type 'attention' (seules agregees dans l'indice)
        $valides = 0;     // total conforme au schema (tous types de capteurs)
        $rejets = 0;
        foreach ($lines as $line) {
            $m = json_decode($line, true);
            if (!$this->schema_ok($m)) { $rejets++; continue; }   // schema strict
            $valides++;
            if ($m['type'] === 'attention') { $attention[] = (float) $m['value']; }
        }
        if ($rejets > 0) {
            // Journal minimise : categorie + compte, jamais le contenu brut du capteur.
            debugging("ollamasecure[iot_rejects]=$rejets", DEBUG_NORMAL);
        }
        if ($attention) {
            // Agregation des seules mesures 'attention' -> indicateur BORNE [0,1]
            // (strictement numerique, jamais de texte).
            $moy = array_sum($attention) / count($attention);
            $indice = max(0.0, min(1.0, $moy / 100.0));
            set_config('indice_attention', sprintf('%.2f', $indice), 'aiprovider_ollamasecure');
            mtrace("iot_mediation: indice_attention=" . sprintf('%.2f', $indice)
                . " (attention=" . count($attention) . ", valides=$valides, rejets=$rejets)");
        } else {
            mtrace("iot_mediation: aucune mesure d'attention valide (valides=$valides, rejets=$rejets)");
        }
    }

    /** Schema strict : type connu, valeur NUMERIQUE dans la plage, horodatage plausible. */
    private function schema_ok($m): bool {
        if (!is_array($m)) { return false; }
        if (!isset($m['type'], $m['value'], $m['ts'])) { return false; }
        if (!in_array($m['type'], ['attention', 'presence'], true)) { return false; }
        if (!is_int($m['value']) && !is_float($m['value'])) { return false; } // rejette le texte
        if ($m['value'] < 0 || $m['value'] > 100) { return false; }           // plage
        if (!is_int($m['ts']) || $m['ts'] < 1700000000) { return false; }     // horodatage
        return true;
    }
}

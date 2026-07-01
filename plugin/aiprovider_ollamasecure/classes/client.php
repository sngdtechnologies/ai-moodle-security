<?php
// ai/provider/ollamasecure/classes/client.php
namespace aiprovider_ollamasecure;
defined('MOODLE_INTERNAL') || die();

class client {
    const ENDPOINT = 'http://ollama-gate:11434/api/generate';
    const MODEL    = 'tuteur-secure';
    const MAX_LEN  = 6000;

    /** Niveau 1 : contrôle STRUCTUREL neutre. */
    private function normalize(string $s): string {
        $s = preg_replace('/[\x00-\x08\x0B\x0C\x0E-\x1F\x7F]/u', '', $s);
        return trim($s);
    }
    public function validate(string $instruction): bool {
        $n = mb_strlen($this->normalize($instruction));
        return $n > 0 && $n <= self::MAX_LEN;
    }
    private function strip_delimiters(string $c): string {
        return preg_replace('/\[\s*\/?\s*(instruction|donnees)\s*\]/iu', '', $c);
    }
    private function build_prompt(string $instr, string $contenu = ''): string {
        $instr = $this->strip_delimiters($this->normalize($instr));
        $p = "[INSTRUCTION]\n" . $instr . "\n[/INSTRUCTION]\n";
        if ($contenu !== '') {
            $contenu = $this->strip_delimiters($this->normalize($contenu));
            $p .= "[DONNEES]\n" . $contenu . "\n[/DONNEES]\n";
        }
        return $p;
    }
    public function ask(string $instruction, int $userid, string $contenu = ''): string {
        if (!$this->validate($instruction)) {
            $this->log_alert('invalid_input', $userid);
            return get_string('blocked', 'aiprovider_ollamasecure');
        }
        $token = get_config('aiprovider_ollamasecure', 'ollama_token');
        $payload = json_encode(['model' => self::MODEL,
            'prompt' => $this->build_prompt($instruction, $contenu),
            'stream' => false]);
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                'Authorization: Bearer ' . $token],
            CURLOPT_TIMEOUT        => 30]);
        $raw = curl_exec($ch); $errno = curl_errno($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        // Erreur de transport OU statut HTTP non 2xx (ex. 401 de la passerelle) :
        // on ne doit pas avaler un echec d'auth en renvoyant une reponse vide.
        if ($errno !== 0 || $raw === false || $httpcode < 200 || $httpcode >= 300) {
            $this->log_alert('ollama_unreachable', $userid, 'errno=' . $errno . ' http=' . $httpcode);
            return get_string('aiunavailable', 'aiprovider_ollamasecure');
        }
        $decoded = json_decode($raw, true);
        $out = is_array($decoded) ? ($decoded['response'] ?? '') : '';
        return $this->sanitize_output($out);
    }
    /** Niveaux 3 & 4 : detection de fuite (marqueurs systeme/canaux) puis
      * rendu sur par Moodle. FORMAT_PLAIN echappe tout HTML (anti-XSS). */
    private function sanitize_output(string $o): string {
        // Marqueurs revelateurs d'une divulgation de la consigne systeme ou
        // des delimiteurs de canaux (best-effort, sans motifs de langage libre).
        $leak = ['[instruction]', '[/instruction]', '[donnees]', '[/donnees]',
                 'règles inviolables', 'regles inviolables', 'prompt système',
                 'prompt systeme'];
        $low = \core_text::strtolower($o);
        foreach ($leak as $needle) {
            if (mb_strpos($low, \core_text::strtolower($needle)) !== false) {
                $this->log_alert('possible_prompt_leak', 0);
                return get_string('blocked', 'aiprovider_ollamasecure');
            }
        }
        return format_text($o, FORMAT_PLAIN, ['filter' => false]);
    }
    /** Journalisation minimisée (loi 2024/017) : catégorie + user pseudonymisé. */
    private function log_alert(string $event, int $userid, string $detail = ''): void {
        $hashed = $userid ? hash('sha256', $userid . get_site_identifier()) : 'anon';
        debugging("ollamasecure[$event] user=$hashed $detail", DEBUG_NORMAL);
    }
}

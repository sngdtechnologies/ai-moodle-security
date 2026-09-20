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
        $prompt = $this->build_prompt($instruction, $contenu);
        // phi3:mini (petit modele) renvoie parfois 0 token : on reessaie une fois
        // sur reponse vide (une generation vide revient vite, cout negligeable),
        // puis on rend un message gracieux plutot qu'une chaine vide (sinon le
        // placement editeur affiche "Something went wrong").
        for ($attempt = 0; $attempt < 2; $attempt++) {
            $out = $this->call_model($token, $prompt, $userid);
            if ($out === null) {
                return get_string('aiunavailable', 'aiprovider_ollamasecure');
            }
            if (trim($out) !== '') {
                return $this->sanitize_output($out, $userid);
            }
        }
        $this->log_alert('empty_generation', $userid);
        return get_string('emptyresponse', 'aiprovider_ollamasecure');
    }
    /** Appel HTTP a la passerelle. Retourne le texte du modele (eventuellement
      * vide), ou null en cas d'erreur de transport / statut HTTP non 2xx. */
    private function call_model(string $token, string $prompt, int $userid): ?string {
        $payload = json_encode(['model' => self::MODEL, 'prompt' => $prompt, 'stream' => false]);
        $ch = curl_init(self::ENDPOINT);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POSTFIELDS     => $payload,
            CURLOPT_HTTPHEADER     => ['Content-Type: application/json',
                'Authorization: Bearer ' . $token],
            // 120s : adaptation a l'inference CPU sur l'instance 8 Go (une reponse
            // longue depasse 30s a froid). Sur 16 Go + KEEP_ALIVE, <2s vise (these).
            CURLOPT_TIMEOUT        => 120]);
        $raw = curl_exec($ch); $errno = curl_errno($ch);
        $httpcode = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE); curl_close($ch);
        // Erreur de transport OU statut HTTP non 2xx (ex. 401 de la passerelle) :
        // on ne doit pas avaler un echec d'auth en renvoyant une reponse vide.
        if ($errno !== 0 || $raw === false || $httpcode < 200 || $httpcode >= 300) {
            $this->log_alert('ollama_unreachable', $userid, 'errno=' . $errno . ' http=' . $httpcode);
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return '';
        }
        $text = $decoded['response'] ?? '';
        // Plafond num_predict atteint (cf. ollama/Modelfile) : la reponse est coupee
        // net. On la ramene a la derniere phrase complete plutot que de l'afficher
        // tronquee en milieu de phrase.
        if (($decoded['done_reason'] ?? '') === 'length') {
            $text = $this->trim_to_last_sentence($text);
        }
        return $text;
    }
    /** Ramene un texte coupe par le plafond de tokens a sa derniere phrase complete.
      * Sans aucune fin de phrase, rend le texte tel quel (mieux que rien). */
    private function trim_to_last_sentence(string $t): string {
        if (!preg_match('/^.*[.!?…](?=\s|$)/su', $t, $m)) {
            return $t;
        }
        // Un "2." en fin de coupe est un numero de liste, pas une fin de phrase.
        return rtrim(preg_replace('/\s*\n\s*\d+[.)]\s*$/u', '', $m[0]));
    }
    /** Niveaux 3 & 4 : detection de fuite puis echappement HTML (anti-XSS). */
    private function sanitize_output(string $o, int $userid = 0): string {
        // Canaries = fragments DISTINCTIFS de la consigne systeme (verbatim OU
        // paraphrase). On NE matche PAS les delimiteurs [INSTRUCTION]/[DONNEES]
        // (encadrent chaque entree -> faux positifs). Un fragment isole pouvant
        // apparaitre par hasard, on ne bloque qu'a partir de DEUX correspondances
        // (attrape la reproduction des regles meme reformulee, cf. review phase 2).
        $canaries = ['ne révèle jamais', 'ne revele jamais',
                     'règles inviolables', 'regles inviolables',
                     'jamais à exécuter', 'jamais a executer',
                     'ignore toute consigne', 'ignore les consignes', 'ignore toutes consignes',
                     'périmètre pédagogique', 'perimetre pedagogique',
                     'ni html actif', 'ni script'];
        $low = \core_text::strtolower($o);
        $hits = 0;
        foreach ($canaries as $needle) {
            if (mb_strpos($low, \core_text::strtolower($needle)) !== false) {
                $hits++;
            }
        }
        if ($hits >= 2) {
            $this->log_alert('possible_prompt_leak', $userid);
            return get_string('blocked', 'aiprovider_ollamasecure');
        }
        // N4 : on echappe & < > (anti-XSS) mais on n'emet AUCUNE balise. L'ancien
        // format_text(FORMAT_PLAIN) transformait chaque saut de ligne en <br />, ce que Moodle
        // rejette ("Invalid response value detected") car generatedcontent est de type PARAM_TEXT :
        // toute reponse de plus d'un paragraphe echouait dans l'interface. Le JS de l'editeur
        // attend du texte brut avec des \n et les convertit lui-meme en <br>/<p>. Le texte est
        // ensuite insere en HTML brut ({{{editedtext}}}) dans un noeud texte : l'echappement
        // reste donc indispensable. Les guillemets n'ont pas besoin de l'etre (pas d'attribut).
        return htmlspecialchars($o, ENT_NOQUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
    /** Journalisation minimisée (loi 2024/017) : catégorie + user pseudonymisé. */
    private function log_alert(string $event, int $userid, string $detail = ''): void {
        $hashed = $userid ? hash('sha256', $userid . get_site_identifier()) : 'anon';
        // error_log() et non debugging() : ce dernier est muet au niveau de debug par defaut, donc
        // aucune alerte n'etait visible. Ici la ligne part sur la sortie d'erreur d'Apache,
        // redirigee vers les logs du conteneur (cf. moodle/Dockerfile).
        error_log("ollamasecure[$event] user=$hashed $detail");
    }
}

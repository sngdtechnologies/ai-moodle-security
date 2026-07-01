<?php
// ai/provider/ollamasecure/classes/process_generate_text.php
namespace aiprovider_ollamasecure;

class process_generate_text extends \core_ai\process_base {
    protected function query_ai_api(): array {
        $contextid = $this->action->get_configuration('contextid');
        $context = \context::instance_by_id($contextid);
        require_capability('aiprovider/ollamasecure:use', $context);
        $client = new client();
        $texte = $client->ask(
            $this->action->get_configuration('prompttext'),
            $this->action->get_configuration('userid'));
        return ['success' => true, 'generatedcontent' => $texte];
    }
}

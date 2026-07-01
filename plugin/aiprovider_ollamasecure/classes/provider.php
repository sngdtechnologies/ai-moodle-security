<?php
// ai/provider/ollamasecure/classes/provider.php
namespace aiprovider_ollamasecure;
use core_ai\rate_limiter;
use core_ai\aiactions\base;

class provider extends \core_ai\provider {
    public function get_action_list(): array {
        return [\core_ai\aiactions\generate_text::class];
    }
    public function is_provider_configured(): bool {
        return !empty($this->get_config('ollama_token'));
    }
    public function is_request_allowed(base $action): array|bool {
        $rl = \core\di::get(rate_limiter::class);
        $userid = $action->get_configuration('userid');
        if (!$rl->check_user_rate_limit('aiprovider_ollamasecure', 20, $userid)) {
            return ['errorcode' => 429,
                'errormessage' => get_string('ratelimited', 'aiprovider_ollamasecure')];
        }
        return true;
    }
}

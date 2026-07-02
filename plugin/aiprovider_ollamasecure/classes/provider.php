<?php
// ai/provider/ollamasecure/classes/provider.php
namespace aiprovider_ollamasecure;
use core_ai\rate_limiter;
use core_ai\aiactions\base;

class provider extends \core_ai\provider {
    /** @var string Jeton porteur de la passerelle d'authentification. */
    private string $ollamatoken;

    public function __construct() {
        // Lu au niveau plugin (fonction globale get_config, comme aiprovider_openai).
        $this->ollamatoken = (string) get_config('aiprovider_ollamasecure', 'ollama_token');
    }

    public function get_action_list(): array {
        return [\core_ai\aiactions\generate_text::class];
    }

    public function is_provider_configured(): bool {
        return $this->ollamatoken !== '';
    }

    public function is_request_allowed(base $action): array|bool {
        $ratelimiter = \core\di::get(rate_limiter::class);
        $component = \core\component::get_component_from_classname(get_class($this));
        if (!$ratelimiter->check_user_rate_limit(
                component: $component,
                ratelimit: 20,
                userid: $action->get_configuration('userid'))) {
            return [
                'success' => false,
                'errorcode' => 429,
                'errormessage' => get_string('ratelimited', 'aiprovider_ollamasecure'),
            ];
        }
        return true;
    }
}

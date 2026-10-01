<?php
namespace aiplacement_tuteur;

/**
 * Declare les actions IA utilisees par cet emplacement.
 */
class placement extends \core_ai\placement {
    public function get_action_list(): array {
        return [
            \core_ai\aiactions\generate_text::class,
        ];
    }
}

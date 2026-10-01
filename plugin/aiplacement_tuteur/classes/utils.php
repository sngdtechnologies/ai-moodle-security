<?php
namespace aiplacement_tuteur;

use core_ai\manager;

/**
 * Fonctions utilitaires pour l'emplacement tuteur.
 */
class utils {
    /**
     * Verifie si le tuteur IA est disponible dans ce contexte : plugin active,
     * capacite accordee, action activee, au moins un fournisseur disponible.
     *
     * @param \context $context Le contexte a verifier.
     * @return bool
     */
    public static function is_tutor_available(\context $context): bool {
        [$plugintype, $pluginname] = explode('_', \core_component::normalize_componentname('aiplacement_tuteur'), 2);
        $manager = \core_plugin_manager::resolve_plugininfo_class($plugintype);
        if (!$manager::is_plugin_enabled($pluginname)) {
            return false;
        }

        $providers = manager::get_providers_for_actions([\core_ai\aiactions\generate_text::class], true);
        if (!has_capability('aiplacement/tuteur:use', $context)
            || !manager::is_action_available(\core_ai\aiactions\generate_text::class)
            || !manager::is_action_enabled('aiplacement_tuteur', \core_ai\aiactions\generate_text::class)
            || empty($providers[\core_ai\aiactions\generate_text::class])
        ) {
            return false;
        }

        return true;
    }
}

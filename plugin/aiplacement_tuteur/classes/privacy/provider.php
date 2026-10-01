<?php
namespace aiplacement_tuteur\privacy;

use core_privacy\local\metadata\null_provider;

/**
 * Sous-systeme de confidentialite : ce plugin ne stocke aucune donnee personnelle propre
 * (les reponses generees sont tracees par core_ai, deja couvert par son propre fournisseur).
 *
 * @codeCoverageIgnore
 */
class provider implements null_provider {
    public static function get_reason(): string {
        return 'privacy:metadata';
    }
}

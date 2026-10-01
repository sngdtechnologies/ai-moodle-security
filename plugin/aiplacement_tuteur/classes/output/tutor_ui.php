<?php
namespace aiplacement_tuteur\output;

use aiplacement_tuteur\utils;
use core\hook\output\after_http_headers;
use core\hook\output\before_footer_html_generation;

/**
 * Gestionnaire de sortie pour l'emplacement tuteur.
 */
class tutor_ui {
    public static function load_tutor_ui(before_footer_html_generation $hook): void {
        global $PAGE, $OUTPUT, $USER;
        if (!self::preflight_checks()) {
            return;
        }
        $params = [
            'userid' => $USER->id,
            'contextid' => $PAGE->context->id,
        ];
        $html = $OUTPUT->render_from_template('aiplacement_tuteur/drawer', $params);
        $hook->add_html($html);
    }

    public static function load_tutor_button(after_http_headers $hook): void {
        global $OUTPUT;
        if (!self::preflight_checks()) {
            return;
        }
        $html = $OUTPUT->render_from_template('aiplacement_tuteur/button', []);
        $hook->add_html($html);
    }

    /**
     * Determine si le bouton/tiroir doit s'afficher sur la page courante.
     *
     * Elargit la regle d'aiplacement_courseassist (CONTEXT_MODULE seul) a CONTEXT_COURSE
     * egalement, pour une visibilite "partout dans le cours" (spec section 6).
     *
     * Note : on lit $PAGE->course->id directement (sans isset() prealable). moodle_page
     * n'implemente que __get() pour ses proprietes magiques (course, cm, ...), pas __isset() ;
     * isset($PAGE->course) retourne donc toujours false en PHP, meme quand le cours courant
     * est correctement renseigne (magic_get_course() renvoie systematiquement un objet valide,
     * $SITE par defaut si aucun cours n'est positionne). Un isset() prealable ferait donc
     * toujours prendre la branche SITEID, masquant le bouton sur toutes les pages de cours.
     * Verifie en conditions reelles sur l'EC2 (task-3-report.md, root cause documentee).
     */
    private static function preflight_checks(): bool {
        global $PAGE;
        if (during_initial_install()) {
            return false;
        }
        if (!get_config('aiplacement_tuteur', 'version')) {
            return false;
        }
        if (in_array($PAGE->pagelayout, ['maintenance', 'print', 'redirect', 'embedded', 'login'])) {
            return false;
        }
        if (!in_array($PAGE->context->contextlevel, [CONTEXT_COURSE, CONTEXT_MODULE], true)) {
            return false;
        }
        if ($PAGE->course->id == SITEID) {
            return false;
        }
        return utils::is_tutor_available($PAGE->context);
    }
}

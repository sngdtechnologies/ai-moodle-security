<?php
namespace aiplacement_tuteur;

use core\hook\output\after_http_headers;
use core\hook\output\before_footer_html_generation;

/**
 * Relaie les hooks de sortie vers tutor_ui.
 */
class hook_callbacks {
    public static function before_footer_html_generation(before_footer_html_generation $hook): void {
        \aiplacement_tuteur\output\tutor_ui::load_tutor_ui($hook);
    }

    public static function after_http_headers(after_http_headers $hook): void {
        \aiplacement_tuteur\output\tutor_ui::load_tutor_button($hook);
    }
}

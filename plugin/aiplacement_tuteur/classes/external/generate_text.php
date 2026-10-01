<?php
namespace aiplacement_tuteur\external;

use aiplacement_tuteur\utils;
use core_external\external_api;
use core_external\external_function_parameters;
use core_external\external_value;

/**
 * API externe : recoit la question de l'etudiant, l'envoie au gestionnaire IA, renvoie la reponse.
 */
class generate_text extends external_api {
    public static function execute_parameters(): external_function_parameters {
        return new external_function_parameters([
            'contextid' => new external_value(PARAM_INT, 'The context ID', VALUE_REQUIRED),
            'prompttext' => new external_value(PARAM_RAW, 'The question for the AI tutor', VALUE_REQUIRED),
        ]);
    }

    public static function execute(int $contextid, string $prompttext): array {
        global $USER;
        [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
        ] = self::validate_parameters(self::execute_parameters(), [
            'contextid' => $contextid,
            'prompttext' => $prompttext,
        ]);

        $context = \core\context::instance_by_id($contextid);
        self::validate_context($context);

        // Echoue fort si la capacite manque (posture fail-closed, cf. spec section 7).
        require_capability('aiplacement/tuteur:use', $context);
        if (!utils::is_tutor_available($context)) {
            throw new \moodle_exception('notutor', 'aiplacement_tuteur');
        }

        $action = new \core_ai\aiactions\generate_text(
            contextid: $contextid,
            userid: $USER->id,
            prompttext: $prompttext,
        );

        $manager = \core\di::get(\core_ai\manager::class);
        $response = $manager->process_action($action);

        return [
            'success' => $response->get_success(),
            // Ne JAMAIS passer generatedcontent dans format_text() : deja echappe par N4
            // (htmlspecialchars). Toute balise ajoutee ici fait echouer PARAM_TEXT des que la
            // reponse depasse un paragraphe (cf. Global Constraints et spec section 7).
            'generatedcontent' => $response->get_response_data()['generatedcontent'] ?? '',
            'finishreason' => $response->get_response_data()['finishreason'] ?? '',
            'errorcode' => $response->get_errorcode(),
            'error' => $response->get_errormessage(),
            'timecreated' => $response->get_timecreated(),
            'prompttext' => $prompttext,
        ];
    }

    public static function execute_returns(): external_function_parameters {
        return new external_function_parameters([
            'success' => new external_value(PARAM_BOOL, 'Was the request successful', VALUE_REQUIRED),
            'timecreated' => new external_value(PARAM_INT, 'The time the request was created', VALUE_REQUIRED),
            'prompttext' => new external_value(PARAM_RAW, 'The question for the AI tutor', VALUE_REQUIRED),
            'generatedcontent' => new external_value(PARAM_TEXT, 'The tutor answer.', VALUE_DEFAULT, ''),
            'finishreason' => new external_value(PARAM_ALPHAEXT, 'The reason generation was stopped', VALUE_DEFAULT, 'stop'),
            'errorcode' => new external_value(PARAM_INT, 'Error code if any', VALUE_DEFAULT, 0),
            'error' => new external_value(PARAM_TEXT, 'Error message if any', VALUE_DEFAULT, ''),
        ]);
    }
}

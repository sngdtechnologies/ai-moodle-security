<?php
defined('MOODLE_INTERNAL') || die();

$functions = [
    'aiplacement_tuteur_generate_text' => [
        'classname' => \aiplacement_tuteur\external\generate_text::class,
        'description' => 'Generate a tutor answer for the AI tutor placement',
        'type' => 'write',
        'ajax' => true,
        'services' => [MOODLE_OFFICIAL_MOBILE_SERVICE],
    ],
];

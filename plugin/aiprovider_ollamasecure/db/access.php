<?php
defined('MOODLE_INTERNAL') || die();
$capabilities = [
    'aiprovider/ollamasecure:use' => [
        'captype'      => 'write',
        'contextlevel' => CONTEXT_COURSE,
        'archetypes'   => ['student' => CAP_ALLOW, 'teacher' => CAP_ALLOW],
    ],
];

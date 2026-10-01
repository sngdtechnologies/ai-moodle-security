<?php
defined('MOODLE_INTERNAL') || die();

$callbacks = [
    [
        'hook' => \core\hook\output\before_footer_html_generation::class,
        'callback' => \aiplacement_tuteur\hook_callbacks::class . '::before_footer_html_generation',
        'priority' => 0,
    ],
    [
        'hook' => \core\hook\output\after_http_headers::class,
        'callback' => \aiplacement_tuteur\hook_callbacks::class . '::after_http_headers',
        'priority' => 0,
    ],
];

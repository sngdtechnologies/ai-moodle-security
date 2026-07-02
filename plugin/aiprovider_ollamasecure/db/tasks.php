<?php
// ai/provider/ollamasecure/db/tasks.php
defined('MOODLE_INTERNAL') || die();

$tasks = [
    [
        'classname' => 'aiprovider_ollamasecure\\task\\iot_mediation',
        'blocking'  => 0,
        'minute'    => '*/5',
        'hour'      => '*',
        'day'       => '*',
        'month'     => '*',
        'dayofweek' => '*',
    ],
];

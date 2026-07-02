<?php
unset($CFG);
global $CFG;
$CFG = new stdClass();
$CFG->dbtype    = 'mariadb';
$CFG->dblibrary = 'native';
$CFG->dbhost    = 'db';
$CFG->dbname    = 'moodle';
$CFG->dbuser    = 'moodle';
$CFG->dbpass    = trim(file_get_contents('/run/secrets/db_password'));
$CFG->prefix    = 'mdl_';
$CFG->dboptions = ['dbport' => 3306, 'dbsocket' => '', 'dbcollation' => 'utf8mb4_unicode_ci'];
$CFG->wwwroot   = getenv('MOODLE_WWWROOT') ?: 'https://localhost';
$CFG->sslproxy  = 1; // TLS termine par le proxy Caddy ; trafic proxy->moodle en HTTP.
$CFG->dataroot  = '/var/www/moodledata';
$CFG->admin     = 'admin';
$CFG->directorypermissions = 02777;
require_once(__DIR__ . '/lib/setup.php');

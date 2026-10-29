<?php
ob_start();
ini_set('error_log', sys_get_temp_dir() . '/wcms-tests-error.log'); // the theme fallback logs its cause; keep it out of the test output (the render probe pins the line on its own stderr)
define('PHPUNIT_TESTING', true);

require_once __DIR__ . '/../index.php';

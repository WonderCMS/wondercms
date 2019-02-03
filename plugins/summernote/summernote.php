<?php
/**
 * SummerNote plugin for WonderCMS.
 *
 * It transforms all the editable areas into SummerNote inline editor.
 *
 * @author Prakai Nadee <prakai@rmuti.acth>
 * @forked by Robert Isoski @robertisoski
 * @version 3.0.0
 */

if(defined('VERSION'))
    define('version', VERSION);
    defined('version') OR die('Direct access is not allowed.');

    $default_contents_path = 'files';

    wCMS::addListener('js', 'loadSummerNoteJS');
    wCMS::addListener('css', 'loadSummerNoteCSS');
    wCMS::addListener('editable', 'initialSummerNoteVariables');

function initialSummerNoteVariables($contents) {
    $content = $contents[0];
    $subside = $contents[1];

    global $default_contents_path;

    $contents_path = wCMS::getConfig('contents_path');
    if ( ! $contents_path) {
        wCMS::setConfig('contents_path', $default_contents_path);
        $contents_path = $default_contents_path;
    }
    $contents_path_n = trim($contents_path, "/");
    if ($contents_path != $contents_path_n) {
        $contents_path = $contents_path_n;
        wCMS::setConfig('contents_path', $contents_path);
    }
    $_SESSION['contents_path'] = $contents_path;

    return array($content, $subside);
}

function loadSummerNoteJS($args) {
    if (wCMS::$loggedIn) {
        $script = <<<'EOT'
        <script src="https://cdn.jsdelivr.net/npm/summernote@0.8.8/dist/summernote.min.js" integrity="sha384-rv18XCwOLyh2rO/6Vz9eKWaSP+ljKfFed/npYlSg476id+996jfNDF+5iC3W5KKJ" crossorigin="anonymous"></script>
        <script src="plugins/summernote/js/admin.js"></script>
EOT;
    }
    $args[0].=$script;
    return $args;
}
 
function loadSummerNoteCSS($args) {
    if (wCMS::$loggedIn) {
        $script = <<<'EOT'
        <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/summernote@0.8.11/dist/summernote.css" integrity="sha384-hHEPAd3Dkb316VuNPtIZ6LUrzxvD4PQOTW478Ww6c/aUJKXNDV9pEx5/jZgISR1G" crossorigin="anonymous">
        <link rel="stylesheet" href="plugins/summernote/css/admin.css" type="text/css" media="screen">
EOT;
    }
    $args[0].=$script;
    return $args;
}

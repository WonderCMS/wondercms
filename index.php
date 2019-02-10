<?php
/**
 * @package WonderCMS
 * @author Robert Isoski
 * @see https://www.wondercms.com - offical website
 * @license MIT
 */
namespace Robiso\Wondercms;

use Exception;

require_once 'vendor/autoload.php';

session_start();
define('VERSION', '3.0.0');
mb_internal_encoding('UTF-8');

try {
    $Wcms = new Wcms();
    $Wcms->init();
    $Wcms->render();
} catch (Exception $e) {
    echo $e->getMessage();
}

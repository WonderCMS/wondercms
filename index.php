<?php
/**
 * @package WonderCMS
 * @author Robert Isoski
 * @see https://www.wondercms.com - offical website
 * @license MIT
 */
namespace Robiso\Wondercms;

require_once 'vendor/autoload.php';

session_start();
define('VERSION', '2.6.0');
mb_internal_encoding('UTF-8');

$Wcms = new Wcms();
$Wcms->init();
$Wcms->render();

<?php
/**
 * Runs one check in a separate PHP process so a test can disable an extension's functions
 * (php -d disable_functions=... -d disable_classes=...). Prints the result as JSON.
 *
 * php tests/probe.php update <file>            isValidCoreUpdate() on the file's contents
 * php tests/probe.php upload <tmpfile> <name>  uploadFileAction() with that file; prints the alert
 */
PHP_SAPI === 'cli' || exit; // never over HTTP (a git-clone install has tests/ public)
define('PHPUNIT_TESTING', true);
require_once __DIR__ . '/../index.php';

final class ProbeWcms extends Wcms
{
	public function redirect(string $location = ''): void
	{
	}
}

$_SERVER['SERVER_NAME'] = 'wondercms.doc';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$wcms = new ProbeWcms('data_test', 'files_test', 'database_probe.js');

switch ($argv[1] ?? '') {
	case 'update':
		echo json_encode(['tokenizer' => function_exists('token_get_all'), 'valid' => $wcms->isValidCoreUpdate(file_get_contents($argv[2]))]);
		break;
	case 'upload':
		$wcms->loggedIn = true;
		$_SESSION['loggedIn'] = true;
		$_POST['token'] = $_REQUEST['token'] = $wcms->getToken();
		$_FILES['uploadFile'] = ['name' => $argv[3], 'tmp_name' => $argv[2], 'error' => UPLOAD_ERR_OK, 'size' => filesize($argv[2])];
		$wcms->uploadFileAction();
		echo json_encode(['fileinfo' => class_exists('finfo'), 'alert' => $_SESSION['alert'] ?? []]);
		break;
}
@unlink(__DIR__ . '/../data_test/database_probe.js');

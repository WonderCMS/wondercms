<?php
/**
 * Runs one check in a separate PHP process so a test can disable an extension's functions
 * (php -d disable_functions=...) or work on a throwaway copy of index.php. Prints the result as JSON.
 *
 * php tests/probe.php update <file>                 isValidCoreUpdate() on the file's contents
 * php tests/probe.php upload <tmpfile> <name>       uploadFileAction() with that file; prints the alert
 * php tests/probe.php update-run <dir> <newcore>    updateAction() inside <dir> (a copy of the site), downloading <newcore>
 */
PHP_SAPI === 'cli' || exit; // never over HTTP (a git-clone install has tests/ public)
define('PHPUNIT_TESTING', true);
$mode = $argv[1] ?? '';
if ($mode === 'update-run') {
	chdir($argv[2]);
	require_once $argv[2] . '/index.php';
} else {
	require_once __DIR__ . '/../index.php';
}

final class ProbeWcms extends Wcms
{
	/** @var string */
	public static $download = '';

	public function redirect(string $location = ''): void
	{
	}

	public function getFileFromRepo(string $file, string $repo = self::WCMS_REPO): string
	{
		return self::$download;
	}
}

$_SERVER['SERVER_NAME'] = 'wondercms.doc';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$wcms = $mode === 'update-run' ? new ProbeWcms() : new ProbeWcms('data_test', 'files_test', 'database_probe.js');

switch ($mode) {
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
	case 'update-run':
		ProbeWcms::$download = file_get_contents($argv[3]);
		$wcms->loggedIn = true;
		$_SESSION['loggedIn'] = true;
		$_POST['update'] = '1';
		$_POST['token'] = $wcms->getToken();
		$wcms->updateAction();
		echo json_encode([
			'sha' => hash_file('sha256', $argv[2] . '/index.php'),
			'tmpLeft' => file_exists($argv[2] . '/index.php.tmp'),
			'alerts' => array_keys($_SESSION['alert'] ?? []),
		]);
		exit; // nothing to clean up: the copy is the test's
}
@unlink(__DIR__ . '/../data_test/database_probe.js');

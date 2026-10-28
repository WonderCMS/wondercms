<?php
/**
 * Runs one check in a separate PHP process so a test can disable an extension's functions
 * (php -d disable_functions=...) or work on a throwaway copy of index.php. Prints the result as JSON.
 *
 * php tests/probe.php update <file>                 isValidCoreUpdate() on the file's contents
 * php tests/probe.php upload <tmpfile> <name>       uploadFileAction() with that file; prints the alert
 * php tests/probe.php update-run <dir> <newcore>    updateAction() inside <dir> (a copy of the site), downloading <newcore>
 * php tests/probe.php search <query>                searchAction() as a visitor with a valid token; prints its JSON
 * php tests/probe.php locked-save <title>           save() with config.siteTitle set; prints the title read back
 * php tests/probe.php locked-read                   getDb(); prints the title it read (the wait happens in the constructor's read; the test times it from outside)
 * php tests/probe.php render <root> <theme>          loadThemeAndFunctions() as an admin with <theme> active and "Wrong password." queued; prints the page
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
$wcms = $mode === 'update-run' ? new ProbeWcms() : ($mode === 'render' ? new ProbeWcms('data_test', 'files_test', 'database_probe.js', $argv[2]) : new ProbeWcms('data_test', 'files_test', 'database_probe.js'));
if ($mode !== 'update-run' && strpos($mode, 'locked-') !== 0) { // the lock probes work on a database the test owns and inspects afterwards
	register_shutdown_function(static function () { @unlink(__DIR__ . '/../data_test/database_probe.js'); }); // also after exit() inside an action
}

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
	case 'search':
		$_SERVER['REQUEST_METHOD'] = 'POST';
		$_POST['query'] = strpos($argv[2], 'hex:') === 0 ? hex2bin(substr($argv[2], 4)) : $argv[2]; // hex: carries raw bytes past escapeshellarg
		if (isset($argv[3])) {
			$wcms->set('pages', 'home', 'content', $argv[3]); // page text for the excerpt and highlight checks
		}
		$_POST['token'] = $wcms->getToken();
		$m = new ReflectionMethod(Wcms::class, 'searchAction');
		if (PHP_VERSION_ID < 80100) {
			$m->setAccessible(true);
		}
		$m->invoke($wcms); // echoes JSON and exits
		break;
	case 'locked-save':
		$wcms->set('config', 'siteTitle', $argv[2]); // save() takes the exclusive lock; blocks while the test holds one
		echo json_encode(['title' => $wcms->getDb()->config->siteTitle]);
		break;
	case 'render': // run under -d disable_functions=ob_start,... to exercise the unbuffered theme fallback
		$GLOBALS['Wcms'] = $wcms;
		$wcms->loggedIn = true;
		$wcms->set('config', 'theme', $argv[3]);
		$wcms->alert('danger', 'Wrong password.');
		$wcms->loadThemeAndFunctions();
		break;
	case 'locked-read':
		$db = $wcms->getDb(); // the shared lock was already waited for in the constructor's getDb(); this read takes it again
		echo json_encode(['title' => $db->config->siteTitle ?? null]);
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
			'message' => $_SESSION['alert']['danger'][0]['message'] ?? ($_SESSION['alert']['success'][0]['message'] ?? ''),
		]);
		break;
}

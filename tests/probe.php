<?php
/**
 * Runs one check in a separate PHP process so a test can disable an extension's functions
 * (php -d disable_functions=...) or work on a throwaway copy of index.php. Prints the result as JSON.
 *
 * php tests/probe.php update <file>                 isValidCoreUpdate() on the file's contents
 * php tests/probe.php upload <tmpfile> <name>       uploadFileAction() with that file; prints the alert
 * php tests/probe.php update-run <dir> <newcore> [plant]  updateAction() inside <dir> (a copy of the site), downloading <newcore>;
 *                                                   plant = dir | stale | symlink | dangling puts that in the way at the temp path first (fixed by ProbeWcms::tempPath())
 * php tests/probe.php search <query>                searchAction() as a visitor with a valid token; prints its JSON
 * php tests/probe.php locked-save <title>           save() with config.siteTitle set; prints the title read back
 * php tests/probe.php locked-read                   getDb(); prints the title it read (the wait happens in the constructor's read; the test times it from outside)
 * php tests/probe.php locked-json <file>            getJsonFileData() on the file (cache.json's read); prints what it decoded
 * php tests/probe.php locked-zip <root>             createBackupArchive() of <root> into <root>/data_test; prints the archive path
 * php tests/probe.php locked-stale-saves <go> <ms>  reads the database, prints "ready", waits for the file <go> (- for none), then
 *                                                   saves siteTitle from that snapshot for <ms> milliseconds (0: once); prints the count
 * php tests/probe.php module-delete <root> <type> <name>          deleteFileModuleAction() as the admin; prints the alerts
 * php tests/probe.php module-install <root> <type> <name> <zip>  installUpdateModuleAction() as the admin, the module's ZIP at the URL <zip>; prints the alerts
 * php tests/probe.php module-list <root> <type>    listAllModules() as the admin, the official list empty; prints it
 * php tests/probe.php render <root> <theme> [plugin-ob]  loadThemeAndFunctions() as an admin with <theme> active and "Wrong password." queued; prints the page (plugin-ob: inside a plugin's own buffer, then its level)
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
	/** @var bool */
	public static $fixedTemp = false;
	/** @var string */
	public static $moduleZip = '';

	public function redirect(string $location = ''): void
	{
	}

	public function getFileFromRepo(string $file, string $repo = self::WCMS_REPO): string
	{
		return self::$download;
	}

	public function getModulesCachedData(string $type = self::THEMES_DIR): array
	{
		return $GLOBALS['argv'][1] === 'module-list' ? [] : parent::getModulesCachedData($type);
	}

	public function getSingleModuleCachedData(string $moduleKey, string $type = self::THEMES_DIR): array
	{
		return self::$moduleZip === '' ? parent::getSingleModuleCachedData($moduleKey, $type) : ['zip' => self::$moduleZip];
	}

	protected function tempPath(string $path): string
	{
		return self::$fixedTemp ? $path . '.probe.tmp' : parent::tempPath($path); // a fixed name only where update-run plants at it
	}
}

$_SERVER['SERVER_NAME'] = 'wondercms.doc';
$_SERVER['SERVER_PORT'] = '80';
$_SERVER['SCRIPT_NAME'] = '/index.php';
$wcms = $mode === 'update-run' ? new ProbeWcms() : (in_array($mode, ['render', 'locked-zip', 'module-delete', 'module-install', 'module-list'], true) ? new ProbeWcms('data_test', 'files_test', 'database_probe.js', $argv[2]) : new ProbeWcms('data_test', 'files_test', 'database_probe.js'));
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
		$pluginBuffer = ($argv[4] ?? '') === 'plugin-ob';
		if ($pluginBuffer) { // a plugin that opened its own output buffer when plugins loaded, before the theme
			ob_start(static function ($out) { return '[plugin]' . $out . '[/plugin]'; });
		}
		$wcms->loadThemeAndFunctions();
		if ($pluginBuffer) {
			echo ' level ' . ob_get_level();
			ob_end_flush();
		}
		break;
	case 'locked-read':
		$db = $wcms->getDb(); // the shared lock was already waited for in the constructor's getDb(); this read takes it again
		echo json_encode(['title' => $db->config->siteTitle ?? null]);
		break;
	case 'locked-json':
		echo json_encode(['data' => $wcms->getJsonFileData($argv[2])]);
		break;
	case 'locked-stale-saves': // the constructor already read the database: every save below writes that snapshot back through save()
		echo "ready\n";
		while ($argv[2] !== '-' && !file_exists($argv[2])) {
			usleep(500);
		}
		$until = microtime(true) + (int)$argv[3] / 1000;
		$saves = 0;
		do {
			$wcms->set('config', 'siteTitle', 'stale ' . getmypid() . ' ' . ++$saves);
		} while (microtime(true) < $until);
		echo json_encode(['saves' => $saves]);
		break;
	case 'locked-zip':
		echo json_encode(['zips' => [$wcms->createBackupArchive()]]);
		break;
	case 'module-delete':
	case 'module-install':
		$wcms->loggedIn = true;
		$_SESSION['loggedIn'] = true;
		ProbeWcms::$moduleZip = $argv[5] ?? '';
		$_REQUEST = $_GET = [($mode === 'module-delete' ? 'deleteModule' : 'installModule') => $argv[4], 'type' => $argv[3], 'token' => $wcms->getToken()];
		$mode === 'module-delete' ? $wcms->deleteFileModuleAction() : $wcms->installUpdateModuleAction();
		echo json_encode(['alert' => $_SESSION['alert'] ?? []]);
		break;
	case 'module-list':
		$wcms->loggedIn = true;
		echo json_encode(['list' => $wcms->listAllModules($argv[3])]);
		break;
	case 'update-run':
		ProbeWcms::$download = file_get_contents($argv[3]);
		ProbeWcms::$fixedTemp = isset($argv[4]);
		$temp = $argv[2] . '/index.php.probe.tmp'; // ProbeWcms::tempPath() with a plant
		switch ($argv[4] ?? '') {
			case 'dir': // what a full disk looks like to file_put_contents(): the temp cannot be written
				mkdir($temp);
				break;
			case 'stale':
				file_put_contents($temp, 'stale');
				DIRECTORY_SEPARATOR === '/' && chmod($temp, 0444); // Windows cannot unlink a read-only file: there such a leftover still blocks the update, and index.php is untouched
				break;
			case 'symlink':
				symlink($argv[2] . '/victim.txt', $temp);
				break;
			case 'dangling':
				symlink($argv[2] . '/no-such-dir/index.php.tmp', $temp);
				break;
		}
		$wcms->loggedIn = true;
		$_SESSION['loggedIn'] = true;
		$_POST['update'] = '1';
		$_POST['token'] = $wcms->getToken();
		$wcms->updateAction();
		echo json_encode([
			'sha' => hash_file('sha256', $argv[2] . '/index.php'),
			'tmpLeft' => glob($argv[2] . '/index.php.*.tmp') !== [] || file_exists($temp),
			'alerts' => array_keys($_SESSION['alert'] ?? []),
			'message' => $_SESSION['alert']['danger'][0]['message'] ?? ($_SESSION['alert']['success'][0]['message'] ?? ''),
		]);
		break;
}

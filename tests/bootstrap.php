<?php
ob_start();
ini_set('error_log', sys_get_temp_dir() . '/wcms-tests-error.log'); // the theme fallback logs its cause; keep it out of the test output (the render probe pins the line on its own stderr)
define('PHPUNIT_TESTING', true);

require_once __DIR__ . '/../index.php';

/**
 * Leaves $_SESSION as a successful login on $wcms's install leaves it: loggedIn, rootDir and the stamp of the password
 * hash $wcms holds. A test that calls loginStatus() or init() as the admin needs all three since 3.7.2.
 * @param Wcms $wcms
 * @return void
 */
function wcmsAdminSession(Wcms $wcms): void
{
	$call = static function (string $name, ...$args) use ($wcms) {
		$method = new ReflectionMethod(Wcms::class, $name);
		if (PHP_VERSION_ID < 80100) {
			$method->setAccessible(true);
		}
		return $method->invoke($wcms, ...$args);
	};
	$_SESSION['loggedIn'] = true;
	$_SESSION['rootDir'] = $wcms->rootDir;
	$_SESSION['authStamp'] = $call('authStamp', $call('passwordHash'));
}

/**
 * Sets the admin password the way the owner's recovery does, by editing the hash in the database file, and reloads it
 * into $wcms (save() keeps the hash on disk, so set('config', 'password', ...) no longer changes it)
 * @param Wcms $wcms
 * @param string $password
 * @return void
 */
function wcmsSetPasswordOnDisk(Wcms $wcms, string $password): void
{
	$property = new ReflectionProperty(Wcms::class, 'dbPath');
	if (PHP_VERSION_ID < 80100) {
		$property->setAccessible(true);
	}
	$path = $property->getValue($wcms);
	$db = json_decode(file_get_contents($path));
	$db->config->password = password_hash($password, PASSWORD_DEFAULT);
	file_put_contents($path, json_encode($db, JSON_FORCE_OBJECT | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
	$wcms->set('config', 'password', $db->config->password); // the in-memory copy follows; the save finds the same hash on disk
}

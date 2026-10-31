<?php

use PHPUnit\Framework\TestCase;

/**
 * #406: a visitor gets alerts too (the login page's "Wrong password."), but the admin stylesheet is admin-only.
 */
final class AlertStyleTest extends TestCase
{
	/** @var Wcms */
	private $wcms;

	protected function setUp(): void
	{
		$_SERVER['SERVER_NAME'] = 'wondercms.doc';
		$_SERVER['SERVER_PORT'] = '80';
		$this->wcms = $this->getMockBuilder(Wcms::class)
			->setConstructorArgs(['data_test', 'files_test', 'database_test.js'])
			->onlyMethods(['redirect'])
			->getMock();
	}

	protected function tearDown(): void
	{
		unset($_SESSION['alert'], $_SESSION['loggedIn'], $_SESSION['token']);
	}

	public function testAVisitorGetsTheAlertRulesAndNotTheAdminStylesheet(): void
	{
		$this->wcms->loggedIn = false;
		$this->wcms->alert('danger', 'Wrong password.');
		$css = $this->wcms->css();
		$this->assertStringNotContainsString('wcms-admin.min.css', $css);
		$this->assertStringStartsWith('<style>', $css);
		foreach (['.alertWrapper{', '.alertWrapper .alert{', '.alertWrapper .alert-danger{', '.alertWrapper .alert-success{', '.alertWrapper .alert-dismissible{', '.alertWrapper .alert .close{'] as $rule) {
			$this->assertStringContainsString($rule, $css);
		}
		$this->assertStringContainsString('position:fixed', $css);
		$this->assertDoesNotMatchRegularExpression('/:hover|:focus|:checked|@media|url\(/', $css, 'touch and mouse render the same; nothing is fetched');
	}

	public function testEveryVisitorAlertRuleIsScopedUnderTheAlertWrapper(): void
	{
		// a theme or page content with its own Bootstrap-style .alert boxes (wcms-bs5) is not restyled by a core update
		$this->wcms->loggedIn = false;
		$this->wcms->alert('danger', 'Wrong password.');
		$this->assertSame(1, preg_match('#^<style>(.*)</style>$#s', $this->wcms->css(), $m));
		preg_match_all('/([^{}]+)\{[^{}]*\}/', $m[1], $rules);
		$this->assertCount(7, $rules[1]);
		foreach ($rules[1] as $selectors) {
			foreach (explode(',', $selectors) as $selector) {
				$this->assertMatchesRegularExpression('/^\.alertWrapper(?![\w-])/', trim($selector));
			}
		}
	}

	public function testAVisitorPageWithNoAlertGetsNoCoreStyle(): void
	{
		// css() runs on every visitor page: without an alert it returns what 3.7.1 did
		$this->wcms->loggedIn = false;
		$this->assertSame('', $this->wcms->css());
		$this->wcms->addListener('css', static function ($args) { $args[0] .= '/* plugin */'; return $args; });
		$this->assertSame('/* plugin */', $this->wcms->css());
		$_SESSION['alert'] = [];
		$this->assertSame('/* plugin */', $this->wcms->css(), 'an emptied queue shows nothing');
	}

	public function testTheRulesFollowAnAlertTheThemePrintedBeforeCss(): void
	{
		// alerts() empties the queue; a theme that prints them before calling css() still gets the rules (CSS anywhere in the page applies)
		$this->wcms->loggedIn = false;
		$this->wcms->alert('danger', 'Wrong password.');
		$this->assertStringContainsString('Wrong password.', $this->wcms->alerts());
		$this->assertArrayNotHasKey('alert', $_SESSION);
		$this->assertStringContainsString('.alertWrapper .alert-danger{', $this->wcms->css());
	}

	public function testACssListenerThatReplacesItsArgumentKeepsTheVisitorAlertRules(): void
	{
		// the hook keeps its 3.7.1 argument ('' for a visitor); the alert rules are not something a plugin can drop by returning its own string
		$this->wcms->loggedIn = false;
		$this->wcms->alert('danger', 'Wrong password.');
		$received = null;
		$this->wcms->addListener('css', static function ($args) use (&$received) { $received = $args[0]; return ['/* plugin */']; });
		$css = $this->wcms->css();
		$this->assertSame('', $received);
		$this->assertStringContainsString('.alertWrapper .alert .close{', $css);
		$this->assertStringEndsWith('</style>/* plugin */', $css, 'the plugin\'s rules follow the core\'s, as the admin stylesheet\'s listeners do');
	}

	public function testTheAdminGetsTheStylesheetAndNotTheInlineRules(): void
	{
		$this->wcms->loggedIn = true;
		$css = $this->wcms->css();
		$this->assertStringContainsString('wondercms-cdn-files@3.5.0/wcms-admin.min.css', $css);
		$this->assertStringNotContainsString('<style>', $css);
	}

	public function testAVisitorsAlertIsClosedSoThePageIsNotInsideTheFixedWrapper(): void
	{
		// the alert's </div> came from hideAlerts(), which prints nothing for a visitor: the wrapper stayed open to </body>
		$this->wcms->loggedIn = false;
		$this->wcms->alert('danger', 'Wrong password.');
		$this->wcms->alert('success', 'Password changed. Log in again.', true);
		$html = $this->wcms->alerts();
		$this->assertStringContainsString('<button type="button" class="close"', $html);
		$this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'));
		$this->assertStringEndsWith('Wrong password.</div><div class="alert alert-success">Password changed. Log in again.</div></div>', $html);
	}

	public function testTheAdminsAlertKeepsItsHideLink(): void
	{
		$this->wcms->loggedIn = true;
		$this->wcms->alert('danger', 'Page not found.');
		$html = $this->wcms->alerts();
		$this->assertStringContainsString('Hide all alerts until next login', $html);
		$this->assertSame(substr_count($html, '<div'), substr_count($html, '</div>'));
	}
}

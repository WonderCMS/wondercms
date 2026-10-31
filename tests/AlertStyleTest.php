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
		$css = $this->wcms->css();
		$this->assertStringNotContainsString('wcms-admin.min.css', $css);
		$this->assertStringStartsWith('<style>', $css);
		foreach (['.alertWrapper{', '.alertWrapper .alert{', '.alertWrapper .alert-danger{', '.alertWrapper .alert-success{', '.alertWrapper .alert-dismissible{', '.alertWrapper .alert .close{'] as $rule) {
			$this->assertStringContainsString($rule, $css);
		}
		$this->assertStringContainsString('position:fixed', $css);
		$this->assertDoesNotMatchRegularExpression('/:hover|:focus|:checked|@media|url\(/', $css, 'touch and mouse render the same; nothing is fetched');
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

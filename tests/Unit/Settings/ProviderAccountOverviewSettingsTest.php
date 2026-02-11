<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Settings;

use OCA\Mail\AppInfo\Application;
use OCA\Mail\Settings\ProviderAccountOverviewSettings;
use OCP\AppFramework\Http\TemplateResponse;
use Test\TestCase;

class ProviderAccountOverviewSettingsTest extends TestCase {
	private ProviderAccountOverviewSettings $settings;

	protected function setUp(): void {
		parent::setUp();
		$this->settings = new ProviderAccountOverviewSettings();
	}

	public function testGetForm(): void {
		$expected = new TemplateResponse(Application::APP_ID, 'settings-provider-account-overview');

		$result = $this->settings->getForm();

		$this->assertEquals($expected, $result);
	}

	public function testGetSection(): void {
		$result = $this->settings->getSection();

		$this->assertEquals('mail-provider-accounts', $result);
	}

	public function testGetPriority(): void {
		$result = $this->settings->getPriority();

		$this->assertEquals(50, $result);
	}

	public function testGetName(): void {
		$result = $this->settings->getName();

		$this->assertNull($result);
	}

	public function testGetAuthorizedAppConfig(): void {
		$result = $this->settings->getAuthorizedAppConfig();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}
}

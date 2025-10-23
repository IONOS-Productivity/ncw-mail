<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi;
use OCA\Mail\Service\IONOS\IonosMailService;

class IonosMailServiceTest extends TestCase {

	protected function setUp(): void {
		parent::setUp();

		$this->service = new IonosMailService();
	}

	public function testCreateEmailAccountSuccess(): void {
		$emailAddress = 'test@example.com';
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);

		$apiInstance->method('createMailbox')->willReturn(null);

		$result = $this->service->createEmailAccount($emailAddress);

		$this->assertIsArray($result);
		$this->assertTrue($result['success']);
		$this->assertArrayHasKey('mailConfig', $result);
		$this->assertEquals('mail.localhost', $result['mailConfig']['imap']['host']);
		$this->assertEquals($emailAddress, $result['mailConfig']['imap']['username']);
	}
}

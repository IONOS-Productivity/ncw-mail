<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS\Dto;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\Dto\MailServerConfig;

class MailAccountConfigTest extends TestCase {
	private MailServerConfig $imapConfig;
	private MailServerConfig $smtpConfig;
	private MailAccountConfig $accountConfig;

	protected function setUp(): void {
		parent::setUp();

		$this->imapConfig = new MailServerConfig(
			host: 'imap.example.com',
			port: 993,
			security: 'ssl',
			username: 'user@example.com',
			password: 'imap-password',
		);

		$this->smtpConfig = new MailServerConfig(
			host: 'smtp.example.com',
			port: 587,
			security: 'tls',
			username: 'user@example.com',
			password: 'smtp-password',
		);

		$this->accountConfig = new MailAccountConfig(
			email: 'user@example.com',
			imap: $this->imapConfig,
			smtp: $this->smtpConfig,
		);
	}

	public function testConstructor(): void {
		$imap = new MailServerConfig('imap.test.com', 993, 'ssl', 'test@test.com', 'pass1');
		$smtp = new MailServerConfig('smtp.test.com', 465, 'ssl', 'test@test.com', 'pass2');

		$config = new MailAccountConfig(
			email: 'test@test.com',
			imap: $imap,
			smtp: $smtp,
		);

		$this->assertInstanceOf(MailAccountConfig::class, $config);
	}

	public function testGetEmail(): void {
		$this->assertEquals('user@example.com', $this->accountConfig->getEmail());
	}

	public function testGetImap(): void {
		$imap = $this->accountConfig->getImap();

		$this->assertInstanceOf(MailServerConfig::class, $imap);
		$this->assertEquals('imap.example.com', $imap->getHost());
		$this->assertEquals(993, $imap->getPort());
		$this->assertEquals('ssl', $imap->getSecurity());
		$this->assertEquals('user@example.com', $imap->getUsername());
		$this->assertEquals('imap-password', $imap->getPassword());
	}

	public function testGetSmtp(): void {
		$smtp = $this->accountConfig->getSmtp();

		$this->assertInstanceOf(MailServerConfig::class, $smtp);
		$this->assertEquals('smtp.example.com', $smtp->getHost());
		$this->assertEquals(587, $smtp->getPort());
		$this->assertEquals('tls', $smtp->getSecurity());
		$this->assertEquals('user@example.com', $smtp->getUsername());
		$this->assertEquals('smtp-password', $smtp->getPassword());
	}

	public function testToArray(): void {
		$expected = [
			'email' => 'user@example.com',
			'imap' => [
				'host' => 'imap.example.com',
				'port' => 993,
				'security' => 'ssl',
				'username' => 'user@example.com',
				'password' => 'imap-password',
			],
			'smtp' => [
				'host' => 'smtp.example.com',
				'port' => 587,
				'security' => 'tls',
				'username' => 'user@example.com',
				'password' => 'smtp-password',
			],
		];

		$this->assertEquals($expected, $this->accountConfig->toArray());
	}

	public function testToArrayStructure(): void {
		$array = $this->accountConfig->toArray();

		$this->assertIsArray($array);
		$this->assertArrayHasKey('email', $array);
		$this->assertArrayHasKey('imap', $array);
		$this->assertArrayHasKey('smtp', $array);

		$this->assertIsString($array['email']);
		$this->assertIsArray($array['imap']);
		$this->assertIsArray($array['smtp']);

		// Verify IMAP structure
		$this->assertArrayHasKey('host', $array['imap']);
		$this->assertArrayHasKey('port', $array['imap']);
		$this->assertArrayHasKey('security', $array['imap']);
		$this->assertArrayHasKey('username', $array['imap']);
		$this->assertArrayHasKey('password', $array['imap']);

		// Verify SMTP structure
		$this->assertArrayHasKey('host', $array['smtp']);
		$this->assertArrayHasKey('port', $array['smtp']);
		$this->assertArrayHasKey('security', $array['smtp']);
		$this->assertArrayHasKey('username', $array['smtp']);
		$this->assertArrayHasKey('password', $array['smtp']);
	}

	public function testCompleteMailConfiguration(): void {
		$array = $this->accountConfig->toArray();

		// Verify that both IMAP and SMTP configs are complete
		$this->assertEquals('user@example.com', $array['email']);
		$this->assertEquals('imap.example.com', $array['imap']['host']);
		$this->assertEquals('smtp.example.com', $array['smtp']['host']);
		$this->assertNotEquals($array['imap']['password'], $array['smtp']['password']);
	}

	public function testReadonlyProperties(): void {
		// Test immutability by calling methods multiple times
		$email1 = $this->accountConfig->getEmail();
		$email2 = $this->accountConfig->getEmail();
		$this->assertEquals($email1, $email2);

		$imap1 = $this->accountConfig->getImap();
		$imap2 = $this->accountConfig->getImap();
		$this->assertEquals($imap1, $imap2);

		$smtp1 = $this->accountConfig->getSmtp();
		$smtp2 = $this->accountConfig->getSmtp();
		$this->assertEquals($smtp1, $smtp2);

		$array1 = $this->accountConfig->toArray();
		$array2 = $this->accountConfig->toArray();
		$this->assertEquals($array1, $array2);
	}

	public function testDifferentEmailFormats(): void {
		$emails = [
			'simple@example.com',
			'user.name@example.com',
			'user+tag@example.co.uk',
			'user_name@sub.example.com',
		];

		foreach ($emails as $email) {
			$imap = new MailServerConfig('imap.host', 993, 'ssl', $email, 'pass');
			$smtp = new MailServerConfig('smtp.host', 587, 'tls', $email, 'pass');
			$config = new MailAccountConfig($email, $imap, $smtp);

			$this->assertEquals($email, $config->getEmail());
			$this->assertEquals($email, $config->toArray()['email']);
		}
	}

	public function testSameCredentialsForImapAndSmtp(): void {
		$email = 'user@example.com';
		$password = 'shared-password';

		$imap = new MailServerConfig('imap.example.com', 993, 'ssl', $email, $password);
		$smtp = new MailServerConfig('smtp.example.com', 587, 'tls', $email, $password);

		$config = new MailAccountConfig($email, $imap, $smtp);

		$this->assertEquals($password, $config->getImap()->getPassword());
		$this->assertEquals($password, $config->getSmtp()->getPassword());
	}

	public function testDifferentCredentialsForImapAndSmtp(): void {
		$email = 'user@example.com';
		$imapPassword = 'imap-specific-password';
		$smtpPassword = 'smtp-specific-password';

		$imap = new MailServerConfig('imap.example.com', 993, 'ssl', $email, $imapPassword);
		$smtp = new MailServerConfig('smtp.example.com', 587, 'tls', $email, $smtpPassword);

		$config = new MailAccountConfig($email, $imap, $smtp);

		$this->assertEquals($imapPassword, $config->getImap()->getPassword());
		$this->assertEquals($smtpPassword, $config->getSmtp()->getPassword());
		$this->assertNotEquals(
			$config->getImap()->getPassword(),
			$config->getSmtp()->getPassword()
		);
	}

	public function testEmptyEmail(): void {
		$imap = new MailServerConfig('imap.host', 993, 'ssl', '', '');
		$smtp = new MailServerConfig('smtp.host', 587, 'tls', '', '');
		$config = new MailAccountConfig('', $imap, $smtp);

		$this->assertEquals('', $config->getEmail());
		$this->assertEquals('', $config->toArray()['email']);
	}

	public function testToArrayBackwardsCompatibility(): void {
		// Ensure the array format is compatible with existing code expecting this structure
		$array = $this->accountConfig->toArray();

		// Top-level keys
		$this->assertCount(3, $array);
		$this->assertArrayHasKey('email', $array);
		$this->assertArrayHasKey('imap', $array);
		$this->assertArrayHasKey('smtp', $array);

		// Server config keys
		$serverKeys = ['host', 'port', 'security', 'username', 'password'];
		foreach ($serverKeys as $key) {
			$this->assertArrayHasKey($key, $array['imap'], "IMAP missing key: $key");
			$this->assertArrayHasKey($key, $array['smtp'], "SMTP missing key: $key");
		}
	}

	public function testNestedObjectAccess(): void {
		// Test that we can chain method calls
		$imapHost = $this->accountConfig->getImap()->getHost();
		$smtpHost = $this->accountConfig->getSmtp()->getHost();

		$this->assertEquals('imap.example.com', $imapHost);
		$this->assertEquals('smtp.example.com', $smtpHost);
	}

	public function testWithPassword(): void {
		$newPassword = 'new-secure-password';

		// Create a new config with updated password
		$updatedConfig = $this->accountConfig->withPassword($newPassword);

		// Original config should remain unchanged (immutable)
		$this->assertEquals('imap-password', $this->accountConfig->getImap()->getPassword());
		$this->assertEquals('smtp-password', $this->accountConfig->getSmtp()->getPassword());

		// New config should have the new password for both IMAP and SMTP
		$this->assertEquals($newPassword, $updatedConfig->getImap()->getPassword());
		$this->assertEquals($newPassword, $updatedConfig->getSmtp()->getPassword());

		// Other properties should remain the same
		$this->assertEquals($this->accountConfig->getEmail(), $updatedConfig->getEmail());
		$this->assertEquals($this->accountConfig->getImap()->getHost(), $updatedConfig->getImap()->getHost());
		$this->assertEquals($this->accountConfig->getImap()->getPort(), $updatedConfig->getImap()->getPort());
		$this->assertEquals($this->accountConfig->getImap()->getSecurity(), $updatedConfig->getImap()->getSecurity());
		$this->assertEquals($this->accountConfig->getImap()->getUsername(), $updatedConfig->getImap()->getUsername());
		$this->assertEquals($this->accountConfig->getSmtp()->getHost(), $updatedConfig->getSmtp()->getHost());
		$this->assertEquals($this->accountConfig->getSmtp()->getPort(), $updatedConfig->getSmtp()->getPort());
		$this->assertEquals($this->accountConfig->getSmtp()->getSecurity(), $updatedConfig->getSmtp()->getSecurity());
		$this->assertEquals($this->accountConfig->getSmtp()->getUsername(), $updatedConfig->getSmtp()->getUsername());
	}
}

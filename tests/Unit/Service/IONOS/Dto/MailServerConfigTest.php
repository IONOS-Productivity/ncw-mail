<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS\Dto;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Service\IONOS\Dto\MailServerConfig;

class MailServerConfigTest extends TestCase {
	private MailServerConfig $config;

	protected function setUp(): void {
		parent::setUp();

		$this->config = new MailServerConfig(
			host: 'imap.example.com',
			port: 993,
			security: 'ssl',
			username: 'user@example.com',
			password: 'secret123',
		);
	}

	public function testConstructor(): void {
		$config = new MailServerConfig(
			host: 'smtp.example.com',
			port: 465,
			security: 'tls',
			username: 'test@example.com',
			password: 'password123',
		);

		$this->assertInstanceOf(MailServerConfig::class, $config);
	}

	public function testGetHost(): void {
		$this->assertEquals('imap.example.com', $this->config->getHost());
	}

	public function testGetPort(): void {
		$this->assertEquals(993, $this->config->getPort());
	}

	public function testGetSecurity(): void {
		$this->assertEquals('ssl', $this->config->getSecurity());
	}

	public function testGetUsername(): void {
		$this->assertEquals('user@example.com', $this->config->getUsername());
	}

	public function testGetPassword(): void {
		$this->assertEquals('secret123', $this->config->getPassword());
	}

	public function testToArray(): void {
		$expected = [
			'host' => 'imap.example.com',
			'port' => 993,
			'security' => 'ssl',
			'username' => 'user@example.com',
			'password' => 'secret123',
		];

		$this->assertEquals($expected, $this->config->toArray());
	}

	public function testToArrayStructure(): void {
		$array = $this->config->toArray();

		$this->assertIsArray($array);
		$this->assertArrayHasKey('host', $array);
		$this->assertArrayHasKey('port', $array);
		$this->assertArrayHasKey('security', $array);
		$this->assertArrayHasKey('username', $array);
		$this->assertArrayHasKey('password', $array);
		$this->assertIsString($array['host']);
		$this->assertIsInt($array['port']);
		$this->assertIsString($array['security']);
		$this->assertIsString($array['username']);
		$this->assertIsString($array['password']);
	}

	public function testImapConfiguration(): void {
		$imapConfig = new MailServerConfig(
			host: 'imap.example.com',
			port: 993,
			security: 'ssl',
			username: 'user@example.com',
			password: 'imap-password',
		);

		$this->assertEquals('imap.example.com', $imapConfig->getHost());
		$this->assertEquals(993, $imapConfig->getPort());
		$this->assertEquals('ssl', $imapConfig->getSecurity());
	}

	public function testSmtpConfiguration(): void {
		$smtpConfig = new MailServerConfig(
			host: 'smtp.example.com',
			port: 587,
			security: 'tls',
			username: 'user@example.com',
			password: 'smtp-password',
		);

		$this->assertEquals('smtp.example.com', $smtpConfig->getHost());
		$this->assertEquals(587, $smtpConfig->getPort());
		$this->assertEquals('tls', $smtpConfig->getSecurity());
	}

	public function testReadonlyProperties(): void {
		// Test that properties are immutable by attempting to convert to array multiple times
		$array1 = $this->config->toArray();
		$array2 = $this->config->toArray();

		$this->assertEquals($array1, $array2);
		$this->assertEquals('imap.example.com', $this->config->getHost());
	}

	public function testEmptyStringValues(): void {
		$config = new MailServerConfig(
			host: '',
			port: 0,
			security: '',
			username: '',
			password: '',
		);

		$this->assertEquals('', $config->getHost());
		$this->assertEquals(0, $config->getPort());
		$this->assertEquals('', $config->getSecurity());
		$this->assertEquals('', $config->getUsername());
		$this->assertEquals('', $config->getPassword());
	}

	public function testDifferentPortNumbers(): void {
		$configs = [
			new MailServerConfig('host', 143, 'none', 'user', 'pass'), // IMAP
			new MailServerConfig('host', 993, 'ssl', 'user', 'pass'),  // IMAPS
			new MailServerConfig('host', 25, 'none', 'user', 'pass'),  // SMTP
			new MailServerConfig('host', 465, 'ssl', 'user', 'pass'),  // SMTPS
			new MailServerConfig('host', 587, 'tls', 'user', 'pass'),  // SMTP Submission
		];

		$this->assertEquals(143, $configs[0]->getPort());
		$this->assertEquals(993, $configs[1]->getPort());
		$this->assertEquals(25, $configs[2]->getPort());
		$this->assertEquals(465, $configs[3]->getPort());
		$this->assertEquals(587, $configs[4]->getPort());
	}

	public function testDifferentSecurityTypes(): void {
		$sslConfig = new MailServerConfig('host', 993, 'ssl', 'user', 'pass');
		$tlsConfig = new MailServerConfig('host', 587, 'tls', 'user', 'pass');
		$noneConfig = new MailServerConfig('host', 143, 'none', 'user', 'pass');

		$this->assertEquals('ssl', $sslConfig->getSecurity());
		$this->assertEquals('tls', $tlsConfig->getSecurity());
		$this->assertEquals('none', $noneConfig->getSecurity());
	}

	public function testWithPassword(): void {
		$newConfig = $this->config->withPassword('newPassword123');

		$this->assertEquals('newPassword123', $newConfig->getPassword());
		// Verify other properties remain unchanged
		$this->assertEquals('imap.example.com', $newConfig->getHost());
		$this->assertEquals(993, $newConfig->getPort());
		$this->assertEquals('ssl', $newConfig->getSecurity());
		$this->assertEquals('user@example.com', $newConfig->getUsername());
		// Verify original config is unchanged (immutability)
		$this->assertEquals('secret123', $this->config->getPassword());
	}
}

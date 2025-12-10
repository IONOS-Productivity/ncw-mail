<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\Dto\MailServerConfig;
use OCA\Mail\Service\IONOS\IonosAccountConflictResolver;
use OCA\Mail\Service\IONOS\IonosConfigService;
use OCA\Mail\Service\IONOS\IonosMailService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosAccountConflictResolverTest extends TestCase {

	private IonosMailService&MockObject $ionosMailService;

	private IonosConfigService&MockObject $ionosConfigService;

	private LoggerInterface&MockObject $logger;

	private IonosAccountConflictResolver $resolver;

	protected function setUp(): void {
		parent::setUp();

		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->ionosConfigService = $this->createMock(IonosConfigService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->resolver = new IonosAccountConflictResolver(
			$this->ionosMailService,
			$this->ionosConfigService,
			$this->logger,
		);
	}

	public function testResolveConflictWithNoExistingAccount(): void {
		$userId = 'testuser';
		$emailUser = 'test';

		$this->ionosMailService->method('getAccountConfigForUser')
			->with($userId)
			->willReturn(null);

		$this->logger
			->expects($this->once())
			->method('debug')
			->with('No existing IONOS account found for conflict resolution', ['userId' => $userId]);

		$result = $this->resolver->resolveConflict($userId, $emailUser);

		$this->assertFalse($result->canRetry());
		$this->assertNull($result->getAccountConfig());
		$this->assertFalse($result->hasEmailMismatch());
	}

	public function testResolveConflictWithMatchingEmail(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';
		$newPassword = 'new-app-password-123';

		// Create MailAccountConfig DTO without password (as API returns)
		$imapConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1143,
			security: 'none',
			username: $emailAddress,
			password: '', // Empty password from getAccountConfigForUser
		);

		$smtpConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1587,
			security: 'none',
			username: $emailAddress,
			password: '', // Empty password from getAccountConfigForUser
		);

		$mailAccountConfig = new MailAccountConfig(
			email: $emailAddress,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);

		$this->ionosMailService->method('getAccountConfigForUser')
			->with($userId)
			->willReturn($mailAccountConfig);

		$this->ionosConfigService->method('getMailDomain')
			->willReturn($domain);

		// Expect resetAppPassword to be called
		$this->ionosMailService
			->expects($this->once())
			->method('resetAppPassword')
			->with($userId, 'NEXTCLOUD_WORKSPACE')
			->willReturn($newPassword);

		$this->logger
			->expects($this->once())
			->method('info')
			->with(
				'IONOS account already exists, retrieving new password for retry',
				['emailAddress' => $emailAddress, 'userId' => $userId]
			);

		$result = $this->resolver->resolveConflict($userId, $emailUser);

		$this->assertTrue($result->canRetry());
		$this->assertNotNull($result->getAccountConfig());
		$this->assertFalse($result->hasEmailMismatch());

		// Verify the returned config has the new password
		$resultConfig = $result->getAccountConfig();
		$this->assertEquals($newPassword, $resultConfig->getImap()->getPassword());
		$this->assertEquals($newPassword, $resultConfig->getSmtp()->getPassword());
	}

	public function testResolveConflictWithEmailMismatch(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$domain = 'example.com';
		$expectedEmail = 'test@example.com';
		$existingEmail = 'different@example.com';

		// Create MailAccountConfig DTO with different email
		$imapConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1143,
			security: 'none',
			username: $existingEmail,
			password: 'tmp',
		);

		$smtpConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1587,
			security: 'none',
			username: $existingEmail,
			password: 'tmp',
		);

		$mailAccountConfig = new MailAccountConfig(
			email: $existingEmail,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);

		$this->ionosMailService->method('getAccountConfigForUser')
			->with($userId)
			->willReturn($mailAccountConfig);

		$this->ionosConfigService->method('getMailDomain')
			->willReturn($domain);

		$this->logger
			->expects($this->once())
			->method('warning')
			->with(
				'IONOS account exists but email mismatch',
				['requestedEmail' => $expectedEmail, 'existingEmail' => $existingEmail, 'userId' => $userId]
			);

		$result = $this->resolver->resolveConflict($userId, $emailUser);

		$this->assertFalse($result->canRetry());
		$this->assertNull($result->getAccountConfig());
		$this->assertTrue($result->hasEmailMismatch());
		$this->assertEquals($expectedEmail, $result->getExpectedEmail());
		$this->assertEquals($existingEmail, $result->getExistingEmail());
	}
}

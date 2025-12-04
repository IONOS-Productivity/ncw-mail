<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Service\IONOS\ConflictResolutionResult;
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
		$emailUser = 'test';

		$this->ionosMailService->method('getAccountConfigForCurrentUser')
			->willReturn(null);

		$this->logger
			->expects($this->once())
			->method('debug')
			->with('No existing IONOS account found for conflict resolution');

		$result = $this->resolver->resolveConflict($emailUser);

		$this->assertFalse($result->canRetry());
		$this->assertNull($result->getAccountConfig());
		$this->assertFalse($result->hasEmailMismatch());
	}

	public function testResolveConflictWithMatchingEmail(): void {
		$emailUser = 'test';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';

		// Create MailAccountConfig DTO
		$imapConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1143,
			security: 'none',
			username: $emailAddress,
			password: 'tmp',
		);

		$smtpConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1587,
			security: 'none',
			username: $emailAddress,
			password: 'tmp',
		);

		$mailAccountConfig = new MailAccountConfig(
			email: $emailAddress,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);

		$this->ionosMailService->method('getAccountConfigForCurrentUser')
			->willReturn($mailAccountConfig);

		$this->ionosConfigService->method('getMailDomain')
			->willReturn($domain);

		$this->logger
			->expects($this->once())
			->method('info')
			->with(
				'IONOS account already exists, retrieving configuration for retry',
				['emailAddress' => $emailAddress]
			);

		$result = $this->resolver->resolveConflict($emailUser);

		$this->assertTrue($result->canRetry());
		$this->assertSame($mailAccountConfig, $result->getAccountConfig());
		$this->assertFalse($result->hasEmailMismatch());
	}

	public function testResolveConflictWithEmailMismatch(): void {
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

		$this->ionosMailService->method('getAccountConfigForCurrentUser')
			->willReturn($mailAccountConfig);

		$this->ionosConfigService->method('getMailDomain')
			->willReturn($domain);

		$this->logger
			->expects($this->once())
			->method('warning')
			->with(
				'IONOS account exists but email mismatch',
				['requestedEmail' => $expectedEmail, 'existingEmail' => $existingEmail]
			);

		$result = $this->resolver->resolveConflict($emailUser);

		$this->assertFalse($result->canRetry());
		$this->assertNull($result->getAccountConfig());
		$this->assertTrue($result->hasEmailMismatch());
		$this->assertEquals($expectedEmail, $result->getExpectedEmail());
		$this->assertEquals($existingEmail, $result->getExistingEmail());
	}
}

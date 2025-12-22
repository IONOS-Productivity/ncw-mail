<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations\Ionos\Service;

use OCA\Mail\Db\MailAccount;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosAccountDeletionService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosMailService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class IonosAccountDeletionServiceTest extends TestCase {
	private IonosMailService&MockObject $ionosMailService;
	private IonosConfigService&MockObject $ionosConfigService;
	private LoggerInterface&MockObject $logger;
	private IonosAccountDeletionService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->ionosConfigService = $this->createMock(IonosConfigService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new IonosAccountDeletionService(
			$this->ionosMailService,
			$this->ionosConfigService,
			$this->logger,
		);
	}

	public function testHandleMailAccountDeletionWhenIonosIntegrationDisabled(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(33);
		$mailAccount->setUserId('testuser');
		$mailAccount->setEmail('testuser@example.com');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(false);

		// IONOS mailbox deletion should NOT be attempted when integration is disabled
		$this->ionosMailService->expects($this->never())
			->method('tryDeleteEmailAccount');

		$this->service->handleMailAccountDeletion($mailAccount);
	}

	public function testHandleMailAccountDeletionForIonosAccount(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(33);
		$mailAccount->setUserId('testuser');
		$mailAccount->setEmail('testuser@example.com');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);
		$this->ionosConfigService->expects($this->once())
			->method('getMailDomain')
			->willReturn('example.com');

		// Should check the IONOS provisioned email
		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('testuser@example.com');

		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Detected IONOS mail account deletion, attempting to delete IONOS mailbox',
				[
					'email' => 'testuser@example.com',
					'userId' => 'testuser',
					'accountId' => 33,
				]
			);

		$this->ionosMailService->expects($this->once())
			->method('tryDeleteEmailAccount')
			->with('testuser');

		$this->service->handleMailAccountDeletion($mailAccount);
	}

	public function testHandleMailAccountDeletionForNonIonosAccount(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(33);
		$mailAccount->setUserId('testuser');
		$mailAccount->setEmail('testuser@otherdomain.com');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);
		$this->ionosConfigService->expects($this->once())
			->method('getMailDomain')
			->willReturn('example.com');

		// Should not check IONOS email for non-IONOS domain
		$this->ionosMailService->expects($this->never())
			->method('getIonosEmailForUser');

		// IONOS mailbox deletion should NOT be called for non-IONOS domain
		$this->ionosMailService->expects($this->never())
			->method('tryDeleteEmailAccount');

		$this->service->handleMailAccountDeletion($mailAccount);
	}

	public function testHandleMailAccountDeletionWithEmptyDomain(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(33);
		$mailAccount->setUserId('testuser');
		$mailAccount->setEmail('testuser@example.com');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);
		$this->ionosConfigService->expects($this->once())
			->method('getMailDomain')
			->willReturn('');

		// IONOS mailbox deletion should NOT be called when domain is empty
		$this->ionosMailService->expects($this->never())
			->method('tryDeleteEmailAccount');

		$this->service->handleMailAccountDeletion($mailAccount);
	}

	public function testHandleMailAccountDeletionWithException(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(33);
		$mailAccount->setUserId('testuser');
		$mailAccount->setEmail('testuser@example.com');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);
		$this->ionosConfigService->expects($this->once())
			->method('getMailDomain')
			->willThrowException(new \RuntimeException('Test exception'));

		// Exception should be caught and logged
		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Error checking/deleting IONOS mailbox during account deletion',
				$this->callback(function ($context) {
					return isset($context['exception'])
						   && $context['exception'] instanceof \RuntimeException
						   && $context['accountId'] === 33;
				})
			);

		// Should not throw exception
		$this->service->handleMailAccountDeletion($mailAccount);
	}

	public function testHandleMailAccountDeletionCaseInsensitiveDomain(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(33);
		$mailAccount->setUserId('testuser');
		$mailAccount->setEmail('testuser@EXAMPLE.COM');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);
		$this->ionosConfigService->expects($this->once())
			->method('getMailDomain')
			->willReturn('example.com');

		// Should check the IONOS provisioned email (case-insensitive match)
		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('testuser@example.com');

		$this->ionosMailService->expects($this->once())
			->method('tryDeleteEmailAccount')
			->with('testuser');

		$this->service->handleMailAccountDeletion($mailAccount);
	}

	public function testHandleMailAccountDeletionWhenNoIonosAccountProvisioned(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(33);
		$mailAccount->setUserId('testuser');
		$mailAccount->setEmail('testuser@example.com');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);
		$this->ionosConfigService->expects($this->once())
			->method('getMailDomain')
			->willReturn('example.com');

		// User manually configured an account with IONOS domain but no provisioned account exists
		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn(null);

		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'No IONOS provisioned account found for user, skipping deletion',
				[
					'email' => 'testuser@example.com',
					'userId' => 'testuser',
					'accountId' => 33,
				]
			);

		// IONOS mailbox deletion should NOT be called when no provisioned account exists
		$this->ionosMailService->expects($this->never())
			->method('tryDeleteEmailAccount');

		$this->service->handleMailAccountDeletion($mailAccount);
	}

	public function testHandleMailAccountDeletionWhenEmailMismatch(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(33);
		$mailAccount->setUserId('testuser');
		$mailAccount->setEmail('different@example.com');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);
		$this->ionosConfigService->expects($this->once())
			->method('getMailDomain')
			->willReturn('example.com');

		// User has a provisioned IONOS account but is deleting a different account with the same domain
		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('testuser@example.com');

		$this->logger->expects($this->once())
			->method('warning')
			->with(
				'Mail account email does not match IONOS provisioned email, skipping deletion',
				[
					'accountEmail' => 'different@example.com',
					'ionosEmail' => 'testuser@example.com',
					'userId' => 'testuser',
					'accountId' => 33,
				]
			);

		// IONOS mailbox deletion should NOT be called when email doesn't match
		$this->ionosMailService->expects($this->never())
			->method('tryDeleteEmailAccount');

		$this->service->handleMailAccountDeletion($mailAccount);
	}
}

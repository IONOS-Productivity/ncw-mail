<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations\Ionos\Service;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosMailConfigService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosMailService;
use OCA\Mail\Service\AccountService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosMailConfigServiceTest extends TestCase {
	private IonosConfigService&MockObject $ionosConfigService;
	private IonosMailService&MockObject $ionosMailService;
	private AccountService&MockObject $accountService;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private IonosMailConfigService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->ionosConfigService = $this->createMock(IonosConfigService::class);
		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new IonosMailConfigService(
			$this->ionosConfigService,
			$this->ionosMailService,
			$this->accountService,
			$this->userSession,
			$this->logger,
		);
	}

	public function testIsMailConfigAvailableReturnsFalseWhenFeatureDisabled(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(false);

		$this->userSession->expects($this->never())
			->method('getUser');

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableReturnsFalseWhenNoUserSession(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mail config not available - no user session');

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableReturnsTrueWhenUserHasNoRemoteAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(false);

		$this->accountService->expects($this->never())
			->method('findByUserIdAndAddress');

		$result = $this->service->isMailConfigAvailable();

		$this->assertTrue($result);
	}

	public function testIsMailConfigAvailableReturnsFalseWhenUserHasRemoteAndLocalAccount(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(true);

		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('testuser@ionos.com');

		// Return a non-empty array to simulate that a local account exists
		$mockAccount = $this->createMock(\OCA\Mail\Account::class);
		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with('testuser', 'testuser@ionos.com')
			->willReturn([$mockAccount]);

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mail config not available - user already has account configured locally', [
				'email' => 'testuser@ionos.com',
			]);

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableReturnsTrueWhenUserHasRemoteAccountButNotLocal(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(true);

		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('testuser@ionos.com');

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with('testuser', 'testuser@ionos.com')
			->willReturn([]);

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mail config available - remote account exists but not configured locally', [
				'email' => 'testuser@ionos.com',
			]);

		$result = $this->service->isMailConfigAvailable();

		$this->assertTrue($result);
	}

	public function testIsMailConfigAvailableReturnsFalseWhenEmailCannotBeRetrieved(): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser');

		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(true);

		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn(null);

		$this->logger->expects($this->once())
			->method('warning')
			->with('IONOS remote account exists but email could not be retrieved');

		$this->accountService->expects($this->never())
			->method('findByUserIdAndAddress');

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableWithExplicitUserIdReturnsTrueWhenUserHasNoRemoteAccount(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		// When userId is provided, userSession should NOT be called
		$this->userSession->expects($this->never())
			->method('getUser');

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(false);

		$this->accountService->expects($this->never())
			->method('findByUserIdAndAddress');

		$result = $this->service->isMailConfigAvailable('explicituser');

		$this->assertTrue($result);
	}

	public function testIsMailConfigAvailableWithExplicitUserIdReturnsTrueWhenUserHasRemoteAccountButNotLocal(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		// When userId is provided, userSession should NOT be called
		$this->userSession->expects($this->never())
			->method('getUser');

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(true);

		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with('explicituser')
			->willReturn('explicituser@ionos.com');

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with('explicituser', 'explicituser@ionos.com')
			->willReturn([]);

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mail config available - remote account exists but not configured locally', [
				'email' => 'explicituser@ionos.com',
			]);

		$result = $this->service->isMailConfigAvailable('explicituser');

		$this->assertTrue($result);
	}

	public function testIsMailConfigAvailableReturnsFalseOnException(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$exception = new \Exception('Test exception');

		$this->userSession->expects($this->once())
			->method('getUser')
			->willThrowException($exception);

		$this->logger->expects($this->once())
			->method('error')
			->with('Error checking IONOS mail config availability', $this->callback(function ($context) use ($exception) {
				return isset($context['exception']) && $context['exception'] === $exception;
			}));

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}
}

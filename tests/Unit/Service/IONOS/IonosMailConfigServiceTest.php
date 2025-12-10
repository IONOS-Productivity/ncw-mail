<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\IONOS\IonosConfigService;
use OCA\Mail\Service\IONOS\IonosMailConfigService;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosMailConfigServiceTest extends TestCase {
	private const TEST_USER_ID = 'testuser123';
	private const TEST_EMAIL = 'test@example.com';

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

	/**
	 * Setup user session with mock user
	 */
	private function setupUserSession(string $userId = self::TEST_USER_ID): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}

	public function testIsMailConfigAvailableReturnsFalseWhenFeatureDisabled(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(false);

		$this->userSession->expects($this->never())
			->method('getUser');

		$this->ionosMailService->expects($this->never())
			->method('mailAccountExistsForCurrentUser');

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

		$this->ionosMailService->expects($this->never())
			->method('mailAccountExistsForCurrentUser');

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableReturnsTrueWhenUserHasNoAccount(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->setupUserSession();

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(false);

		$this->ionosMailService->expects($this->never())
			->method('getIonosEmailForUser');

		$this->accountService->expects($this->never())
			->method('findByUserIdAndAddress');

		$result = $this->service->isMailConfigAvailable();

		$this->assertTrue($result);
	}

	public function testIsMailConfigAvailableReturnsFalseWhenUserHasRemoteAndLocalAccount(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->setupUserSession();

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(true);

		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with(self::TEST_USER_ID)
			->willReturn(self::TEST_EMAIL);

		// Mock that user has local account configured
		$account = $this->createMock(Account::class);
		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with(self::TEST_USER_ID, self::TEST_EMAIL)
			->willReturn([$account]);

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mail config not available - user already has account configured locally', [
				'email' => self::TEST_EMAIL,
			]);

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableReturnsTrueWhenRemoteAccountExistsButNotConfiguredLocally(): void {
		// This is the retry scenario: IONOS account was created remotely,
		// but local Nextcloud account creation failed (e.g., DNS propagation issue)
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->setupUserSession();

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(true);

		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with(self::TEST_USER_ID)
			->willReturn(self::TEST_EMAIL);

		// Mock that user has NO local account configured (empty array)
		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with(self::TEST_USER_ID, self::TEST_EMAIL)
			->willReturn([]);

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mail config available - remote account exists but not configured locally', [
				'email' => self::TEST_EMAIL,
			]);

		$result = $this->service->isMailConfigAvailable();

		$this->assertTrue($result);
	}

	public function testIsMailConfigAvailableReturnsFalseWhenRemoteAccountExistsButEmailCannotBeRetrieved(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->setupUserSession();

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(true);

		$this->ionosMailService->expects($this->once())
			->method('getIonosEmailForUser')
			->with(self::TEST_USER_ID)
			->willReturn(null);

		$this->accountService->expects($this->never())
			->method('findByUserIdAndAddress');

		$this->logger->expects($this->once())
			->method('warning')
			->with('IONOS remote account exists but email could not be retrieved');

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableReturnsFalseOnException(): void {
		$this->ionosConfigService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->setupUserSession();

		$exception = new \Exception('Test exception');

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
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

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations\Ionos;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\IonosProviderFacade;
use OCA\Mail\Service\IONOS\Core\IonosAccountMutationService;
use OCA\Mail\Service\IONOS\Core\IonosAccountQueryService;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosConfigService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosProviderFacadeTest extends TestCase {
	private IonosConfigService&MockObject $configService;
	private IonosAccountQueryService&MockObject $queryService;
	private IonosAccountMutationService&MockObject $mutationService;
	private IonosAccountCreationService&MockObject $creationService;
	private LoggerInterface&MockObject $logger;
	private IonosProviderFacade $facade;

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createMock(IonosConfigService::class);
		$this->queryService = $this->createMock(IonosAccountQueryService::class);
		$this->mutationService = $this->createMock(IonosAccountMutationService::class);
		$this->creationService = $this->createMock(IonosAccountCreationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->facade = new IonosProviderFacade(
			$this->configService,
			$this->queryService,
			$this->mutationService,
			$this->creationService,
			$this->logger,
		);
	}

	public function testIsEnabledReturnsTrue(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$result = $this->facade->isEnabled();

		$this->assertTrue($result);
	}

	public function testIsEnabledReturnsFalse(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(false);

		$result = $this->facade->isEnabled();

		$this->assertFalse($result);
	}

	public function testIsEnabledHandlesException(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willThrowException(new \Exception('Config error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS provider is not enabled', $this->anything());

		$result = $this->facade->isEnabled();

		$this->assertFalse($result);
	}

	public function testIsAvailableForUserReturnsTrueWhenNoAccount(): void {
		$userId = 'user123';

		$this->queryService->expects($this->once())
			->method('mailAccountExistsForUserId')
			->with($userId)
			->willReturn(false);

		$result = $this->facade->isAvailableForUser($userId);

		$this->assertTrue($result);
	}

	public function testIsAvailableForUserReturnsFalseWhenAccountExists(): void {
		$userId = 'user123';

		$this->queryService->expects($this->once())
			->method('mailAccountExistsForUserId')
			->with($userId)
			->willReturn(true);

		$result = $this->facade->isAvailableForUser($userId);

		$this->assertFalse($result);
	}

	public function testIsAvailableForUserHandlesException(): void {
		$userId = 'user123';

		$this->queryService->expects($this->once())
			->method('mailAccountExistsForUserId')
			->with($userId)
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('error')
			->with('Error checking IONOS availability for user', $this->anything());

		$result = $this->facade->isAvailableForUser($userId);

		$this->assertFalse($result);
	}

	public function testCreateAccountSuccess(): void {
		$userId = 'user123';
		$emailUser = 'john.doe';
		$accountName = 'John Doe';
		$account = $this->createMock(Account::class);

		$this->logger->expects($this->once())
			->method('info')
			->with('Creating IONOS account via facade', [
				'userId' => $userId,
				'emailUser' => $emailUser,
			]);

		$this->creationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willReturn($account);

		$result = $this->facade->createAccount($userId, $emailUser, $accountName);

		$this->assertSame($account, $result);
	}

	public function testUpdateAccountSuccess(): void {
		$userId = 'user123';
		$emailUser = 'john.doe';
		$accountName = 'John Doe';
		$account = $this->createMock(Account::class);

		$this->logger->expects($this->once())
			->method('info')
			->with('Updating IONOS account via facade', [
				'userId' => $userId,
				'emailUser' => $emailUser,
			]);

		$this->creationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willReturn($account);

		$result = $this->facade->updateAccount($userId, $emailUser, $accountName);

		$this->assertSame($account, $result);
	}

	public function testDeleteAccountSuccess(): void {
		$userId = 'user123';

		$this->logger->expects($this->once())
			->method('info')
			->with('Deleting IONOS account via facade', [
				'userId' => $userId,
			]);

		$this->mutationService->expects($this->once())
			->method('tryDeleteEmailAccount')
			->with($userId);

		$result = $this->facade->deleteAccount($userId);

		$this->assertTrue($result);
	}

	public function testDeleteAccountHandlesException(): void {
		$userId = 'user123';

		$this->logger->expects($this->once())
			->method('info')
			->with('Deleting IONOS account via facade', [
				'userId' => $userId,
			]);

		$this->mutationService->expects($this->once())
			->method('tryDeleteEmailAccount')
			->with($userId)
			->willThrowException(new \Exception('Deletion failed'));

		$this->logger->expects($this->once())
			->method('error')
			->with('Error deleting IONOS account via facade', $this->anything());

		$result = $this->facade->deleteAccount($userId);

		$this->assertFalse($result);
	}

	public function testGetProvisionedEmailSuccess(): void {
		$userId = 'user123';
		$email = 'user@ionos.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn($email);

		$result = $this->facade->getProvisionedEmail($userId);

		$this->assertSame($email, $result);
	}

	public function testGetProvisionedEmailHandlesException(): void {
		$userId = 'user123';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Error getting IONOS provisioned email', $this->anything());

		$result = $this->facade->getProvisionedEmail($userId);

		$this->assertNull($result);
	}

	public function testManagesEmailReturnsTrue(): void {
		$userId = 'user123';
		$email = 'user@ionos.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn($email);

		$result = $this->facade->managesEmail($userId, $email);

		$this->assertTrue($result);
	}

	public function testManagesEmailReturnsTrueCaseInsensitive(): void {
		$userId = 'user123';
		$email = 'user@ionos.com';
		$checkEmail = 'USER@IONOS.COM';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn($email);

		$result = $this->facade->managesEmail($userId, $checkEmail);

		$this->assertTrue($result);
	}

	public function testManagesEmailReturnsFalseWhenNoIonosAccount(): void {
		$userId = 'user123';
		$email = 'user@other.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn(null);

		$result = $this->facade->managesEmail($userId, $email);

		$this->assertFalse($result);
	}

	public function testManagesEmailReturnsFalseWhenDifferentEmail(): void {
		$userId = 'user123';
		$ionosEmail = 'user@ionos.com';
		$checkEmail = 'other@ionos.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn($ionosEmail);

		$result = $this->facade->managesEmail($userId, $checkEmail);

		$this->assertFalse($result);
	}

	public function testManagesEmailHandlesException(): void {
		$userId = 'user123';
		$email = 'user@ionos.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Error getting IONOS provisioned email', $this->anything());

		$result = $this->facade->managesEmail($userId, $email);

		$this->assertFalse($result);
	}

	public function testGetEmailDomainSuccess(): void {
		$domain = 'ionos.com';

		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willReturn($domain);

		$result = $this->facade->getEmailDomain();

		$this->assertSame($domain, $result);
	}

	public function testGetEmailDomainHandlesException(): void {
		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willThrowException(new \Exception('Config error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Could not get IONOS email domain', $this->anything());

		$result = $this->facade->getEmailDomain();

		$this->assertNull($result);
	}
}

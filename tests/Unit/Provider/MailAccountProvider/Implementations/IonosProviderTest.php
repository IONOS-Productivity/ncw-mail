<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations;

use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Provider\MailAccountProvider\Implementations\IonosProvider;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosConfigService;
use OCA\Mail\Service\IONOS\IonosMailService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class IonosProviderTest extends TestCase {
	private IonosConfigService&MockObject $configService;
	private IonosMailService&MockObject $mailService;
	private IonosAccountCreationService&MockObject $creationService;
	private LoggerInterface&MockObject $logger;
	private IonosProvider $provider;

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createMock(IonosConfigService::class);
		$this->mailService = $this->createMock(IonosMailService::class);
		$this->creationService = $this->createMock(IonosAccountCreationService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->provider = new IonosProvider(
			$this->configService,
			$this->mailService,
			$this->creationService,
			$this->logger,
		);
	}

	public function testGetId(): void {
		$this->assertEquals('ionos', $this->provider->getId());
	}

	public function testGetName(): void {
		$this->assertEquals('IONOS Mail', $this->provider->getName());
	}

	public function testGetCapabilities(): void {
		$this->configService->method('getMailDomain')
			->willReturn('example.com');

		$capabilities = $this->provider->getCapabilities();

		$this->assertFalse($capabilities->allowsMultipleAccounts());
		$this->assertTrue($capabilities->supportsAppPasswords());
		$this->assertTrue($capabilities->supportsPasswordReset());
		$this->assertEquals('example.com', $capabilities->getEmailDomain());

		$configSchema = $capabilities->getConfigSchema();
		$this->assertArrayHasKey('ionos_mailconfig_api_base_url', $configSchema);
		$this->assertArrayHasKey('ionos_mailconfig_api_auth_user', $configSchema);
		$this->assertArrayHasKey('ionos_mailconfig_api_auth_pass', $configSchema);

		$creationSchema = $capabilities->getCreationParameterSchema();
		$this->assertArrayHasKey('accountName', $creationSchema);
		$this->assertArrayHasKey('emailUser', $creationSchema);
	}

	public function testGetCapabilitiesWithExceptionOnDomain(): void {
		$this->configService->method('getMailDomain')
			->willThrowException(new \Exception('Config error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Could not get IONOS email domain', $this->anything());

		$capabilities = $this->provider->getCapabilities();

		$this->assertNull($capabilities->getEmailDomain());
	}

	public function testGetCapabilitiesCached(): void {
		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willReturn('example.com');

		// Call twice to test caching
		$capabilities1 = $this->provider->getCapabilities();
		$capabilities2 = $this->provider->getCapabilities();

		$this->assertSame($capabilities1, $capabilities2);
	}

	public function testIsEnabledWhenEnabled(): void {
		$this->configService->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->assertTrue($this->provider->isEnabled());
	}

	public function testIsEnabledWhenDisabled(): void {
		$this->configService->method('isIonosIntegrationEnabled')
			->willReturn(false);

		$this->assertFalse($this->provider->isEnabled());
	}

	public function testIsEnabledWithException(): void {
		$this->configService->method('isIonosIntegrationEnabled')
			->willThrowException(new \Exception('Config error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS provider is not enabled', $this->anything());

		$this->assertFalse($this->provider->isEnabled());
	}

	public function testIsAvailableForUserWhenNoAccount(): void {
		$this->mailService->method('mailAccountExistsForCurrentUserId')
			->with('testuser')
			->willReturn(false);

		$this->assertTrue($this->provider->isAvailableForUser('testuser'));
	}

	public function testIsAvailableForUserWhenHasAccount(): void {
		$this->mailService->method('mailAccountExistsForCurrentUserId')
			->with('testuser')
			->willReturn(true);

		$this->assertFalse($this->provider->isAvailableForUser('testuser'));
	}

	public function testIsAvailableForUserWithException(): void {
		$this->mailService->method('mailAccountExistsForCurrentUserId')
			->with('testuser')
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('error')
			->with('Error checking IONOS availability for user', $this->anything());

		$this->assertFalse($this->provider->isAvailableForUser('testuser'));
	}

	public function testCreateAccountSuccess(): void {
		$userId = 'testuser';
		$parameters = [
			'emailUser' => 'user',
			'accountName' => 'Test Account',
		];

		$mailAccount = new MailAccount();
		$mailAccount->setId(123);
		$mailAccount->setEmail('user@example.com');
		$account = new Account($mailAccount);

		$this->creationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, 'user', 'Test Account')
			->willReturn($account);

		$result = $this->provider->createAccount($userId, $parameters);

		$this->assertSame($account, $result);
		$this->assertEquals('user@example.com', $result->getEmail());
	}

	public function testCreateAccountWithMissingEmailUser(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->createAccount('testuser', [
			'accountName' => 'Test Account',
		]);
	}

	public function testCreateAccountWithMissingAccountName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->createAccount('testuser', [
			'emailUser' => 'user',
		]);
	}

	public function testCreateAccountWithEmptyEmailUser(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->createAccount('testuser', [
			'emailUser' => '',
			'accountName' => 'Test Account',
		]);
	}

	public function testCreateAccountWithEmptyAccountName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->createAccount('testuser', [
			'emailUser' => 'user',
			'accountName' => '',
		]);
	}

	public function testUpdateAccount(): void {
		$userId = 'testuser';
		$accountId = 123;
		$parameters = [
			'emailUser' => 'user',
			'accountName' => 'Updated Account',
		];

		$mailAccount = new MailAccount();
		$mailAccount->setId($accountId);
		$mailAccount->setEmail('user@example.com');
		$account = new Account($mailAccount);

		$this->creationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, 'user', 'Updated Account')
			->willReturn($account);

		$result = $this->provider->updateAccount($userId, $accountId, $parameters);

		$this->assertSame($account, $result);
	}

	public function testDeleteAccount(): void {
		$this->mailService->expects($this->once())
			->method('deleteEmailAccount')
			->with('testuser')
			->willReturn(true);

		$result = $this->provider->deleteAccount('testuser', 'user@example.com');

		$this->assertTrue($result);
	}

	public function testManagesEmailWhenMatches(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('user@example.com');

		$this->assertTrue($this->provider->managesEmail('testuser', 'user@example.com'));
	}

	public function testManagesEmailCaseInsensitive(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('user@example.com');

		$this->assertTrue($this->provider->managesEmail('testuser', 'USER@EXAMPLE.COM'));
	}

	public function testManagesEmailWhenDoesNotMatch(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('user@example.com');

		$this->assertFalse($this->provider->managesEmail('testuser', 'other@example.com'));
	}

	public function testManagesEmailWhenNoIonosEmail(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn(null);

		$this->assertFalse($this->provider->managesEmail('testuser', 'user@example.com'));
	}

	public function testManagesEmailWithException(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('testuser')
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Error checking if IONOS manages email', $this->anything());

		$this->assertFalse($this->provider->managesEmail('testuser', 'user@example.com'));
	}

	public function testGetProvisionedEmail(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn('user@example.com');

		$result = $this->provider->getProvisionedEmail('testuser');

		$this->assertEquals('user@example.com', $result);
	}

	public function testGetProvisionedEmailWithNoEmail(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('testuser')
			->willReturn(null);

		$result = $this->provider->getProvisionedEmail('testuser');

		$this->assertNull($result);
	}

	public function testGetProvisionedEmailWithException(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('testuser')
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Error getting IONOS provisioned email', $this->anything());

		$result = $this->provider->getProvisionedEmail('testuser');

		$this->assertNull($result);
	}
}

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
			$this->logger
		);
	}

	public function testGetId(): void {
		$this->assertEquals('ionos', $this->provider->getId());
	}

	public function testGetName(): void {
		$this->assertEquals('IONOS Mail', $this->provider->getName());
	}

	public function testGetCapabilities(): void {
		$capabilities = $this->provider->getCapabilities();

		$this->assertFalse($capabilities->allowsMultipleAccounts());
		$this->assertTrue($capabilities->supportsAppPasswords());
		$this->assertTrue($capabilities->supportsPasswordReset());
	}

	public function testGetCapabilitiesConfigSchema(): void {
		$capabilities = $this->provider->getCapabilities();
		$configSchema = $capabilities->getConfigSchema();

		$this->assertArrayHasKey('ionos_mailconfig_api_base_url', $configSchema);
		$this->assertArrayHasKey('ionos_mailconfig_api_auth_user', $configSchema);
		$this->assertArrayHasKey('ionos_mailconfig_api_auth_pass', $configSchema);
		$this->assertTrue($configSchema['ionos_mailconfig_api_base_url']['required']);
	}

	public function testGetCapabilitiesParameterSchema(): void {
		$capabilities = $this->provider->getCapabilities();
		$paramSchema = $capabilities->getCreationParameterSchema();

		$this->assertArrayHasKey('emailUser', $paramSchema);
		$this->assertArrayHasKey('accountName', $paramSchema);
		$this->assertTrue($paramSchema['emailUser']['required']);
		$this->assertTrue($paramSchema['accountName']['required']);
	}

	public function testIsEnabledWhenIntegrationEnabled(): void {
		$this->configService->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->assertTrue($this->provider->isEnabled());
	}

	public function testIsEnabledWhenIntegrationDisabled(): void {
		$this->configService->method('isIonosIntegrationEnabled')
			->willReturn(false);

		$this->assertFalse($this->provider->isEnabled());
	}

	public function testIsEnabledWhenExceptionThrown(): void {
		$this->configService->method('isIonosIntegrationEnabled')
			->willThrowException(new \Exception('Config error'));

		$this->logger->expects($this->once())
			->method('debug');

		$this->assertFalse($this->provider->isEnabled());
	}

	public function testIsAvailableForUserWhenNoAccount(): void {
		$this->mailService->method('mailAccountExistsForCurrentUserId')
			->with('user123')
			->willReturn(false);

		$this->assertTrue($this->provider->isAvailableForUser('user123'));
	}

	public function testIsAvailableForUserWhenAccountExists(): void {
		$this->mailService->method('mailAccountExistsForCurrentUserId')
			->with('user123')
			->willReturn(true);

		$this->assertFalse($this->provider->isAvailableForUser('user123'));
	}

	public function testIsAvailableForUserWhenExceptionThrown(): void {
		$this->mailService->method('mailAccountExistsForCurrentUserId')
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('error');

		$this->assertFalse($this->provider->isAvailableForUser('user123'));
	}

	public function testCreateAccount(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(123);
		$mailAccount->setEmail('test@ionos.com');
		$account = new Account($mailAccount);

		$this->creationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with('user123', 'test', 'Test Account')
			->willReturn($account);

		$result = $this->provider->createAccount('user123', [
			'emailUser' => 'test',
			'accountName' => 'Test Account',
		]);

		$this->assertEquals($account, $result);
	}

	public function testCreateAccountThrowsExceptionWhenParametersMissing(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->createAccount('user123', []);
	}

	public function testUpdateAccount(): void {
		$mailAccount = new MailAccount();
		$mailAccount->setId(123);
		$account = new Account($mailAccount);

		$this->creationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with('user123', 'test', 'Updated Account')
			->willReturn($account);

		$result = $this->provider->updateAccount('user123', 123, [
			'emailUser' => 'test',
			'accountName' => 'Updated Account',
		]);

		$this->assertEquals($account, $result);
	}

	public function testDeleteAccount(): void {
		$this->mailService->expects($this->once())
			->method('deleteEmailAccount')
			->with('user123')
			->willReturn(true);

		$result = $this->provider->deleteAccount('user123', 'test@ionos.com');

		$this->assertTrue($result);
	}

	public function testManagesEmailWhenMatches(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('user123')
			->willReturn('test@ionos.com');

		$this->assertTrue($this->provider->managesEmail('user123', 'test@ionos.com'));
		$this->assertTrue($this->provider->managesEmail('user123', 'TEST@IONOS.COM')); // Case insensitive
	}

	public function testManagesEmailWhenDoesNotMatch(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('user123')
			->willReturn('test@ionos.com');

		$this->assertFalse($this->provider->managesEmail('user123', 'other@ionos.com'));
	}

	public function testManagesEmailWhenNoIonosAccount(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('user123')
			->willReturn(null);

		$this->assertFalse($this->provider->managesEmail('user123', 'test@ionos.com'));
	}

	public function testManagesEmailWhenExceptionThrown(): void {
		$this->mailService->method('getIonosEmailForUser')
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('debug');

		$this->assertFalse($this->provider->managesEmail('user123', 'test@ionos.com'));
	}

	public function testGetProvisionedEmail(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('user123')
			->willReturn('test@ionos.com');

		$result = $this->provider->getProvisionedEmail('user123');

		$this->assertEquals('test@ionos.com', $result);
	}

	public function testGetProvisionedEmailReturnsNullWhenNoAccount(): void {
		$this->mailService->method('getIonosEmailForUser')
			->with('user123')
			->willReturn(null);

		$result = $this->provider->getProvisionedEmail('user123');

		$this->assertNull($result);
	}

	public function testGetProvisionedEmailReturnsNullWhenExceptionThrown(): void {
		$this->mailService->method('getIonosEmailForUser')
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('debug');

		$result = $this->provider->getProvisionedEmail('user123');

		$this->assertNull($result);
	}
}

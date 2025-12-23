<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\IonosProviderFacade;
use OCA\Mail\Provider\MailAccountProvider\Implementations\IonosProvider;
use PHPUnit\Framework\MockObject\MockObject;

class IonosProviderTest extends TestCase {
	private IonosProviderFacade&MockObject $facade;
	private IonosProvider $provider;

	protected function setUp(): void {
		parent::setUp();

		$this->facade = $this->createMock(IonosProviderFacade::class);

		$this->provider = new IonosProvider(
			$this->facade,
		);
	}

	public function testGetId(): void {
		$this->assertEquals('ionos', $this->provider->getId());
	}

	public function testGetName(): void {
		$this->assertEquals('IONOS Nextcloud Workspace Mail', $this->provider->getName());
	}

	public function testGetCapabilities(): void {
		$this->facade->method('getEmailDomain')
			->willReturn('example.com');

		$capabilities = $this->provider->getCapabilities();

		$this->assertFalse($capabilities->allowsMultipleAccounts());
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
		$this->facade->method('getEmailDomain')
			->willReturn(null);

		$capabilities = $this->provider->getCapabilities();

		$this->assertNull($capabilities->getEmailDomain());
	}

	public function testGetCapabilitiesCached(): void {
		$this->facade->expects($this->once())
			->method('getEmailDomain')
			->willReturn('example.com');

		// Call twice to test caching
		$capabilities1 = $this->provider->getCapabilities();
		$capabilities2 = $this->provider->getCapabilities();

		$this->assertSame($capabilities1, $capabilities2);
	}

	public function testIsEnabledWhenEnabled(): void {
		$this->facade->method('isEnabled')
			->willReturn(true);

		$this->assertTrue($this->provider->isEnabled());
	}

	public function testIsEnabledWhenDisabled(): void {
		$this->facade->method('isEnabled')
			->willReturn(false);

		$this->assertFalse($this->provider->isEnabled());
	}

	public function testIsAvailableForUserWhenNoAccount(): void {
		$this->facade->method('isAvailableForUser')
			->with('testuser')
			->willReturn(true);

		$this->assertTrue($this->provider->isAvailableForUser('testuser'));
	}

	public function testIsAvailableForUserWhenHasAccount(): void {
		$this->facade->method('isAvailableForUser')
			->with('testuser')
			->willReturn(false);

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

		$this->facade->expects($this->once())
			->method('createAccount')
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

	public function testCreateAccountWithBothEmpty(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->createAccount('testuser', [
			'emailUser' => '',
			'accountName' => '',
		]);
	}

	public function testCreateAccountWithEmptyArray(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->createAccount('testuser', []);
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

		$this->facade->expects($this->once())
			->method('createAccount')
			->with($userId, 'user', 'Updated Account')
			->willReturn($account);

		$result = $this->provider->updateAccount($userId, $accountId, $parameters);

		$this->assertSame($account, $result);
	}

	public function testUpdateAccountWithMissingEmailUser(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->updateAccount('testuser', 123, [
			'accountName' => 'Test Account',
		]);
	}

	public function testUpdateAccountWithMissingAccountName(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->updateAccount('testuser', 123, [
			'emailUser' => 'user',
		]);
	}

	public function testUpdateAccountWithEmptyParameters(): void {
		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('emailUser and accountName are required');

		$this->provider->updateAccount('testuser', 123, []);
	}

	public function testDeleteAccount(): void {
		$this->facade->expects($this->once())
			->method('deleteAccount')
			->with('testuser')
			->willReturn(true);

		$result = $this->provider->deleteAccount('testuser', 'user@example.com');

		$this->assertTrue($result);
	}

	public function testDeleteAccountReturnsFalse(): void {
		$this->facade->expects($this->once())
			->method('deleteAccount')
			->with('testuser')
			->willReturn(false);

		$result = $this->provider->deleteAccount('testuser', 'user@example.com');

		$this->assertFalse($result);
	}

	public function testManagesEmailWhenMatches(): void {
		$this->facade->method('managesEmail')
			->with('testuser', 'user@example.com')
			->willReturn(true);

		$this->assertTrue($this->provider->managesEmail('testuser', 'user@example.com'));
	}

	public function testManagesEmailCaseInsensitive(): void {
		$this->facade->method('managesEmail')
			->with('testuser', 'USER@EXAMPLE.COM')
			->willReturn(true);

		$this->assertTrue($this->provider->managesEmail('testuser', 'USER@EXAMPLE.COM'));
	}

	public function testManagesEmailWhenDoesNotMatch(): void {
		$this->facade->method('managesEmail')
			->with('testuser', 'other@example.com')
			->willReturn(false);

		$this->assertFalse($this->provider->managesEmail('testuser', 'other@example.com'));
	}

	public function testManagesEmailWhenNoIonosEmail(): void {
		$this->facade->method('managesEmail')
			->with('testuser', 'user@example.com')
			->willReturn(false);

		$this->assertFalse($this->provider->managesEmail('testuser', 'user@example.com'));
	}

	public function testGetProvisionedEmail(): void {
		$this->facade->method('getProvisionedEmail')
			->with('testuser')
			->willReturn('user@example.com');

		$result = $this->provider->getProvisionedEmail('testuser');

		$this->assertEquals('user@example.com', $result);
	}

	public function testGetProvisionedEmailWithNoEmail(): void {
		$this->facade->method('getProvisionedEmail')
			->with('testuser')
			->willReturn(null);

		$result = $this->provider->getProvisionedEmail('testuser');
		$this->assertNull($result);
	}
}

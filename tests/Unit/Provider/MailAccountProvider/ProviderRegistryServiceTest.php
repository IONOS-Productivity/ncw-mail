<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider;

use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ProviderRegistryServiceTest extends TestCase {
	private LoggerInterface&MockObject $logger;
	private ProviderRegistryService $registry;

	protected function setUp(): void {
		parent::setUp();

		$this->logger = $this->createMock(LoggerInterface::class);
		$this->registry = new ProviderRegistryService($this->logger);
	}

	public function testRegisterProvider(): void {
		$provider = $this->createMockProvider('test', 'Test Provider');

		$this->registry->registerProvider($provider);

		$this->assertEquals($provider, $this->registry->getProvider('test'));
	}

	public function testGetProviderReturnsNullForUnknownId(): void {
		$result = $this->registry->getProvider('unknown');

		$this->assertNull($result);
	}

	public function testGetAllProviders(): void {
		$provider1 = $this->createMockProvider('test1', 'Test Provider 1');
		$provider2 = $this->createMockProvider('test2', 'Test Provider 2');

		$this->registry->registerProvider($provider1);
		$this->registry->registerProvider($provider2);

		$providers = $this->registry->getAllProviders();

		$this->assertCount(2, $providers);
		$this->assertArrayHasKey('test1', $providers);
		$this->assertArrayHasKey('test2', $providers);
	}

	public function testGetEnabledProviders(): void {
		$enabledProvider = $this->createMockProvider('enabled', 'Enabled', true);
		$disabledProvider = $this->createMockProvider('disabled', 'Disabled', false);

		$this->registry->registerProvider($enabledProvider);
		$this->registry->registerProvider($disabledProvider);

		$enabled = $this->registry->getEnabledProviders();

		$this->assertCount(1, $enabled);
		$this->assertArrayHasKey('enabled', $enabled);
		$this->assertArrayNotHasKey('disabled', $enabled);
	}

	public function testGetAvailableProvidersForUser(): void {
		$availableProvider = $this->createMockProvider('available', 'Available', true, true);
		$unavailableProvider = $this->createMockProvider('unavailable', 'Unavailable', true, false);

		$this->registry->registerProvider($availableProvider);
		$this->registry->registerProvider($unavailableProvider);

		$available = $this->registry->getAvailableProvidersForUser('testuser');

		$this->assertCount(1, $available);
		$this->assertArrayHasKey('available', $available);
	}

	public function testFindProviderForEmail(): void {
		$matchingProvider = $this->createMockProvider('matching', 'Matching', true);
		$matchingProvider->method('managesEmail')
			->willReturn(true);

		$nonMatchingProvider = $this->createMockProvider('nonmatching', 'Non-Matching', true);
		$nonMatchingProvider->method('managesEmail')
			->willReturn(false);

		$this->registry->registerProvider($matchingProvider);
		$this->registry->registerProvider($nonMatchingProvider);

		$result = $this->registry->findProviderForEmail('user', 'test@example.com');

		$this->assertEquals($matchingProvider, $result);
	}

	public function testFindProviderForEmailReturnsNullIfNoneMatch(): void {
		$provider = $this->createMockProvider('test', 'Test', true);
		$provider->method('managesEmail')
			->willReturn(false);

		$this->registry->registerProvider($provider);

		$result = $this->registry->findProviderForEmail('user', 'test@example.com');

		$this->assertNull($result);
	}

	public function testGetProviderInfo(): void {
		$capabilities = new ProviderCapabilities(
			multipleAccounts: true,
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('test');
		$provider->method('getName')->willReturn('Test Provider');
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('getCapabilities')->willReturn($capabilities);

		$this->registry->registerProvider($provider);

		$info = $this->registry->getProviderInfo();

		$this->assertArrayHasKey('test', $info);
		$this->assertEquals('test', $info['test']['id']);
		$this->assertEquals('Test Provider', $info['test']['name']);
		$this->assertTrue($info['test']['enabled']);
		$this->assertTrue($info['test']['capabilities']['multipleAccounts']);
	}

	public function testDeleteProviderManagedAccountsWithNoProviderManaged(): void {
		$userId = 'testuser';
		$account = $this->createMockAccount('user@example.com');

		$provider = $this->createMockProvider('test', 'Test', true);
		$provider->method('managesEmail')
			->willReturn(false);

		$this->registry->registerProvider($provider);

		$provider->expects($this->never())
			->method('deleteAccount');

		$this->registry->deleteProviderManagedAccounts($userId, [$account]);
	}

	public function testDeleteProviderManagedAccountsWithProviderManaged(): void {
		$userId = 'testuser';
		$email = 'user@example.com';
		$account = $this->createMockAccount($email);

		$provider = $this->createMockProvider('test', 'Test', true);
		$provider->method('managesEmail')
			->with($userId, $email)
			->willReturn(true);
		$provider->expects($this->once())
			->method('deleteAccount')
			->with($userId, $email);

		$this->registry->registerProvider($provider);

		$this->registry->deleteProviderManagedAccounts($userId, [$account]);
	}

	public function testDeleteProviderManagedAccountsWithMultipleAccounts(): void {
		$userId = 'testuser';
		$email1 = 'user1@example.com';
		$email2 = 'user2@example.com';
		$email3 = 'user3@example.com';

		$account1 = $this->createMockAccount($email1);
		$account2 = $this->createMockAccount($email2);
		$account3 = $this->createMockAccount($email3);

		$provider = $this->createMockProvider('test', 'Test', true);
		$provider->method('managesEmail')
			->willReturnMap([
				[$userId, $email1, true],
				[$userId, $email2, false],
				[$userId, $email3, true],
			]);
		$provider->expects($this->exactly(2))
			->method('deleteAccount')
			->willReturnCallback(function ($uid, $email) use ($userId, $email1, $email3) {
				$this->assertSame($userId, $uid);
				$this->assertContains($email, [$email1, $email3]);
				return true;
			});

		$this->registry->registerProvider($provider);

		$this->registry->deleteProviderManagedAccounts($userId, [$account1, $account2, $account3]);
	}

	public function testDeleteProviderManagedAccountsContinuesOnException(): void {
		$userId = 'testuser';
		$email1 = 'user1@example.com';
		$email2 = 'user2@example.com';

		$account1 = $this->createMockAccount($email1);
		$account2 = $this->createMockAccount($email2);

		$provider = $this->createMockProvider('test', 'Test', true);
		$provider->method('managesEmail')
			->willReturn(true);
		$provider->expects($this->exactly(2))
			->method('deleteAccount')
			->willReturnCallback(function ($uid, $email) use ($email1) {
				if ($email === $email1) {
					throw new \Exception('Deletion failed');
				}
				return true;
			});

		$this->registry->registerProvider($provider);

		$this->logger->expects($this->once())
			->method('error')
			->with('Failed to delete provider-managed account', $this->anything());

		// Should not throw exception, continues with second account
		$this->registry->deleteProviderManagedAccounts($userId, [$account1, $account2]);
	}

	private function createMockAccount(string $email): object {
		return new class($email) {
			private string $email;

			public function __construct(string $email) {
				$this->email = $email;
			}

			public function getEmail(): string {
				return $this->email;
			}
		};
	}

	private function createMockProvider(
		string $id,
		string $name,
		bool $enabled = true,
		bool $availableForUser = true,
	): IMailAccountProvider&MockObject {
		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn($id);
		$provider->method('getName')->willReturn($name);
		$provider->method('isEnabled')->willReturn($enabled);
		$provider->method('isAvailableForUser')->willReturn($availableForUser);

		$capabilities = new ProviderCapabilities();
		$provider->method('getCapabilities')->willReturn($capabilities);

		return $provider;
	}
}

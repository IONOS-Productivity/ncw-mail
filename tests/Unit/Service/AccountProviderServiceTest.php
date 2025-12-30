<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service;

use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCA\Mail\Service\AccountProviderService;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class AccountProviderServiceTest extends TestCase {
	private ProviderRegistryService&MockObject $providerRegistry;
	private LoggerInterface&MockObject $logger;
	private AccountProviderService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->providerRegistry = $this->createMock(ProviderRegistryService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new AccountProviderService(
			$this->providerRegistry,
			$this->logger,
		);
	}

	public function testAddProviderMetadataWithNoProvider(): void {
		$accountJson = [
			'id' => 123,
			'email' => 'user@example.com',
		];

		$this->providerRegistry->method('findProviderForEmail')
			->with('testuser', 'user@example.com')
			->willReturn(null);

		$result = $this->service->addProviderMetadata($accountJson, 'testuser', 'user@example.com');

		$this->assertArrayHasKey('managedByProvider', $result);
		$this->assertNull($result['managedByProvider']);
		$this->assertArrayHasKey('providerCapabilities', $result);
		$this->assertNull($result['providerCapabilities']);
	}

	public function testAddProviderMetadataWithProvider(): void {
		$accountJson = [
			'id' => 123,
			'email' => 'user@example.com',
		];

		$capabilities = new ProviderCapabilities(
			multipleAccounts: true,
			appPasswords: true,
			passwordReset: false,
			emailDomain: 'example.com',
		);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')
			->willReturn('test-provider');
		$provider->method('getCapabilities')
			->willReturn($capabilities);

		$this->providerRegistry->method('findProviderForEmail')
			->with('testuser', 'user@example.com')
			->willReturn($provider);

		$result = $this->service->addProviderMetadata($accountJson, 'testuser', 'user@example.com');

		$this->assertEquals('test-provider', $result['managedByProvider']);
		$this->assertIsArray($result['providerCapabilities']);
		$this->assertTrue($result['providerCapabilities']['multipleAccounts']);
		$this->assertTrue($result['providerCapabilities']['appPasswords']);
		$this->assertFalse($result['providerCapabilities']['passwordReset']);
		$this->assertEquals('example.com', $result['providerCapabilities']['emailDomain']);
	}

	public function testAddProviderMetadataWithException(): void {
		$accountJson = [
			'id' => 123,
			'email' => 'user@example.com',
		];

		$this->providerRegistry->method('findProviderForEmail')
			->with('testuser', 'user@example.com')
			->willThrowException(new \Exception('Test exception'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Error determining account provider', $this->anything());

		$result = $this->service->addProviderMetadata($accountJson, 'testuser', 'user@example.com');

		// Should return safe defaults
		$this->assertNull($result['managedByProvider']);
		$this->assertNull($result['providerCapabilities']);
	}

	public function testGetAvailableProvidersForUser(): void {
		$capabilities1 = new ProviderCapabilities(
			multipleAccounts: true,
			appPasswords: true,
			passwordReset: false,
			creationParameterSchema: [
				'param1' => ['type' => 'string', 'required' => true],
			],
			emailDomain: 'example.com',
		);

		$capabilities2 = new ProviderCapabilities(
			multipleAccounts: false,
			appPasswords: false,
			passwordReset: true,
			creationParameterSchema: [
				'param2' => ['type' => 'string', 'required' => false],
			],
			emailDomain: 'test.com',
		);

		$provider1 = $this->createMock(IMailAccountProvider::class);
		$provider1->method('getId')->willReturn('provider1');
		$provider1->method('getName')->willReturn('Provider 1');
		$provider1->method('getCapabilities')->willReturn($capabilities1);

		$provider2 = $this->createMock(IMailAccountProvider::class);
		$provider2->method('getId')->willReturn('provider2');
		$provider2->method('getName')->willReturn('Provider 2');
		$provider2->method('getCapabilities')->willReturn($capabilities2);

		$this->providerRegistry->method('getAvailableProvidersForUser')
			->with('testuser')
			->willReturn([
				'provider1' => $provider1,
				'provider2' => $provider2,
			]);

		$result = $this->service->getAvailableProvidersForUser('testuser');

		$this->assertCount(2, $result);
		$this->assertArrayHasKey('provider1', $result);
		$this->assertArrayHasKey('provider2', $result);

		// Check provider1
		$this->assertEquals('provider1', $result['provider1']['id']);
		$this->assertEquals('Provider 1', $result['provider1']['name']);
		$this->assertTrue($result['provider1']['capabilities']['multipleAccounts']);
		$this->assertTrue($result['provider1']['capabilities']['appPasswords']);
		$this->assertFalse($result['provider1']['capabilities']['passwordReset']);
		$this->assertEquals('example.com', $result['provider1']['capabilities']['emailDomain']);
		$this->assertArrayHasKey('param1', $result['provider1']['parameterSchema']);

		// Check provider2
		$this->assertEquals('provider2', $result['provider2']['id']);
		$this->assertEquals('Provider 2', $result['provider2']['name']);
		$this->assertFalse($result['provider2']['capabilities']['multipleAccounts']);
		$this->assertFalse($result['provider2']['capabilities']['appPasswords']);
		$this->assertTrue($result['provider2']['capabilities']['passwordReset']);
		$this->assertEquals('test.com', $result['provider2']['capabilities']['emailDomain']);
		$this->assertArrayHasKey('param2', $result['provider2']['parameterSchema']);
	}

	public function testGetAvailableProvidersForUserWithNoProviders(): void {
		$this->providerRegistry->method('getAvailableProvidersForUser')
			->with('testuser')
			->willReturn([]);

		$result = $this->service->getAvailableProvidersForUser('testuser');

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}
}

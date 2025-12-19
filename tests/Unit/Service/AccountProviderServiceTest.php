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
			$this->logger
		);
	}

	public function testAddProviderMetadataWhenProviderFound(): void {
		$provider = $this->createMockProvider('ionos', 'IONOS Mail', true, true, false);

		$this->providerRegistry->method('findProviderForEmail')
			->with('user123', 'test@ionos.com')
			->willReturn($provider);

		$accountJson = [
			'id' => 1,
			'email' => 'test@ionos.com',
		];

		$result = $this->service->addProviderMetadata($accountJson, 'user123', 'test@ionos.com');

		$this->assertEquals('ionos', $result['managedByProvider']);
		$this->assertIsArray($result['providerCapabilities']);
		$this->assertFalse($result['providerCapabilities']['multipleAccounts']);
		$this->assertTrue($result['providerCapabilities']['appPasswords']);
		$this->assertFalse($result['providerCapabilities']['passwordReset']);
		$this->assertTrue($result['isIonosManaged']); // Backward compatibility
	}

	public function testAddProviderMetadataWhenNoProvider(): void {
		$this->providerRegistry->method('findProviderForEmail')
			->with('user123', 'test@example.com')
			->willReturn(null);

		$accountJson = [
			'id' => 1,
			'email' => 'test@example.com',
		];

		$result = $this->service->addProviderMetadata($accountJson, 'user123', 'test@example.com');

		$this->assertNull($result['managedByProvider']);
		$this->assertNull($result['providerCapabilities']);
		$this->assertFalse($result['isIonosManaged']);
	}

	public function testAddProviderMetadataWhenNonIonosProvider(): void {
		$provider = $this->createMockProvider('office365', 'Microsoft 365', true, false, true);

		$this->providerRegistry->method('findProviderForEmail')
			->with('user123', 'test@office365.com')
			->willReturn($provider);

		$accountJson = [
			'id' => 1,
			'email' => 'test@office365.com',
		];

		$result = $this->service->addProviderMetadata($accountJson, 'user123', 'test@office365.com');

		$this->assertEquals('office365', $result['managedByProvider']);
		$this->assertTrue($result['providerCapabilities']['multipleAccounts']);
		$this->assertFalse($result['providerCapabilities']['appPasswords']);
		$this->assertTrue($result['providerCapabilities']['passwordReset']);
		$this->assertFalse($result['isIonosManaged']); // Not IONOS
	}

	public function testAddProviderMetadataWhenExceptionThrown(): void {
		$this->providerRegistry->method('findProviderForEmail')
			->willThrowException(new \Exception('Registry error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Error determining account provider', $this->anything());

		$accountJson = [
			'id' => 1,
			'email' => 'test@example.com',
		];

		$result = $this->service->addProviderMetadata($accountJson, 'user123', 'test@example.com');

		// Should return safe defaults on error
		$this->assertNull($result['managedByProvider']);
		$this->assertNull($result['providerCapabilities']);
		$this->assertFalse($result['isIonosManaged']);
	}

	public function testGetAvailableProvidersForUser(): void {
		$provider1 = $this->createMockProvider('ionos', 'IONOS Mail', false, true, false);
		$provider2 = $this->createMockProvider('office365', 'Microsoft 365', true, false, true);

		$this->providerRegistry->method('getAvailableProvidersForUser')
			->with('user123')
			->willReturn([
				'ionos' => $provider1,
				'office365' => $provider2,
			]);

		$result = $this->service->getAvailableProvidersForUser('user123');

		$this->assertCount(2, $result);
		$this->assertArrayHasKey('ionos', $result);
		$this->assertArrayHasKey('office365', $result);

		$this->assertEquals('ionos', $result['ionos']['id']);
		$this->assertEquals('IONOS Mail', $result['ionos']['name']);
		$this->assertFalse($result['ionos']['capabilities']['multipleAccounts']);
		$this->assertTrue($result['ionos']['capabilities']['appPasswords']);

		$this->assertEquals('office365', $result['office365']['id']);
		$this->assertEquals('Microsoft 365', $result['office365']['name']);
		$this->assertTrue($result['office365']['capabilities']['multipleAccounts']);
		$this->assertFalse($result['office365']['capabilities']['appPasswords']);
	}

	public function testGetAvailableProvidersForUserWhenEmpty(): void {
		$this->providerRegistry->method('getAvailableProvidersForUser')
			->with('user123')
			->willReturn([]);

		$result = $this->service->getAvailableProvidersForUser('user123');

		$this->assertEmpty($result);
	}

	private function createMockProvider(
		string $id,
		string $name,
		bool $multipleAccounts,
		bool $appPasswords,
		bool $passwordReset
	): IMailAccountProvider&MockObject {
		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn($id);
		$provider->method('getName')->willReturn($name);

		$capabilities = new ProviderCapabilities(
			multipleAccounts: $multipleAccounts,
			appPasswords: $appPasswords,
			passwordReset: $passwordReset,
			creationParameterSchema: []
		);
		$provider->method('getCapabilities')->willReturn($capabilities);

		return $provider;
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use GuzzleHttp\Client;
use GuzzleHttp\ClientInterface;
use IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi;
use OCA\Mail\Service\IONOS\ApiMailConfigClientService;

class ApiMailConfigClientServiceTest extends TestCase {
	private ApiMailConfigClientService $service;

	protected function setUp(): void {
		parent::setUp();
		$this->service = new ApiMailConfigClientService();
	}

	public function testNewClientWithDefaultConfig(): void {
		$config = [];
		$client = $this->service->newClient($config);

		$this->assertInstanceOf(ClientInterface::class, $client);
		$this->assertInstanceOf(Client::class, $client);
	}

	public function testNewClientWithAuthConfig(): void {
		$config = [
			'auth' => ['username', 'password'],
			'verify' => true,
		];
		$client = $this->service->newClient($config);

		$this->assertInstanceOf(ClientInterface::class, $client);
		$this->assertInstanceOf(Client::class, $client);
	}

	public function testNewClientWithInsecureConfig(): void {
		$config = [
			'auth' => ['username', 'password'],
			'verify' => false,
		];
		$client = $this->service->newClient($config);

		$this->assertInstanceOf(ClientInterface::class, $client);
		$this->assertInstanceOf(Client::class, $client);
	}

	public function testNewEventAPIApi(): void {
		$client = $this->createMock(ClientInterface::class);
		$apiBaseUrl = 'https://api.example.com';

		$apiInstance = $this->service->newEventAPIApi($client, $apiBaseUrl);

		$this->assertInstanceOf(MailConfigurationAPIApi::class, $apiInstance);
		$this->assertEquals($apiBaseUrl, $apiInstance->getConfig()->getHost());
	}

	public function testNewEventAPIApiWithEmptyUrl(): void {
		$client = $this->createMock(ClientInterface::class);
		$apiBaseUrl = '';

		$this->expectException(\InvalidArgumentException::class);
		$this->expectExceptionMessage('API base URL is required');

		$this->service->newEventAPIApi($client, $apiBaseUrl);
	}

	public function testNewEventAPIApiWithDifferentUrls(): void {
		$client = $this->createMock(ClientInterface::class);

		$urls = [
			'https://api.example.com',
			'https://api.example.com/v1',
			'http://localhost:8080',
			'https://staging-api.example.com',
		];

		foreach ($urls as $url) {
			$apiInstance = $this->service->newEventAPIApi($client, $url);
			$this->assertInstanceOf(MailConfigurationAPIApi::class, $apiInstance);
			$this->assertEquals($url, $apiInstance->getConfig()->getHost());
		}
	}

	public function testNewClientReturnsNewInstanceEachTime(): void {
		$config = ['auth' => ['user', 'pass']];

		$client1 = $this->service->newClient($config);
		$client2 = $this->service->newClient($config);

		// Each call should return a new instance
		$this->assertNotSame($client1, $client2);
	}

	public function testNewEventAPIApiReturnsNewInstanceEachTime(): void {
		$client = $this->createMock(ClientInterface::class);
		$apiBaseUrl = 'https://api.example.com';

		$api1 = $this->service->newEventAPIApi($client, $apiBaseUrl);
		$api2 = $this->service->newEventAPIApi($client, $apiBaseUrl);

		// Each call should return a new instance
		$this->assertNotSame($api1, $api2);
	}
}

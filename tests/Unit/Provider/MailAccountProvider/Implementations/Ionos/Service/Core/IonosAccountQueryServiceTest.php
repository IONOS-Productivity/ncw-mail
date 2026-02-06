<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations\Ionos\Service\Core;

use ChristophWurst\Nextcloud\Testing\TestCase;
use IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi;
use IONOS\MailConfigurationAPI\Client\ApiException;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\ApiMailConfigClientService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core\IonosAccountQueryService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosAccountQueryServiceTest extends TestCase {
	private ApiMailConfigClientService&MockObject $apiClientService;
	private IonosConfigService&MockObject $configService;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private MailConfigurationAPIApi&MockObject $apiInstance;
	private IonosAccountQueryService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->apiClientService = $this->createMock(ApiMailConfigClientService::class);
		$this->configService = $this->createMock(IonosConfigService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);
		$this->apiInstance = $this->createMock(MailConfigurationAPIApi::class);

		$this->service = new IonosAccountQueryService(
			$this->apiClientService,
			$this->configService,
			$this->userSession,
			$this->logger,
		);
	}

	public function testGetAllMailAccountResponsesReturnsEmptyArrayOnApiException(): void {
		$externalRef = 'test-ref';

		$this->configService->expects($this->once())
			->method('getExternalReference')
			->willReturn($externalRef);

		$this->configService->expects($this->once())
			->method('getBasicAuthUser')
			->willReturn('user');

		$this->configService->expects($this->once())
			->method('getBasicAuthPassword')
			->willReturn('pass');

		$this->configService->expects($this->once())
			->method('getAllowInsecure')
			->willReturn(false);

		$this->configService->expects($this->once())
			->method('getApiBaseUrl')
			->willReturn('https://api.example.com');

		$this->apiClientService->expects($this->once())
			->method('newClient')
			->willReturn($this->createMock(\GuzzleHttp\ClientInterface::class));

		$this->apiClientService->expects($this->once())
			->method('newMailConfigurationAPIApi')
			->willReturn($this->apiInstance);

		$this->apiInstance->expects($this->once())
			->method('getAllFunctionalAccounts')
			->with('IONOS', $externalRef)
			->willThrowException(new ApiException('API error', 500));

		$this->logger->expects($this->atLeastOnce())
			->method('debug');

		$this->logger->expects($this->once())
			->method('error')
			->with('API error getting all IONOS mail accounts', $this->anything());

		$result = $this->service->getAllMailAccountResponses();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testGetAllMailAccountResponsesReturnsAccountList(): void {
		$externalRef = 'test-ref';
		$mockResponse1 = $this->createMock(MailAccountResponse::class);
		$mockResponse2 = $this->createMock(MailAccountResponse::class);
		$responses = [$mockResponse1, $mockResponse2];

		$this->configService->expects($this->once())
			->method('getExternalReference')
			->willReturn($externalRef);

		$this->configService->expects($this->once())
			->method('getBasicAuthUser')
			->willReturn('user');

		$this->configService->expects($this->once())
			->method('getBasicAuthPassword')
			->willReturn('pass');

		$this->configService->expects($this->once())
			->method('getAllowInsecure')
			->willReturn(false);

		$this->configService->expects($this->once())
			->method('getApiBaseUrl')
			->willReturn('https://api.example.com');

		$this->apiClientService->expects($this->once())
			->method('newClient')
			->willReturn($this->createMock(\GuzzleHttp\ClientInterface::class));

		$this->apiClientService->expects($this->once())
			->method('newMailConfigurationAPIApi')
			->willReturn($this->apiInstance);

		$this->apiInstance->expects($this->once())
			->method('getAllFunctionalAccounts')
			->with('IONOS', $externalRef)
			->willReturn($responses);

		$this->logger->expects($this->atLeastOnce())
			->method('debug');

		$result = $this->service->getAllMailAccountResponses();

		$this->assertIsArray($result);
		$this->assertCount(2, $result);
		$this->assertSame($mockResponse1, $result[0]);
		$this->assertSame($mockResponse2, $result[1]);
	}

	public function testGetAllMailAccountResponsesHandlesException(): void {
		$externalRef = 'test-ref';

		$this->configService->expects($this->once())
			->method('getExternalReference')
			->willReturn($externalRef);

		$this->configService->expects($this->once())
			->method('getBasicAuthUser')
			->willReturn('user');

		$this->configService->expects($this->once())
			->method('getBasicAuthPassword')
			->willReturn('pass');

		$this->configService->expects($this->once())
			->method('getAllowInsecure')
			->willReturn(false);

		$this->configService->expects($this->once())
			->method('getApiBaseUrl')
			->willReturn('https://api.example.com');

		$this->apiClientService->expects($this->once())
			->method('newClient')
			->willReturn($this->createMock(\GuzzleHttp\ClientInterface::class));

		$this->apiClientService->expects($this->once())
			->method('newMailConfigurationAPIApi')
			->willReturn($this->apiInstance);

		$this->apiInstance->expects($this->once())
			->method('getAllFunctionalAccounts')
			->with('IONOS', $externalRef)
			->willThrowException(new \Exception('Unexpected error'));

		$this->logger->expects($this->atLeastOnce())
			->method('debug');

		$this->logger->expects($this->once())
			->method('error')
			->with('Unexpected error getting all IONOS mail accounts', $this->anything());

		$result = $this->service->getAllMailAccountResponses();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}
}

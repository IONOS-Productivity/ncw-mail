<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use GuzzleHttp\ClientInterface;
use IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\ApiMailConfigClientService;
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\IonosConfigService;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosMailServiceTest extends TestCase {
	private ApiMailConfigClientService&MockObject $apiClientService;
	private IonosConfigService&MockObject $configService;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private IonosMailService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->apiClientService = $this->createMock(ApiMailConfigClientService::class);
		$this->configService = $this->createMock(IonosConfigService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new IonosMailService(
			$this->apiClientService,
			$this->configService,
			$this->userSession,
			$this->logger,
		);
	}

	public function testCreateEmailAccountSuccess(): void {
		$emailAddress = 'test@example.com';

		// Mock config
		$this->configService->method('getApiConfig')
			->willReturn([
				'extRef' => 'test-ext-ref',
				'apiBaseUrl' => 'https://api.example.com',
				'allowInsecure' => false,
				'basicAuthUser' => 'testuser',
				'basicAuthPass' => 'testpass',
			]);

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => ['testuser', 'testpass'],
				'verify' => true,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newEventAPIApi')
			->with($client, 'https://api.example.com')
			->willReturn($apiInstance);

		$apiInstance->method('createMailbox')->willReturn(null);

		$result = $this->service->createEmailAccount($emailAddress);

		$this->assertInstanceOf(MailAccountConfig::class, $result);
		$this->assertEquals($emailAddress, $result->getEmail());
		$this->assertEquals('mail.localhost', $result->getImap()->getHost());
		$this->assertEquals(1143, $result->getImap()->getPort());
		$this->assertEquals('none', $result->getImap()->getSecurity());
		$this->assertEquals($emailAddress, $result->getImap()->getUsername());
		$this->assertEquals('tmp', $result->getImap()->getPassword());
		$this->assertEquals('mail.localhost', $result->getSmtp()->getHost());
		$this->assertEquals(1587, $result->getSmtp()->getPort());
		$this->assertEquals('none', $result->getSmtp()->getSecurity());
		$this->assertEquals($emailAddress, $result->getSmtp()->getUsername());
		$this->assertEquals('tmp', $result->getSmtp()->getPassword());
	}

	public function testCreateEmailAccountWithApiException(): void {
		$emailAddress = 'test@example.com';

		// Mock config
		$this->configService->method('getApiConfig')
			->willReturn([
				'extRef' => 'test-ext-ref',
				'apiBaseUrl' => 'https://api.example.com',
				'allowInsecure' => false,
				'basicAuthUser' => 'testuser',
				'basicAuthPass' => 'testpass',
			]);

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newEventAPIApi')->willReturn($apiInstance);

		// Mock API to throw exception
		$apiInstance->method('createMailbox')
			->willThrowException(new \Exception('API call failed'));

		$this->logger->expects($this->once())
			->method('error')
			->with('Exception when calling MailConfigurationAPIApi->createMailbox', $this->anything());

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');

		$this->service->createEmailAccount($emailAddress);
	}

	public function testCreateEmailAccountWithNoUserSession(): void {
		$emailAddress = 'test@example.com';

		// Mock config
		$this->configService->method('getApiConfig')
			->willReturn([
				'extRef' => 'test-ext-ref',
				'apiBaseUrl' => 'https://api.example.com',
				'allowInsecure' => false,
				'basicAuthUser' => 'testuser',
				'basicAuthPass' => 'testpass',
			]);

		// Mock no user session
		$this->userSession->method('getUser')->willReturn(null);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('No user session found');

		$this->service->createEmailAccount($emailAddress);
	}

	public function testExtractDomainSuccess(): void {
		$result = $this->service->extractDomain('user@example.com');
		$this->assertEquals('example.com', $result);

		$result = $this->service->extractDomain('test.user@subdomain.example.com');
		$this->assertEquals('subdomain.example.com', $result);
	}

	public function testExtractDomainWithNoAtSign(): void {
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Invalid email address: unable to extract domain');

		$this->service->extractDomain('invalid-email');
	}

	public function testExtractDomainWithEmptyDomain(): void {
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Invalid email address: unable to extract domain');

		$this->service->extractDomain('user@');
	}
}

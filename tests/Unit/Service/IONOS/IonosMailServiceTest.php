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
use IONOS\MailConfigurationAPI\Client\Model\ErrorMessage;
use IONOS\MailConfigurationAPI\Client\Model\Imap;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
use IONOS\MailConfigurationAPI\Client\Model\MailServer;
use IONOS\MailConfigurationAPI\Client\Model\Smtp;
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
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

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

		// Mock API response - use getMockBuilder with onlyMethods for existing methods
		$imapServer = $this->getMockBuilder(Imap::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHost', 'getPort', 'getSslMode'])
			->getMock();
		$imapServer->method('getHost')->willReturn('imap.example.com');
		$imapServer->method('getPort')->willReturn(993);
		$imapServer->method('getSslMode')->willReturn('ssl');

		$smtpServer = $this->getMockBuilder(Smtp::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHost', 'getPort', 'getSslMode'])
			->getMock();
		$smtpServer->method('getHost')->willReturn('smtp.example.com');
		$smtpServer->method('getPort')->willReturn(587);
		$smtpServer->method('getSslMode')->willReturn('tls');

		$mailServer = $this->getMockBuilder(MailServer::class)
			->disableOriginalConstructor()
			->onlyMethods(['getImap', 'getSmtp'])
			->getMock();
		$mailServer->method('getImap')->willReturn($imapServer);
		$mailServer->method('getSmtp')->willReturn($smtpServer);

		$mailAccountResponse = $this->getMockBuilder(MailAccountResponse::class)
			->disableOriginalConstructor()
			->onlyMethods(['getEmail', 'getPassword', 'getServer'])
			->getMock();
		$mailAccountResponse->method('getEmail')->willReturn($emailAddress);
		$mailAccountResponse->method('getPassword')->willReturn('test-password');
		$mailAccountResponse->method('getServer')->willReturn($mailServer);

		$apiInstance->method('createMailbox')->willReturn($mailAccountResponse);

		$result = $this->service->createEmailAccount($emailAddress);

		$this->assertInstanceOf(MailAccountConfig::class, $result);
		$this->assertEquals($emailAddress, $result->getEmail());
		$this->assertEquals('imap.example.com', $result->getImap()->getHost());
		$this->assertEquals(993, $result->getImap()->getPort());
		$this->assertEquals('ssl', $result->getImap()->getSecurity());
		$this->assertEquals($emailAddress, $result->getImap()->getUsername());
		$this->assertEquals('test-password', $result->getImap()->getPassword());
		$this->assertEquals('smtp.example.com', $result->getSmtp()->getHost());
		$this->assertEquals(587, $result->getSmtp()->getPort());
		$this->assertEquals('tls', $result->getSmtp()->getSecurity());
		$this->assertEquals($emailAddress, $result->getSmtp()->getUsername());
		$this->assertEquals('test-password', $result->getSmtp()->getPassword());
	}

	public function testCreateEmailAccountWithApiException(): void {
		$emailAddress = 'test@example.com';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

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

	public function testCreateEmailAccountWithErrorMessageResponse(): void {
		$emailAddress = 'test@example.com';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newEventAPIApi')->willReturn($apiInstance);

		// Mock ErrorMessage response
		$errorMessage = $this->getMockBuilder(ErrorMessage::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStatus', 'getMessage'])
			->getMock();
		$errorMessage->method('getStatus')->willReturn(400);
		$errorMessage->method('getMessage')->willReturn('Bad Request');

		$apiInstance->method('createMailbox')->willReturn($errorMessage);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');

		$this->service->createEmailAccount($emailAddress);
	}

	public function testCreateEmailAccountWithUnknownResponseType(): void {
		$emailAddress = 'test@example.com';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newEventAPIApi')->willReturn($apiInstance);

		// Mock unknown response type (return a stdClass instead of expected types)
		$unknownResponse = new \stdClass();
		$apiInstance->method('createMailbox')->willReturn($unknownResponse);


		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');

		$this->service->createEmailAccount($emailAddress);
	}

	public function testCreateEmailAccountWithNoUserSession(): void {
		$emailAddress = 'test@example.com';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

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

	public function testExtractUsernameSuccess(): void {
		$result = $this->service->extractUsername('user@example.com');
		$this->assertEquals('user', $result);

		$result = $this->service->extractUsername('test.user@subdomain.example.com');
		$this->assertEquals('test.user', $result);
	}

	public function testExtractUsernameWithNoAtSign(): void {
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Invalid email address: unable to extract username');

		$this->service->extractUsername('invalid-email');
	}

	public function testExtractUsernameWithEmptyUsername(): void {
		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Invalid email address: unable to extract username');

		$this->service->extractUsername('@example.com');
	}

	/**
	 * Test SSL mode normalization with various API response values
	 *
	 * @dataProvider sslModeNormalizationProvider
	 */
	public function testSslModeNormalization(string $apiSslMode, string $expectedSecurity): void {
		$emailAddress = 'test@example.com';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newEventAPIApi')->willReturn($apiInstance);

		// Mock API response with specific SSL mode
		$imapServer = $this->getMockBuilder(Imap::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHost', 'getPort', 'getSslMode'])
			->getMock();
		$imapServer->method('getHost')->willReturn('imap.example.com');
		$imapServer->method('getPort')->willReturn(993);
		$imapServer->method('getSslMode')->willReturn($apiSslMode);

		$smtpServer = $this->getMockBuilder(Smtp::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHost', 'getPort', 'getSslMode'])
			->getMock();
		$smtpServer->method('getHost')->willReturn('smtp.example.com');
		$smtpServer->method('getPort')->willReturn(587);
		$smtpServer->method('getSslMode')->willReturn($apiSslMode);

		$mailServer = $this->getMockBuilder(MailServer::class)
			->disableOriginalConstructor()
			->onlyMethods(['getImap', 'getSmtp'])
			->getMock();
		$mailServer->method('getImap')->willReturn($imapServer);
		$mailServer->method('getSmtp')->willReturn($smtpServer);

		$mailAccountResponse = $this->getMockBuilder(MailAccountResponse::class)
			->disableOriginalConstructor()
			->onlyMethods(['getEmail', 'getPassword', 'getServer'])
			->getMock();
		$mailAccountResponse->method('getEmail')->willReturn($emailAddress);
		$mailAccountResponse->method('getPassword')->willReturn('test-password');
		$mailAccountResponse->method('getServer')->willReturn($mailServer);

		$apiInstance->method('createMailbox')->willReturn($mailAccountResponse);

		$result = $this->service->createEmailAccount($emailAddress);

		$this->assertEquals($expectedSecurity, $result->getImap()->getSecurity());
		$this->assertEquals($expectedSecurity, $result->getSmtp()->getSecurity());
	}

	/**
	 * Data provider for SSL mode normalization tests
	 *
	 * @return array<string, array{apiSslMode: string, expectedSecurity: string}>
	 */
	public static function sslModeNormalizationProvider(): array {
		return [
			'SSL should map to ssl' => [
				'apiSslMode' => 'SSL',
				'expectedSecurity' => 'ssl',
			],
			'ssl should map to ssl' => [
				'apiSslMode' => 'ssl',
				'expectedSecurity' => 'ssl',
			],
			'TLS should map to tls' => [
				'apiSslMode' => 'TLS',
				'expectedSecurity' => 'tls',
			],
			'tls should map to tls' => [
				'apiSslMode' => 'tls',
				'expectedSecurity' => 'tls',
			],
			'STARTTLS should map to tls' => [
				'apiSslMode' => 'STARTTLS',
				'expectedSecurity' => 'tls',
			],
			'starttls should map to tls' => [
				'apiSslMode' => 'starttls',
				'expectedSecurity' => 'tls',
			],
			'none should map to none' => [
				'apiSslMode' => 'none',
				'expectedSecurity' => 'none',
			],
			'NONE should map to none' => [
				'apiSslMode' => 'NONE',
				'expectedSecurity' => 'none',
			],
			'unknown value should default to none' => [
				'apiSslMode' => 'unknown',
				'expectedSecurity' => 'none',
			],
		];
	}
}

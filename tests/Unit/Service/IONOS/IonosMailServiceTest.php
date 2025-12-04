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
use IONOS\MailConfigurationAPI\Client\Model\Imap;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse;
use IONOS\MailConfigurationAPI\Client\Model\MailAddonErrorMessage;
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
		$userName = 'test';
		$domain = 'example.com';
		$emailAddress = $userName . '@' . $domain;

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');
		$this->configService->method('getMailDomain')->willReturn($domain);

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
		$this->apiClientService->method('newMailConfigurationAPIApi')
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

		// Expect logging calls
		$this->logger->expects($this->exactly(4))
			->method('debug');

		$this->logger->expects($this->once())
			->method('info')
			->with('Successfully created IONOS mail account', $this->callback(function ($context) use ($emailAddress) {
				return $context['email'] === $emailAddress
					&& $context['userId'] === 'testuser123'
					&& $context['userName'] === 'test';
			}));

		$result = $this->service->createEmailAccount($userName);

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
		$userName = 'test';
		$domain = 'example.com';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');
		$this->configService->method('getMailDomain')->willReturn($domain);

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock API to throw exception
		$apiInstance->method('createMailbox')
			->willThrowException(new \Exception('API call failed'));

		// Expect logging calls
		$this->logger->expects($this->exactly(2))
			->method('debug');

		$this->logger->expects($this->once())
			->method('error')
			->with('Exception when calling MailConfigurationAPIApi->createMailbox', $this->callback(function ($context) use ($userName) {
				return isset($context['exception'])
					&& $context['userId'] === 'testuser123'
					&& $context['userName'] === $userName;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');
		$this->expectExceptionCode(500);

		$this->service->createEmailAccount($userName);
	}

	public function testCreateEmailAccountWithMailAddonErrorMessageResponse(): void {
		$userName = 'test';
		$domain = 'example.com';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');
		$this->configService->method('getMailDomain')->willReturn($domain);

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock MailAddonErrorMessage response
		$errorMessage = $this->getMockBuilder(MailAddonErrorMessage::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStatus', 'getMessage'])
			->getMock();
		$errorMessage->method('getStatus')->willReturn(MailAddonErrorMessage::STATUS__400_BAD_REQUEST);
		$errorMessage->method('getMessage')->willReturn('Bad Request');

		$apiInstance->method('createMailbox')->willReturn($errorMessage);

		// Expect logging calls
		$this->logger->expects($this->exactly(2))
			->method('debug');

		$this->logger->expects($this->once())
			->method('error')
			->with('Failed to create ionos mail', $this->callback(function ($context) use ($userName) {
				return $context['status code'] === MailAddonErrorMessage::STATUS__400_BAD_REQUEST
					&& $context['message'] === 'Bad Request'
					&& $context['userId'] === 'testuser123'
					&& $context['userName'] === $userName;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');
		$this->expectExceptionCode(400);

		$this->service->createEmailAccount($userName);
	}

	public function testCreateEmailAccountWithUnknownResponseType(): void {
		$userName = 'test';
		$domain = 'example.com';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');
		$this->configService->method('getMailDomain')->willReturn($domain);

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock unknown response type (return a stdClass instead of expected types)
		$unknownResponse = new \stdClass();
		$apiInstance->method('createMailbox')->willReturn($unknownResponse);

		// Expect logging calls
		$this->logger->expects($this->exactly(2))
			->method('debug');

		$this->logger->expects($this->once())
			->method('error')
			->with('Failed to create ionos mail: Unknown response type', $this->callback(function ($context) use ($userName) {
				return $context['userId'] === 'testuser123'
					&& $context['userName'] === $userName;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');
		$this->expectExceptionCode(500);

		$this->service->createEmailAccount($userName);
	}

	public function testCreateEmailAccountWithNoUserSession(): void {
		$userName = 'test';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock no user session
		$this->userSession->method('getUser')->willReturn(null);

		// Expect logging call
		$this->logger->expects($this->once())
			->method('error')
			->with('No user session found when attempting to create IONOS mail account');

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('No user session found');

		$this->service->createEmailAccount($userName);
	}

	/**
	 * Test SSL mode normalization with various API response values
	 *
	 * @dataProvider sslModeNormalizationProvider
	 */
	public function testSslModeNormalization(string $apiSslMode, string $expectedSecurity): void {
		$userName = 'test';
		$domain = 'example.com';
		$emailAddress = $userName . '@' . $domain;

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');
		$this->configService->method('getMailDomain')->willReturn($domain);

		// Mock user session
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn('testuser123');
		$this->userSession->method('getUser')->willReturn($user);

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

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

		$result = $this->service->createEmailAccount($userName);

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

	public function testMailAccountExistsForCurrentUserReturnsTrueWhenAccountExists(): void {
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
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock API response with existing account
		$mailAccountResponse = $this->getMockBuilder(MailAccountResponse::class)
			->disableOriginalConstructor()
			->onlyMethods(['getEmail'])
			->getMock();
		$mailAccountResponse->method('getEmail')->willReturn('testuser@example.com');

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', 'test-ext-ref', 'testuser123')
			->willReturn($mailAccountResponse);

		// Expect logging calls
		$this->logger->expects($this->exactly(2))
			->method('debug');

		$result = $this->service->mailAccountExistsForCurrentUser();

		$this->assertTrue($result);
	}

	public function testMailAccountExistsForCurrentUserReturnsFalseWhen404(): void {
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
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock API to throw 404 exception
		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Not Found',
			404,
			[],
			'{"error": "Not Found"}'
		);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', 'test-ext-ref', 'testuser123')
			->willThrowException($apiException);

		// Expect logging calls
		$this->logger->expects($this->exactly(2))
			->method('debug');

		$result = $this->service->mailAccountExistsForCurrentUser();

		$this->assertFalse($result);
	}

	public function testMailAccountExistsForCurrentUserReturnsFalseOnApiError(): void {
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
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock API to throw 500 exception
		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Internal Server Error',
			500,
			[],
			'{"error": "Server error"}'
		);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', 'test-ext-ref', 'testuser123')
			->willThrowException($apiException);

		// Expect logging calls
		$this->logger->expects($this->once())
			->method('debug');

		$this->logger->expects($this->once())
			->method('error')
			->with('API Exception when getting IONOS mail account', $this->callback(function ($context) {
				return $context['statusCode'] === 500
				&& $context['message'] === 'Internal Server Error';
			}));

		$result = $this->service->mailAccountExistsForCurrentUser();

		$this->assertFalse($result);
	}

	public function testMailAccountExistsForCurrentUserReturnsFalseOnGeneralException(): void {
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
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock API to throw general exception
		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', 'test-ext-ref', 'testuser123')
			->willThrowException(new \Exception('Unexpected error'));

		// Expect logging calls
		$this->logger->expects($this->once())
			->method('debug');

		$this->logger->expects($this->once())
			->method('error')
			->with('Exception when getting IONOS mail account', $this->callback(function ($context) {
				return isset($context['exception'])
				&& $context['userId'] === 'testuser123';
			}));

		$result = $this->service->mailAccountExistsForCurrentUser();

		$this->assertFalse($result);
	}


	public function testDeleteEmailAccountSuccess(): void {
		$userId = 'testuser123';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => ['testuser', 'testpass'],
				'verify' => true,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')
			->with($client, 'https://api.example.com')
			->willReturn($apiInstance);

		// Mock successful deletion (returns void)
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'test-ext-ref', $userId);

		// Expect logging calls
		$callCount = 0;
		$this->logger->expects($this->exactly(2))
			->method('info')
			->willReturnCallback(function ($message, $context) use ($userId, &$callCount) {
				$callCount++;
				if ($callCount === 1) {
					$this->assertEquals('Attempting to delete IONOS email account', $message);
					$this->assertEquals($userId, $context['userId']);
					$this->assertEquals('test-ext-ref', $context['extRef']);
				} elseif ($callCount === 2) {
					$this->assertEquals('Successfully deleted IONOS email account', $message);
					$this->assertEquals($userId, $context['userId']);
				}
			});

		$result = $this->service->deleteEmailAccount($userId);

		$this->assertTrue($result);
	}

	public function testDeleteEmailAccountReturns404AlreadyDeleted(): void {
		$userId = 'testuser123';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock API to throw 404 exception (mailbox doesn't exist)
		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Not Found',
			404,
			[],
			'{"error": "Not Found"}'
		);

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'test-ext-ref', $userId)
			->willThrowException($apiException);

		// Expect logging calls
		$this->logger->expects($this->once())
			->method('info')
			->with('Attempting to delete IONOS email account', $this->callback(function ($context) use ($userId) {
				return $context['userId'] === $userId
					&& $context['extRef'] === 'test-ext-ref';
			}));

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mailbox does not exist (already deleted or never created)', $this->callback(function ($context) use ($userId) {
				return $context['userId'] === $userId
					&& $context['statusCode'] === 404;
			}));

		// Should return true for 404 (treat as success)
		$result = $this->service->deleteEmailAccount($userId);

		$this->assertTrue($result);
	}

	public function testDeleteEmailAccountThrowsExceptionOnApiError(): void {
		$userId = 'testuser123';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock API to throw 500 exception
		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Internal Server Error',
			500,
			[],
			'{"error": "Server error"}'
		);

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'test-ext-ref', $userId)
			->willThrowException($apiException);

		// Expect logging calls
		$this->logger->expects($this->once())
			->method('info')
			->with('Attempting to delete IONOS email account', $this->callback(function ($context) use ($userId) {
				return $context['userId'] === $userId
					&& $context['extRef'] === 'test-ext-ref';
			}));

		$this->logger->expects($this->once())
			->method('error')
			->with('API Exception when calling MailConfigurationAPIApi->deleteMailbox', $this->callback(function ($context) use ($userId) {
				return $context['statusCode'] === 500
					&& $context['message'] === 'Internal Server Error'
					&& $context['userId'] === $userId;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to delete IONOS mail: Internal Server Error');
		$this->expectExceptionCode(500);

		$this->service->deleteEmailAccount($userId);
	}

	public function testDeleteEmailAccountThrowsExceptionOnGeneralError(): void {
		$userId = 'testuser123';

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Mock API to throw general exception
		$generalException = new \Exception('Unexpected error');

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'test-ext-ref', $userId)
			->willThrowException($generalException);

		// Expect logging calls
		$this->logger->expects($this->once())
			->method('info')
			->with('Attempting to delete IONOS email account', $this->callback(function ($context) use ($userId) {
				return $context['userId'] === $userId
					&& $context['extRef'] === 'test-ext-ref';
			}));

		$this->logger->expects($this->once())
			->method('error')
			->with('Exception when calling MailConfigurationAPIApi->deleteMailbox', $this->callback(function ($context) use ($userId) {
				return isset($context['exception'])
					&& $context['userId'] === $userId;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to delete IONOS mail');
		$this->expectExceptionCode(500);

		$this->service->deleteEmailAccount($userId);
	}

	public function testDeleteEmailAccountWithInsecureConnection(): void {
		$userId = 'testuser123';

		// Mock config with insecure connection allowed
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(true);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock API client - verify should be false
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => ['testuser', 'testpass'],
				'verify' => false,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')
			->with($client, 'https://api.example.com')
			->willReturn($apiInstance);

		// Mock successful deletion
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'test-ext-ref', $userId);

		$this->logger->expects($this->exactly(2))
			->method('info');

		$result = $this->service->deleteEmailAccount($userId);

		$this->assertTrue($result);
	}

	public function testTryDeleteEmailAccountWhenIntegrationDisabled(): void {
		$userId = 'testuser123';

		// Mock integration as disabled
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(false);

		// Should log that integration is not enabled
		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'IONOS integration is not enabled, skipping email account deletion',
				['userId' => $userId]
			);

		// Should not attempt to create API client
		$this->apiClientService->expects($this->never())
			->method('newClient');

		// Call tryDeleteEmailAccount - should not throw exception
		$this->service->tryDeleteEmailAccount($userId);

		$this->addToAssertionCount(1);
	}

	public function testTryDeleteEmailAccountWhenIntegrationEnabledSuccess(): void {
		$userId = 'testuser123';

		// Mock integration as enabled
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => ['testuser', 'testpass'],
				'verify' => true,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')
			->with($client, 'https://api.example.com')
			->willReturn($apiInstance);

		// Mock successful deletion
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'test-ext-ref', $userId);

		// Should log success at info level (from deleteEmailAccount only)
		$this->logger->expects($this->exactly(2))
			->method('info')
			->willReturnCallback(function ($message, $context) use ($userId) {
				if ($message === 'Attempting to delete IONOS email account') {
					$this->assertSame($userId, $context['userId']);
					$this->assertSame('test-ext-ref', $context['extRef']);
				} elseif ($message === 'Successfully deleted IONOS email account') {
					$this->assertSame($userId, $context['userId']);
				}
			});

		// Call tryDeleteEmailAccount - should not throw exception
		$this->service->tryDeleteEmailAccount($userId);

		$this->addToAssertionCount(1);
	}

	public function testTryDeleteEmailAccountWhenIntegrationEnabledButDeletionFails(): void {
		$userId = 'testuser123';

		// Mock integration as enabled
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => ['testuser', 'testpass'],
				'verify' => true,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')
			->with($client, 'https://api.example.com')
			->willReturn($apiInstance);

		// Mock API exception
		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException('API Error', 500);
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'test-ext-ref', $userId)
			->willThrowException($apiException);

		// Should log the error from deleteEmailAccount and then from tryDeleteEmailAccount
		$this->logger->expects($this->exactly(2))
			->method('error')
			->willReturnCallback(function ($message, $context) use ($userId) {
				if ($message === 'API Exception when calling MailConfigurationAPIApi->deleteMailbox') {
					// This is from deleteEmailAccount
					$this->assertSame($userId, $context['userId']);
					$this->assertSame(500, $context['statusCode']);
				} elseif ($message === 'Failed to delete IONOS mailbox for user') {
					// This is from tryDeleteEmailAccount
					$this->assertSame($userId, $context['userId']);
					$this->assertInstanceOf(ServiceException::class, $context['exception']);
				}
			});

		// Call tryDeleteEmailAccount - should NOT throw exception (fire and forget)
		$this->service->tryDeleteEmailAccount($userId);

		$this->addToAssertionCount(1);
	}

	public function testTryDeleteEmailAccountWhenMailboxNotFound(): void {
		$userId = 'testuser123';

		// Mock integration as enabled
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		// Mock config
		$this->configService->method('getExternalReference')->willReturn('test-ext-ref');
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getBasicAuthUser')->willReturn('testuser');
		$this->configService->method('getBasicAuthPassword')->willReturn('testpass');

		// Mock API client
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => ['testuser', 'testpass'],
				'verify' => true,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')
			->with($client, 'https://api.example.com')
			->willReturn($apiInstance);

		// Mock 404 API exception (mailbox already deleted or never existed)
		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException('Not Found', 404);
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'test-ext-ref', $userId)
			->willThrowException($apiException);

		// Should log at info level (from deleteEmailAccount) and debug (404 is treated as success)
		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Attempting to delete IONOS email account',
				[
					'userId' => $userId,
					'extRef' => 'test-ext-ref',
				]
			);

		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'IONOS mailbox does not exist (already deleted or never created)',
				[
					'userId' => $userId,
					'statusCode' => 404
				]
			);

		// Call tryDeleteEmailAccount - should NOT throw exception
		$this->service->tryDeleteEmailAccount($userId);

		$this->addToAssertionCount(1);
	}
}

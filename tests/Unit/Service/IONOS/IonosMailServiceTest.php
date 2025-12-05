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
use IONOS\MailConfigurationAPI\Client\Model\MailAccountCreatedResponse;
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
	private const TEST_USER_ID = 'testuser123';
	private const TEST_USER_NAME = 'test';
	private const TEST_DOMAIN = 'example.com';
	private const TEST_EMAIL = self::TEST_USER_NAME . '@' . self::TEST_DOMAIN;
	private const TEST_PASSWORD = 'test-password';
	private const TEST_EXT_REF = 'test-ext-ref';
	private const TEST_API_BASE_URL = 'https://api.example.com';
	private const TEST_BASIC_AUTH_USER = 'testuser';
	private const TEST_BASIC_AUTH_PASSWORD = 'testpass';
	private const IMAP_HOST = 'imap.example.com';
	private const IMAP_PORT = 993;
	private const SMTP_HOST = 'smtp.example.com';
	private const SMTP_PORT = 587;

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

	/**
	 * Setup standard config mocks with default values
	 */
	private function setupConfigMocks(
		string $externalReference = self::TEST_EXT_REF,
		string $apiBaseUrl = self::TEST_API_BASE_URL,
		bool $allowInsecure = false,
		string $basicAuthUser = self::TEST_BASIC_AUTH_USER,
		string $basicAuthPassword = self::TEST_BASIC_AUTH_PASSWORD,
		string $mailDomain = self::TEST_DOMAIN,
	): void {
		$this->configService->method('getExternalReference')->willReturn($externalReference);
		$this->configService->method('getApiBaseUrl')->willReturn($apiBaseUrl);
		$this->configService->method('getAllowInsecure')->willReturn($allowInsecure);
		$this->configService->method('getBasicAuthUser')->willReturn($basicAuthUser);
		$this->configService->method('getBasicAuthPassword')->willReturn($basicAuthPassword);
		$this->configService->method('getMailDomain')->willReturn($mailDomain);
	}

	/**
	 * Setup user session with mock user
	 */
	private function setupUserSession(string $userId): IUser&MockObject {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
		return $user;
	}

	/**
	 * Setup API client mocks and return API instance
	 */
	private function setupApiClient(bool $verifySSL = true): MailConfigurationAPIApi&MockObject {
		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => [self::TEST_BASIC_AUTH_USER, self::TEST_BASIC_AUTH_PASSWORD],
				'verify' => $verifySSL,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')
			->with($client, self::TEST_API_BASE_URL)
			->willReturn($apiInstance);

		return $apiInstance;
	}

	/**
	 * Create a mock IMAP server
	 */
	private function createMockImapServer(
		string $host = self::IMAP_HOST,
		int $port = self::IMAP_PORT,
		string $sslMode = 'ssl',
	): Imap&MockObject {
		$imapServer = $this->getMockBuilder(Imap::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHost', 'getPort', 'getSslMode'])
			->getMock();
		$imapServer->method('getHost')->willReturn($host);
		$imapServer->method('getPort')->willReturn($port);
		$imapServer->method('getSslMode')->willReturn($sslMode);
		return $imapServer;
	}

	/**
	 * Create a mock SMTP server
	 */
	private function createMockSmtpServer(
		string $host = self::SMTP_HOST,
		int $port = self::SMTP_PORT,
		string $sslMode = 'tls',
	): Smtp&MockObject {
		$smtpServer = $this->getMockBuilder(Smtp::class)
			->disableOriginalConstructor()
			->onlyMethods(['getHost', 'getPort', 'getSslMode'])
			->getMock();
		$smtpServer->method('getHost')->willReturn($host);
		$smtpServer->method('getPort')->willReturn($port);
		$smtpServer->method('getSslMode')->willReturn($sslMode);
		return $smtpServer;
	}

	/**
	 * Create a mock MailAccountResponse
	 */
	private function createMockMailAccountResponse(
		string $email = self::TEST_EMAIL,
		string $password = self::TEST_PASSWORD,
		?string $imapSslMode = 'ssl',
		?string $smtpSslMode = 'tls',
	): MailAccountCreatedResponse&MockObject {
		$imapServer = $this->createMockImapServer(self::IMAP_HOST, self::IMAP_PORT, $imapSslMode);
		$smtpServer = $this->createMockSmtpServer(self::SMTP_HOST, self::SMTP_PORT, $smtpSslMode);

		$mailServer = $this->getMockBuilder(MailServer::class)
			->disableOriginalConstructor()
			->onlyMethods(['getImap', 'getSmtp'])
			->getMock();
		$mailServer->method('getImap')->willReturn($imapServer);
		$mailServer->method('getSmtp')->willReturn($smtpServer);

		$mailAccountResponse = $this->getMockBuilder(MailAccountCreatedResponse::class)
			->disableOriginalConstructor()
			->onlyMethods(['getEmail', 'getPassword', 'getServer'])
			->getMock();
		$mailAccountResponse->method('getEmail')->willReturn($email);
		$mailAccountResponse->method('getPassword')->willReturn($password);
		$mailAccountResponse->method('getServer')->willReturn($mailServer);

		return $mailAccountResponse;
	}

	public function testCreateEmailAccountSuccess(): void {
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);
		$apiInstance = $this->setupApiClient();

		$mailAccountResponse = $this->createMockMailAccountResponse();
		$apiInstance->method('createMailbox')->willReturn($mailAccountResponse);

		$this->logger->expects($this->exactly(4))->method('debug');
		$this->logger->expects($this->once())
			->method('info')
			->with('Successfully created IONOS mail account', $this->callback(function ($context) {
				return $context['email'] === self::TEST_EMAIL
					&& $context['userId'] === self::TEST_USER_ID
					&& $context['userName'] === self::TEST_USER_NAME;
			}));

		$result = $this->service->createEmailAccount(self::TEST_USER_NAME);

		$this->assertInstanceOf(MailAccountConfig::class, $result);
		$this->assertEquals(self::TEST_EMAIL, $result->getEmail());
		$this->assertEquals(self::IMAP_HOST, $result->getImap()->getHost());
		$this->assertEquals(self::IMAP_PORT, $result->getImap()->getPort());
		$this->assertEquals('ssl', $result->getImap()->getSecurity());
		$this->assertEquals(self::TEST_EMAIL, $result->getImap()->getUsername());
		$this->assertEquals(self::TEST_PASSWORD, $result->getImap()->getPassword());
		$this->assertEquals(self::SMTP_HOST, $result->getSmtp()->getHost());
		$this->assertEquals(self::SMTP_PORT, $result->getSmtp()->getPort());
		$this->assertEquals('tls', $result->getSmtp()->getSecurity());
		$this->assertEquals(self::TEST_EMAIL, $result->getSmtp()->getUsername());
		$this->assertEquals(self::TEST_PASSWORD, $result->getSmtp()->getPassword());
	}

	public function testCreateEmailAccountWithApiException(): void {
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiInstance->method('createMailbox')
			->willThrowException(new \Exception('API call failed'));

		$this->logger->expects($this->exactly(2))->method('debug');
		$this->logger->expects($this->once())
			->method('error')
			->with('Exception when calling MailConfigurationAPIApi->createMailbox', $this->callback(function ($context) {
				return isset($context['exception'])
					&& $context['userId'] === self::TEST_USER_ID
					&& $context['userName'] === self::TEST_USER_NAME;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');
		$this->expectExceptionCode(500);

		$this->service->createEmailAccount(self::TEST_USER_NAME);
	}

	public function testCreateEmailAccountWithMailAddonErrorMessageResponse(): void {
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$errorMessage = $this->getMockBuilder(MailAddonErrorMessage::class)
			->disableOriginalConstructor()
			->onlyMethods(['getStatus', 'getMessage'])
			->getMock();
		$errorMessage->method('getStatus')->willReturn(MailAddonErrorMessage::STATUS__400_BAD_REQUEST);
		$errorMessage->method('getMessage')->willReturn('Bad Request');

		$apiInstance->method('createMailbox')->willReturn($errorMessage);

		$this->logger->expects($this->exactly(2))->method('debug');
		$this->logger->expects($this->once())
			->method('error')
			->with('Failed to create ionos mail', $this->callback(function ($context) {
				return $context['status code'] === MailAddonErrorMessage::STATUS__400_BAD_REQUEST
					&& $context['message'] === 'Bad Request'
					&& $context['userId'] === self::TEST_USER_ID
					&& $context['userName'] === self::TEST_USER_NAME;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');
		$this->expectExceptionCode(400);

		$this->service->createEmailAccount(self::TEST_USER_NAME);
	}

	public function testCreateEmailAccountWithUnknownResponseType(): void {
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$unknownResponse = new \stdClass();
		$apiInstance->method('createMailbox')->willReturn($unknownResponse);

		$this->logger->expects($this->exactly(2))->method('debug');
		$this->logger->expects($this->once())
			->method('error')
			->with('Failed to create ionos mail: Unknown response type', $this->callback(function ($context) {
				return $context['userId'] === self::TEST_USER_ID
					&& $context['userName'] === self::TEST_USER_NAME;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');
		$this->expectExceptionCode(500);

		$this->service->createEmailAccount(self::TEST_USER_NAME);
	}

	public function testCreateEmailAccountWithNoUserSession(): void {
		$this->setupConfigMocks();
		$this->userSession->method('getUser')->willReturn(null);

		$this->logger->expects($this->once())
			->method('error')
			->with('No user session found when attempting to create IONOS mail account');

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('No user session found');

		$this->service->createEmailAccount(self::TEST_USER_NAME);
	}

	/**
	 * Test SSL mode normalization with various API response values
	 *
	 * @dataProvider sslModeNormalizationProvider
	 */
	public function testSslModeNormalization(string $apiSslMode, string $expectedSecurity): void {
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);
		$apiInstance = $this->setupApiClient();

		$mailAccountResponse = $this->createMockMailAccountResponse(
			self::TEST_EMAIL,
			self::TEST_PASSWORD,
			$apiSslMode,
			$apiSslMode
		);

		$apiInstance->method('createMailbox')->willReturn($mailAccountResponse);

		$result = $this->service->createEmailAccount(self::TEST_USER_NAME);

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
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$mailAccountResponse = $this->getMockBuilder(MailAccountResponse::class)
			->disableOriginalConstructor()
			->onlyMethods(['getEmail'])
			->getMock();
		$mailAccountResponse->method('getEmail')->willReturn(self::TEST_EMAIL);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willReturn($mailAccountResponse);

		$this->logger->expects($this->exactly(2))->method('debug');

		$result = $this->service->mailAccountExistsForCurrentUser();

		$this->assertTrue($result);
	}

	public function testMailAccountExistsForCurrentUserReturnsFalseWhen404(): void {
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Not Found',
			404,
			[],
			'{"error": "Not Found"}'
		);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($apiException);

		$this->logger->expects($this->exactly(2))->method('debug');

		$result = $this->service->mailAccountExistsForCurrentUser();

		$this->assertFalse($result);
	}

	public function testMailAccountExistsForCurrentUserReturnsFalseOnApiError(): void {
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Internal Server Error',
			500,
			[],
			'{"error": "Server error"}'
		);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($apiException);

		$this->logger->expects($this->once())->method('debug');
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
		$this->setupConfigMocks();
		$this->setupUserSession(self::TEST_USER_ID);

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException(new \Exception('Unexpected error'));

		$this->logger->expects($this->once())->method('debug');
		$this->logger->expects($this->once())
			->method('error')
			->with('Exception when getting IONOS mail account', $this->callback(function ($context) {
				return isset($context['exception'])
					&& $context['userId'] === self::TEST_USER_ID;
			}));

		$result = $this->service->mailAccountExistsForCurrentUser();

		$this->assertFalse($result);
	}


	public function testDeleteEmailAccountSuccess(): void {
		$this->setupConfigMocks();
		$apiInstance = $this->setupApiClient();

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID);

		$callCount = 0;
		$this->logger->expects($this->exactly(2))
			->method('info')
			->willReturnCallback(function ($message, $context) use (&$callCount) {
				$callCount++;
				if ($callCount === 1) {
					$this->assertEquals('Attempting to delete IONOS email account', $message);
					$this->assertEquals(self::TEST_USER_ID, $context['userId']);
					$this->assertEquals(self::TEST_EXT_REF, $context['extRef']);
				} elseif ($callCount === 2) {
					$this->assertEquals('Successfully deleted IONOS email account', $message);
					$this->assertEquals(self::TEST_USER_ID, $context['userId']);
				}
			});

		$result = $this->service->deleteEmailAccount(self::TEST_USER_ID);

		$this->assertTrue($result);
	}

	public function testDeleteEmailAccountReturns404AlreadyDeleted(): void {
		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Not Found',
			404,
			[],
			'{"error": "Not Found"}'
		);

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($apiException);

		$this->logger->expects($this->once())
			->method('info')
			->with('Attempting to delete IONOS email account', $this->callback(function ($context) {
				return $context['userId'] === self::TEST_USER_ID
					&& $context['extRef'] === self::TEST_EXT_REF;
			}));

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mailbox does not exist (already deleted or never created)', $this->callback(function ($context) {
				return $context['userId'] === self::TEST_USER_ID
					&& $context['statusCode'] === 404;
			}));

		$result = $this->service->deleteEmailAccount(self::TEST_USER_ID);

		$this->assertTrue($result);
	}

	public function testDeleteEmailAccountThrowsExceptionOnApiError(): void {
		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Internal Server Error',
			500,
			[],
			'{"error": "Server error"}'
		);

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($apiException);

		$this->logger->expects($this->once())
			->method('info')
			->with('Attempting to delete IONOS email account', $this->callback(function ($context) {
				return $context['userId'] === self::TEST_USER_ID
					&& $context['extRef'] === self::TEST_EXT_REF;
			}));

		$this->logger->expects($this->once())
			->method('error')
			->with('API Exception when calling MailConfigurationAPIApi->deleteMailbox', $this->callback(function ($context) {
				return $context['statusCode'] === 500
					&& $context['message'] === 'Internal Server Error'
					&& $context['userId'] === self::TEST_USER_ID;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to delete IONOS mail: Internal Server Error');
		$this->expectExceptionCode(500);

		$this->service->deleteEmailAccount(self::TEST_USER_ID);
	}

	public function testDeleteEmailAccountThrowsExceptionOnGeneralError(): void {
		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$generalException = new \Exception('Unexpected error');

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($generalException);

		$this->logger->expects($this->once())
			->method('info')
			->with('Attempting to delete IONOS email account', $this->callback(function ($context) {
				return $context['userId'] === self::TEST_USER_ID
					&& $context['extRef'] === self::TEST_EXT_REF;
			}));

		$this->logger->expects($this->once())
			->method('error')
			->with('Exception when calling MailConfigurationAPIApi->deleteMailbox', $this->callback(function ($context) {
				return isset($context['exception'])
					&& $context['userId'] === self::TEST_USER_ID;
			}));

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to delete IONOS mail');
		$this->expectExceptionCode(500);

		$this->service->deleteEmailAccount(self::TEST_USER_ID);
	}

	public function testDeleteEmailAccountWithInsecureConnection(): void {
		$this->setupConfigMocks(allowInsecure: true);
		$apiInstance = $this->setupApiClient(verifySSL: false);

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID);

		$this->logger->expects($this->exactly(2))->method('info');

		$result = $this->service->deleteEmailAccount(self::TEST_USER_ID);

		$this->assertTrue($result);
	}

	public function testTryDeleteEmailAccountWhenIntegrationDisabled(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(false);

		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'IONOS integration is not enabled, skipping email account deletion',
				['userId' => self::TEST_USER_ID]
			);

		$this->apiClientService->expects($this->never())->method('newClient');

		$this->service->tryDeleteEmailAccount(self::TEST_USER_ID);

		$this->addToAssertionCount(1);
	}

	public function testTryDeleteEmailAccountWhenIntegrationEnabledSuccess(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->setupConfigMocks();
		$apiInstance = $this->setupApiClient();

		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID);

		$this->logger->expects($this->exactly(2))
			->method('info')
			->willReturnCallback(function ($message, $context) {
				if ($message === 'Attempting to delete IONOS email account') {
					$this->assertSame(self::TEST_USER_ID, $context['userId']);
					$this->assertSame(self::TEST_EXT_REF, $context['extRef']);
				} elseif ($message === 'Successfully deleted IONOS email account') {
					$this->assertSame(self::TEST_USER_ID, $context['userId']);
				}
			});

		$this->service->tryDeleteEmailAccount(self::TEST_USER_ID);

		$this->addToAssertionCount(1);
	}

	public function testTryDeleteEmailAccountWhenIntegrationEnabledButDeletionFails(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => [self::TEST_BASIC_AUTH_USER, self::TEST_BASIC_AUTH_PASSWORD],
				'verify' => true,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')
			->with($client, self::TEST_API_BASE_URL)
			->willReturn($apiInstance);

		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException('API Error', 500);
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($apiException);

		$this->logger->expects($this->exactly(2))
			->method('error')
			->willReturnCallback(function ($message, $context) {
				if ($message === 'API Exception when calling MailConfigurationAPIApi->deleteMailbox') {
					$this->assertSame(self::TEST_USER_ID, $context['userId']);
					$this->assertSame(500, $context['statusCode']);
				} elseif ($message === 'Failed to delete IONOS mailbox for user') {
					$this->assertSame(self::TEST_USER_ID, $context['userId']);
					$this->assertInstanceOf(ServiceException::class, $context['exception']);
				}
			});

		$this->service->tryDeleteEmailAccount(self::TEST_USER_ID);

		$this->addToAssertionCount(1);
	}

	public function testTryDeleteEmailAccountWhenMailboxNotFound(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')
			->with([
				'auth' => [self::TEST_BASIC_AUTH_USER, self::TEST_BASIC_AUTH_PASSWORD],
				'verify' => true,
			])
			->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')
			->with($client, self::TEST_API_BASE_URL)
			->willReturn($apiInstance);

		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException('Not Found', 404);
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($apiException);

		$this->logger->expects($this->once())
			->method('info')
			->with(
				'Attempting to delete IONOS email account',
				[
					'userId' => self::TEST_USER_ID,
					'extRef' => self::TEST_EXT_REF,
				]
			);

		$this->logger->expects($this->once())
			->method('debug')
			->with(
				'IONOS mailbox does not exist (already deleted or never created)',
				[
					'userId' => self::TEST_USER_ID,
					'statusCode' => 404
				]
			);

		$this->service->tryDeleteEmailAccount(self::TEST_USER_ID);

		$this->addToAssertionCount(1);
	}

	public function testGetIonosEmailForUserReturnsEmailWhenAccountExists(): void {
		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$mailAccountResponse = $this->getMockBuilder(MailAccountResponse::class)
			->disableOriginalConstructor()
			->onlyMethods(['getEmail'])
			->getMock();
		$mailAccountResponse->method('getEmail')->willReturn(self::TEST_EMAIL);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willReturn($mailAccountResponse);

		$this->logger->expects($this->exactly(2))->method('debug');

		$result = $this->service->getIonosEmailForUser(self::TEST_USER_ID);

		$this->assertEquals(self::TEST_EMAIL, $result);
	}

	public function testGetIonosEmailForUserReturnsNullWhen404(): void {
		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Not Found',
			404,
			[],
			'{"error": "Not Found"}'
		);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($apiException);

		$this->logger->expects($this->exactly(2))->method('debug');

		$result = $this->service->getIonosEmailForUser(self::TEST_USER_ID);

		$this->assertNull($result);
	}

	public function testGetIonosEmailForUserReturnsNullOnApiError(): void {
		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiException = new \IONOS\MailConfigurationAPI\Client\ApiException(
			'Internal Server Error',
			500,
			[],
			'{"error": "Server error"}'
		);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException($apiException);

		$this->logger->expects($this->once())->method('debug');
		$this->logger->expects($this->once())
			->method('error')
			->with('API Exception when getting IONOS mail account', $this->callback(function ($context) {
				return $context['statusCode'] === 500
					&& $context['message'] === 'Internal Server Error';
			}));

		$result = $this->service->getIonosEmailForUser(self::TEST_USER_ID);

		$this->assertNull($result);
	}

	public function testGetIonosEmailForUserReturnsNullOnGeneralException(): void {
		$this->setupConfigMocks();

		$client = $this->createMock(ClientInterface::class);
		$this->apiClientService->method('newClient')->willReturn($client);

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$apiInstance->method('getFunctionalAccount')
			->with('IONOS', self::TEST_EXT_REF, self::TEST_USER_ID)
			->willThrowException(new \Exception('Unexpected error'));

		$this->logger->expects($this->once())->method('debug');
		$this->logger->expects($this->once())
			->method('error')
			->with('Exception when getting IONOS mail account', $this->callback(function ($context) {
				return isset($context['exception'])
					&& $context['userId'] === self::TEST_USER_ID;
			}));

		$result = $this->service->getIonosEmailForUser(self::TEST_USER_ID);

		$this->assertNull($result);
	}
}

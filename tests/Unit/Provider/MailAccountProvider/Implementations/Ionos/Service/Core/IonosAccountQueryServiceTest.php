<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations\Ionos\Service\Core;

use ChristophWurst\Nextcloud\Testing\TestCase;
use GuzzleHttp\Client;
use IONOS\MailConfigurationAPI\Client\Api\MailConfigurationAPIApi;
use IONOS\MailConfigurationAPI\Client\ApiException;
use OCA\Mail\Provider\MailAccountProvider\Common\Dto\MailAccountConfig;
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
	private IonosAccountQueryService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->apiClientService = $this->createMock(ApiMailConfigClientService::class);
		$this->configService = $this->createMock(IonosConfigService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new IonosAccountQueryService(
			$this->apiClientService,
			$this->configService,
			$this->userSession,
			$this->logger,
		);
	}

	public function testMailAccountExistsForCurrentUser(): void {
		$userId = 'testuser';
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountResponse('test@example.com');
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->mailAccountExistsForCurrentUser();

		$this->assertTrue($result);
	}

	public function testMailAccountExistsForCurrentUserNoUser(): void {
		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$this->expectException(\RuntimeException::class);
		$this->expectExceptionMessage('No user logged in');

		$this->service->mailAccountExistsForCurrentUser();
	}

	public function testMailAccountExistsForUserIdTrue(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountResponse('test@example.com');
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->mailAccountExistsForUserId($userId);

		$this->assertTrue($result);
	}

	public function testMailAccountExistsForUserIdFalse(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMockApiInstance(null);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->mailAccountExistsForUserId($userId);

		$this->assertFalse($result);
	}

	public function testGetMailAccountResponseSuccess(): void {
		$userId = 'testuser';
		$email = 'test@example.com';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountResponse($email);
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getMailAccountResponse($userId);

		$this->assertNotNull($result);
		$this->assertSame($email, $result->getEmail());
	}

	public function testGetMailAccountResponseNotFound(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiException = new ApiException('Not found', 404);
		$apiInstance = $this->createMockApiInstanceWithException($apiException);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->logger->expects($this->exactly(2))
			->method('debug');

		$result = $this->service->getMailAccountResponse($userId);

		$this->assertNull($result);
	}

	public function testGetMailAccountResponseApiError(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiException = new ApiException('Server error', 500);
		$apiInstance = $this->createMockApiInstanceWithException($apiException);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->logger->expects($this->once())
			->method('error')
			->with('Error checking IONOS mail account', $this->anything());

		$result = $this->service->getMailAccountResponse($userId);

		$this->assertNull($result);
	}

	public function testGetMailAccountResponseUnexpectedError(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$exception = new \Exception('Unexpected error');
		$apiInstance = $this->createMockApiInstanceWithException($exception);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->logger->expects($this->once())
			->method('error')
			->with('Unexpected error checking IONOS mail account', $this->anything());

		$result = $this->service->getMailAccountResponse($userId);

		$this->assertNull($result);
	}

	public function testGetAccountConfigForUserSuccess(): void {
		$userId = 'testuser';
		$email = 'test@example.com';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountResponse($email);
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getAccountConfigForUser($userId);

		$this->assertInstanceOf(MailAccountConfig::class, $result);
		$this->assertSame($email, $result->getEmail());
		$this->assertNotNull($result->getImap());
		$this->assertNotNull($result->getSmtp());
	}

	public function testGetAccountConfigForUserNotFound(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMockApiInstance(null);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getAccountConfigForUser($userId);

		$this->assertNull($result);
	}

	public function testGetAccountConfigForCurrentUser(): void {
		$userId = 'testuser';
		$email = 'test@example.com';

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountResponse($email);
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getAccountConfigForCurrentUser();

		$this->assertInstanceOf(MailAccountConfig::class, $result);
		$this->assertSame($email, $result->getEmail());
	}

	public function testGetIonosEmailForUserSuccess(): void {
		$userId = 'testuser';
		$email = 'test@example.com';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountResponse($email);
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getIonosEmailForUser($userId);

		$this->assertSame($email, $result);
	}

	public function testGetIonosEmailForUserNotFound(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMockApiInstance(null);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getIonosEmailForUser($userId);

		$this->assertNull($result);
	}

	public function testGetIonosEmailForUserError(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$exception = new \Exception('Unexpected error');
		$apiInstance = $this->createMockApiInstanceWithException($exception);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getIonosEmailForUser($userId);

		$this->assertNull($result);
	}

	public function testGetMailDomain(): void {
		$domain = 'example.com';

		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willReturn($domain);

		$result = $this->service->getMailDomain();

		$this->assertSame($domain, $result);
	}

	public function testCreateApiInstanceWithInsecureConnection(): void {
		$userId = 'testuser';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(true);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountResponse('test@example.com');
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->expects($this->once())
			->method('newClient')
			->with([
				'auth' => ['auth-user', 'auth-pass'],
				'verify' => false,
			])
			->willReturn($client);

		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getMailAccountResponse($userId);

		$this->assertNotNull($result);
	}

	public function testGetAllMailAccountResponsesReturnsAccounts(): void {
		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response1 = $this->createMailAccountResponse('user1@example.com');
		$response2 = $this->createMailAccountResponse('user2@example.com');

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->expects($this->once())
			->method('getAllFunctionalAccounts')
			->willReturn([$response1, $response2]);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getAllMailAccountResponses();

		$this->assertIsArray($result);
		$this->assertCount(2, $result);
		$this->assertSame($response1, $result[0]);
		$this->assertSame($response2, $result[1]);
	}

	public function testGetAllMailAccountResponsesReturnsEmptyArray(): void {
		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->expects($this->once())
			->method('getAllFunctionalAccounts')
			->willReturn([]);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->getAllMailAccountResponses();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testGetAllMailAccountResponsesHandlesApiException(): void {
		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->expects($this->once())
			->method('getAllFunctionalAccounts')
			->willThrowException(new ApiException('API error', 500));

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->logger->expects($this->once())
			->method('error')
			->with('API error getting all IONOS mail accounts', $this->anything());

		$result = $this->service->getAllMailAccountResponses();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testGetAllMailAccountResponsesHandlesGeneralException(): void {
		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->expects($this->once())
			->method('getAllFunctionalAccounts')
			->willThrowException(new \Exception('Unexpected error'));

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->logger->expects($this->once())
			->method('error')
			->with('Unexpected error getting all IONOS mail accounts', $this->anything());

		$result = $this->service->getAllMailAccountResponses();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	private function createMailAccountResponse(string $email): MockObject {
		// Create mock IMAP server
		$imap = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getHost', 'getPort', 'getPassword'])
			->getMock();
		$imap->method('getHost')->willReturn('imap.example.com');
		$imap->method('getPort')->willReturn(993);
		$imap->method('getPassword')->willReturn('imap-password');

		// Create mock SMTP server
		$smtp = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getHost', 'getPort', 'getPassword'])
			->getMock();
		$smtp->method('getHost')->willReturn('smtp.example.com');
		$smtp->method('getPort')->willReturn(587);
		$smtp->method('getPassword')->willReturn('smtp-password');

		// Create mock MailAccountResponse with proper class to pass instanceof check
		$response = $this->getMockBuilder(\IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse::class)
			->disableOriginalConstructor()
			->onlyMethods(['getEmail'])
			->addMethods(['getImap', 'getSmtp'])
			->getMock();
		$response->method('getEmail')->willReturn($email);
		$response->method('getImap')->willReturn($imap);
		$response->method('getSmtp')->willReturn($smtp);

		return $response;
	}

	private function createMockApiInstance(?MockObject $response): MailConfigurationAPIApi&MockObject {
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);

		$apiInstance->method('getFunctionalAccount')
			->willReturn($response);

		return $apiInstance;
	}

	private function createMockApiInstanceWithException(\Exception $exception): MailConfigurationAPIApi&MockObject {
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);

		$apiInstance->method('getFunctionalAccount')
			->willThrowException($exception);

		return $apiInstance;
	}
}

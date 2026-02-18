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
use IONOS\MailConfigurationAPI\Client\Model\ImapConfig;
use IONOS\MailConfigurationAPI\Client\Model\MailAccountCreatedResponse;
use IONOS\MailConfigurationAPI\Client\Model\MailAddonErrorMessage;
use IONOS\MailConfigurationAPI\Client\Model\SmtpConfig;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\Common\Dto\MailAccountConfig;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\ApiMailConfigClientService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core\IonosAccountMutationService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosAccountMutationServiceTest extends TestCase {
	private ApiMailConfigClientService&MockObject $apiClientService;
	private IonosConfigService&MockObject $configService;
	private IUserSession&MockObject $userSession;
	private LoggerInterface&MockObject $logger;
	private IonosAccountMutationService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->apiClientService = $this->createMock(ApiMailConfigClientService::class);
		$this->configService = $this->createMock(IonosConfigService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new IonosAccountMutationService(
			$this->apiClientService,
			$this->configService,
			$this->userSession,
			$this->logger,
		);
	}

	public function testCreateEmailAccountSuccess(): void {
		$userId = 'testuser';
		$userName = 'john';
		$domain = 'example.com';
		$email = 'john@example.com';

		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn($user);

		$this->configService->method('getMailDomain')->willReturn($domain);
		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountCreatedResponse($email, 'password123');
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->createEmailAccount($userName);

		$this->assertInstanceOf(MailAccountConfig::class, $result);
		$this->assertSame($email, $result->getEmail());
		$this->assertNotNull($result->getImap());
		$this->assertNotNull($result->getSmtp());
	}

	public function testCreateEmailAccountNoUserSession(): void {
		$userName = 'john';

		$this->userSession->expects($this->once())
			->method('getUser')
			->willReturn(null);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('No user session found');

		$this->service->createEmailAccount($userName);
	}

	public function testCreateEmailAccountForUserSuccess(): void {
		$userId = 'testuser';
		$userName = 'john';
		$domain = 'example.com';
		$email = 'john@example.com';

		$this->configService->method('getMailDomain')->willReturn($domain);
		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$response = $this->createMailAccountCreatedResponse($email, 'password123');
		$apiInstance = $this->createMockApiInstance($response);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->createEmailAccountForUser($userId, $userName);

		$this->assertInstanceOf(MailAccountConfig::class, $result);
		$this->assertSame($email, $result->getEmail());
	}

	public function testCreateEmailAccountForUserErrorResponse(): void {
		$userId = 'testuser';
		$userName = 'john';
		$domain = 'example.com';

		$this->configService->method('getMailDomain')->willReturn($domain);
		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$errorResponse = $this->createMailAddonErrorMessage(400, 'Bad request');
		$apiInstance = $this->createMockApiInstance($errorResponse);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');
		$this->expectExceptionCode(400);

		$this->service->createEmailAccountForUser($userId, $userName);
	}

	public function testCreateEmailAccountForUserApiException(): void {
		$userId = 'testuser';
		$userName = 'john';
		$domain = 'example.com';

		$this->configService->method('getMailDomain')->willReturn($domain);
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

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail: Server error');
		$this->expectExceptionCode(500);

		$this->service->createEmailAccountForUser($userId, $userName);
	}

	public function testCreateEmailAccountForUserUnexpectedException(): void {
		$userId = 'testuser';
		$userName = 'john';
		$domain = 'example.com';

		$this->configService->method('getMailDomain')->willReturn($domain);
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

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create ionos mail');
		$this->expectExceptionCode(500);

		$this->service->createEmailAccountForUser($userId, $userName);
	}

	public function testDeleteEmailAccountSuccess(): void {
		$userId = 'testuser';
		$email = 'testuser@example.com';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'ext-ref', $userId);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->deleteEmailAccount($userId, $email);

		$this->assertTrue($result);
	}

	public function testDeleteEmailAccountNotFound(): void {
		$userId = 'testuser';
		$email = 'testuser@example.com';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiException = new ApiException('Not found', 404);
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->method('deleteMailbox')
			->willThrowException($apiException);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// 404 should be treated as success (already deleted)
		$result = $this->service->deleteEmailAccount($userId, $email);

		$this->assertTrue($result);
	}

	public function testDeleteEmailAccountApiException(): void {
		$userId = 'testuser';
		$email = 'testuser@example.com';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiException = new ApiException('Server error', 500);
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->method('deleteMailbox')
			->willThrowException($apiException);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to delete IONOS mail: Server error');
		$this->expectExceptionCode(500);

		$this->service->deleteEmailAccount($userId, $email);
	}

	public function testDeleteEmailAccountServiceException(): void {
		$userId = 'testuser';
		$email = 'testuser@example.com';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$serviceException = new ServiceException('Service layer error', 503);
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->method('deleteMailbox')
			->willThrowException($serviceException);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Verify that ServiceException is re-thrown without additional logging
		$this->logger->expects($this->never())
			->method('error');

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Service layer error');
		$this->expectExceptionCode(503);

		$this->service->deleteEmailAccount($userId, $email);
	}

	public function testDeleteEmailAccountUnexpectedException(): void {
		$userId = 'testuser';
		$email = 'testuser@example.com';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$exception = new \Exception('Unexpected error');
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->method('deleteMailbox')
			->willThrowException($exception);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to delete IONOS mail');
		$this->expectExceptionCode(500);

		$this->service->deleteEmailAccount($userId, $email);
	}

	public function testTryDeleteEmailAccountSuccess(): void {
		$userId = 'testuser';
		$email = 'testuser@example.com';

		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->expects($this->once())
			->method('deleteMailbox')
			->with('IONOS', 'ext-ref', $userId);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		// Should not throw exception
		$this->service->tryDeleteEmailAccount($userId, $email);
	}

	public function testTryDeleteEmailAccountDisabledIntegration(): void {
		$userId = 'testuser';
		$email = 'testuser@example.com';

		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(false);

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS integration is not enabled, skipping email account deletion', $this->anything());

		// Should not attempt deletion
		$this->apiClientService->expects($this->never())
			->method('newClient');

		$this->service->tryDeleteEmailAccount($userId, $email);
	}

	public function testTryDeleteEmailAccountSuppressesExceptions(): void {
		$userId = 'testuser';
		$email = 'testuser@example.com';

		$this->configService->method('isIonosIntegrationEnabled')->willReturn(true);
		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiException = new ApiException('Server error', 500);
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->method('deleteMailbox')
			->willThrowException($apiException);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->logger->expects($this->atLeastOnce())
			->method('error');

		// Should not throw exception (fire and forget)
		$this->service->tryDeleteEmailAccount($userId, $email);
	}

	public function testResetAppPasswordSuccess(): void {
		$userId = 'testuser';
		$appName = 'TestApp';
		$newPassword = 'new-password-123';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->expects($this->once())
			->method('setAppPassword')
			->with('IONOS', 'ext-ref', $userId, $appName)
			->willReturn($newPassword);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$result = $this->service->resetAppPassword($userId, $appName);

		$this->assertSame($newPassword, $result);
	}

	public function testResetAppPasswordUnexpectedResponse(): void {
		$userId = 'testuser';
		$appName = 'TestApp';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->method('setAppPassword')
			->willReturn(['not' => 'a string']);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to reset IONOS app password');
		$this->expectExceptionCode(500);

		$this->service->resetAppPassword($userId, $appName);
	}

	public function testResetAppPasswordApiException(): void {
		$userId = 'testuser';
		$appName = 'TestApp';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$apiException = new ApiException('Server error', 500);
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->method('setAppPassword')
			->willThrowException($apiException);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to reset IONOS app password: Server error');
		$this->expectExceptionCode(500);

		$this->service->resetAppPassword($userId, $appName);
	}

	public function testResetAppPasswordUnexpectedException(): void {
		$userId = 'testuser';
		$appName = 'TestApp';

		$this->configService->method('getExternalReference')->willReturn('ext-ref');
		$this->configService->method('getBasicAuthUser')->willReturn('auth-user');
		$this->configService->method('getBasicAuthPassword')->willReturn('auth-pass');
		$this->configService->method('getAllowInsecure')->willReturn(false);
		$this->configService->method('getApiBaseUrl')->willReturn('https://api.example.com');

		$exception = new \Exception('Unexpected error');
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);
		$apiInstance->method('setAppPassword')
			->willThrowException($exception);

		$client = $this->createMock(Client::class);
		$this->apiClientService->method('newClient')->willReturn($client);
		$this->apiClientService->method('newMailConfigurationAPIApi')->willReturn($apiInstance);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to reset IONOS app password');
		$this->expectExceptionCode(500);

		$this->service->resetAppPassword($userId, $appName);
	}

	private function createMailAccountCreatedResponse(string $email, string $password): MailAccountCreatedResponse&MockObject {
		$imap = $this->createMock(ImapConfig::class);
		$imap->method('getHost')->willReturn('imap.example.com');
		$imap->method('getPort')->willReturn(993);
		$imap->method('getSslMode')->willReturn('TLS');

		$smtp = $this->createMock(SmtpConfig::class);
		$smtp->method('getHost')->willReturn('smtp.example.com');
		$smtp->method('getPort')->willReturn(587);
		$smtp->method('getSslMode')->willReturn('TLS');

		$server = $this->getMockBuilder(\stdClass::class)
			->addMethods(['getImap', 'getSmtp'])
			->getMock();
		$server->method('getImap')->willReturn($imap);
		$server->method('getSmtp')->willReturn($smtp);

		$response = $this->createMock(MailAccountCreatedResponse::class);
		$response->method('getEmail')->willReturn($email);
		$response->method('getPassword')->willReturn($password);
		$response->method('getServer')->willReturn($server);

		return $response;
	}

	private function createMailAddonErrorMessage(int $status, string $message): MailAddonErrorMessage&MockObject {
		$errorResponse = $this->createMock(MailAddonErrorMessage::class);
		$errorResponse->method('getStatus')->willReturn($status);
		$errorResponse->method('getMessage')->willReturn($message);

		return $errorResponse;
	}

	private function createMockApiInstance(mixed $response): MailConfigurationAPIApi&MockObject {
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);

		$apiInstance->method('createMailbox')
			->willReturn($response);

		return $apiInstance;
	}

	private function createMockApiInstanceWithException(\Exception $exception): MailConfigurationAPIApi&MockObject {
		$apiInstance = $this->createMock(MailConfigurationAPIApi::class);

		$apiInstance->method('createMailbox')
			->willThrowException($exception);

		return $apiInstance;
	}
}

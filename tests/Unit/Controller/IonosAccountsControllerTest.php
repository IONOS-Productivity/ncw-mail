<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Controller;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Controller\AccountsController;
use OCA\Mail\Controller\IonosAccountsController;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\ConflictResolutionResult;
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\Dto\MailServerConfig;
use OCA\Mail\Service\IONOS\IonosAccountConflictResolver;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class IonosAccountsControllerTest extends TestCase {
	private string $appName;

	private IRequest&MockObject $request;

	private IonosMailService&MockObject $ionosMailService;

	private IonosAccountConflictResolver&MockObject $conflictResolver;

	private AccountsController&MockObject $accountsController;

	private IUserSession&MockObject $userSession;

	private LoggerInterface|MockObject $logger;

	private IonosAccountsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->appName = 'mail';
		$this->request = $this->createMock(IRequest::class);
		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->conflictResolver = $this->createMock(IonosAccountConflictResolver::class);
		$this->accountsController = $this->createMock(AccountsController::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new IonosAccountsController(
			$this->appName,
			$this->request,
			$this->ionosMailService,
			$this->conflictResolver,
			$this->accountsController,
			$this->userSession,
			$this->logger,
		);
	}

	/**
	 * Helper method to setup user session mock
	 */
	private function setupUserSession(string $userId): void {
		$user = $this->createMock(IUser::class);
		$user->method('getUID')->willReturn($userId);
		$this->userSession->method('getUser')->willReturn($user);
	}

	public function testCreateWithMissingFields(): void {
		// Test with empty account name
		$response = $this->controller->create('', 'testuser');
		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('All fields are required', $data['message']);
		$this->assertEquals('IONOS_API_ERROR', $data['error']);

		// Test with empty email user
		$response = $this->controller->create('Test Account', '');
		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('All fields are required', $data['message']);
		$this->assertEquals('IONOS_API_ERROR', $data['error']);
	}

	public function testCreateSuccess(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$emailAddress = 'test@example.com';

		// Create MailAccountConfig DTO
		$imapConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1143,
			security: 'none',
			username: $emailAddress,
			password: 'tmp',
		);

		$smtpConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1587,
			security: 'none',
			username: $emailAddress,
			password: 'tmp',
		);

		$mailAccountConfig = new MailAccountConfig(
			email: $emailAddress,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);

		// Mock successful IONOS mail service response
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willReturn($mailAccountConfig);

		// Mock account creation response
		$accountData = ['id' => 1, 'emailAddress' => $emailAddress];
		$accountResponse = $this->createMock(JSONResponse::class);
		$accountResponse->method('getData')->willReturn($accountData);

		$this->accountsController
			->method('create')
			->with(
				$accountName,
				$emailAddress,
				'mail.localhost',
				1143,
				'none',
				$emailAddress,
				'tmp',
				'mail.localhost',
				1587,
				'none',
				$emailAddress,
				'tmp',
			)
			->willReturn($accountResponse);

		$response = $this->controller->create($accountName, $emailUser);

		// The controller now directly returns the AccountsController response
		$this->assertSame($accountResponse, $response);
	}

	public function testCreateWithServiceException(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$userId = 'testuser';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock IONOS mail service to throw ServiceException
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willThrowException(new ServiceException('Failed to create email account'));

		// Mock conflict resolver to return no existing account
		$this->conflictResolver->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn(ConflictResolutionResult::noExistingAccount());

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error: Failed to create email account',
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => 0,
					'message' => 'Failed to create email account',
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'error' => 'IONOS_API_ERROR',
			'statusCode' => 0,
			'message' => 'Failed to create email account',
		]);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}

	public function testCreateWithServiceExceptionWithStatusCode(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$userId = 'testuser';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock IONOS mail service to throw ServiceException with HTTP 409 (Duplicate)
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willThrowException(new ServiceException('Duplicate email account', 409));

		// Mock conflict resolver to return no existing account
		$this->conflictResolver->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn(ConflictResolutionResult::noExistingAccount());

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error: Duplicate email account',
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => 409,
					'message' => 'Duplicate email account',
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'error' => 'IONOS_API_ERROR',
			'statusCode' => 409,
			'message' => 'Duplicate email account',
		]);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}

	public function testCreateWithGenericException(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';

		// Mock IONOS mail service to throw a generic exception
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willThrowException(new \Exception('Generic error'));

		$expectedResponse = \OCA\Mail\Http\JsonResponse::error('Could not create account',
			500,
			[],
			0
		);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}


	public function testCreateNextcloudMailAccount(): void {
		$accountName = 'Test Account';
		$emailAddress = 'test@example.com';

		$imapConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1143,
			security: 'none',
			username: $emailAddress,
			password: 'tmp',
		);

		$smtpConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1587,
			security: 'none',
			username: $emailAddress,
			password: 'tmp',
		);

		$mailConfig = new MailAccountConfig(
			email: $emailAddress,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);

		$expectedResponse = $this->createMock(JSONResponse::class);

		$this->accountsController
			->expects($this->once())
			->method('create')
			->with(
				$accountName,
				$emailAddress,
				'mail.localhost',
				1143,
				'none',
				$emailAddress,
				'tmp',
				'mail.localhost',
				1587,
				'none',
				$emailAddress,
				'tmp',
			)
			->willReturn($expectedResponse);

		$reflection = new ReflectionClass($this->controller);
		$method = $reflection->getMethod('createNextcloudMailAccount');
		$method->setAccessible(true);

		$result = $method->invoke($this->controller, $accountName, $mailConfig);

		$this->assertSame($expectedResponse, $result);
	}

	public function testCreateWithServiceExceptionRetriesWithMatchingEmail(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$emailAddress = 'test@example.com';
		$userId = 'testuser';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock IONOS mail service to throw ServiceException on createEmailAccount
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willThrowException(new ServiceException('Account creation failed', 500));

		// Create MailAccountConfig DTO for existing account
		$imapConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1143,
			security: 'none',
			username: $emailAddress,
			password: 'tmp',
		);

		$smtpConfig = new MailServerConfig(
			host: 'mail.localhost',
			port: 1587,
			security: 'none',
			username: $emailAddress,
			password: 'tmp',
		);

		$mailAccountConfig = new MailAccountConfig(
			email: $emailAddress,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);

		// Mock conflict resolver to return retry result with matching email
		$this->conflictResolver->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn(ConflictResolutionResult::retry($mailAccountConfig));

		// Mock account creation response
		$accountData = ['id' => 1, 'emailAddress' => $emailAddress];
		$accountResponse = $this->createMock(JSONResponse::class);
		$accountResponse->method('getData')->willReturn($accountData);

		$this->accountsController
			->expects($this->once())
			->method('create')
			->with(
				$accountName,
				$emailAddress,
				'mail.localhost',
				1143,
				'none',
				$emailAddress,
				'tmp',
				'mail.localhost',
				1587,
				'none',
				$emailAddress,
				'tmp',
				'password',
				true
			)
			->willReturn($accountResponse);

		$this->logger
			->expects($this->once())
			->method('info')
			->with(
				'Starting IONOS email account creation',
				['emailAddress' => $emailUser, 'accountName' => $accountName]
			);

		$response = $this->controller->create($accountName, $emailUser);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$this->assertEquals($accountData, $response->getData());
	}

	public function testCreateWithServiceExceptionRetriesWithMismatchedEmail(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$expectedEmail = 'test@example.com';
		$existingEmail = 'different@example.com';
		$userId = 'testuser';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock IONOS mail service to throw ServiceException on createEmailAccount
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willThrowException(new ServiceException('Account creation failed', 500));

		// Mock conflict resolver to return email mismatch result
		$this->conflictResolver->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn(ConflictResolutionResult::emailMismatch($expectedEmail, $existingEmail));

		// Should NOT call accountsController->create since emails don't match
		$this->accountsController
			->expects($this->never())
			->method('create');

		$this->logger
			->expects($this->once())
			->method('info')
			->with(
				'Starting IONOS email account creation',
				['emailAddress' => $emailUser, 'accountName' => $accountName]
			);


		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'Email mismatch during retry',
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => 409,
					'message' => 'IONOS account exists but email mismatch. Expected: ' . $expectedEmail . ', Found: ' . $existingEmail,
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'error' => 'IONOS_API_ERROR',
			'statusCode' => 409,
			'message' => 'IONOS account exists but email mismatch. Expected: ' . $expectedEmail . ', Found: ' . $existingEmail,
		]);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}

	public function testCreateWithServiceExceptionRetriesWhenNoExistingAccount(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$userId = 'testuser';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock IONOS mail service to throw ServiceException on createEmailAccount
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willThrowException(new ServiceException('Account creation failed', 500));

		// Mock conflict resolver to return no existing account
		$this->conflictResolver->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn(ConflictResolutionResult::noExistingAccount());

		// Should NOT call accountsController->create since no existing account
		$this->accountsController
			->expects($this->never())
			->method('create');

		$this->logger
			->expects($this->once())
			->method('info')
			->with(
				'Starting IONOS email account creation',
				['emailAddress' => $emailUser, 'accountName' => $accountName]
			);

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error: Account creation failed',
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => 500,
					'message' => 'Account creation failed',
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'error' => 'IONOS_API_ERROR',
			'statusCode' => 500,
			'message' => 'Account creation failed',
		]);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}
}

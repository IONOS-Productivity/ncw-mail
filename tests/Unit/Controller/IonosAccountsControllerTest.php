<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Controller;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Controller\IonosAccountsController;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Exception\IonosServiceException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosAccountsControllerTest extends TestCase {
	private string $appName;

	private IRequest&MockObject $request;

	private IonosAccountCreationService&MockObject $accountCreationService;

	private IUserSession&MockObject $userSession;

	private LoggerInterface|MockObject $logger;

	private IonosAccountsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->appName = 'mail';
		$this->request = $this->createMock(IRequest::class);
		$this->accountCreationService = $this->createMock(IonosAccountCreationService::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new IonosAccountsController(
			$this->appName,
			$this->request,
			$this->accountCreationService,
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
		$userId = 'test-user-123';

		// Setup user session
		$this->setupUserSession($userId);

		// Create a real MailAccount instance and wrap it in Account
		$mailAccount = new MailAccount();
		$mailAccount->setId(1);
		$mailAccount->setUserId($userId);
		$mailAccount->setName($accountName);
		$mailAccount->setEmail($emailAddress);

		$account = new Account($mailAccount);

		// Verify response matches the expected MailJsonResponse::success() format
		$accountResponse = \OCA\Mail\Http\JsonResponse::success($account, 201);

		// Mock account creation service to return a successful account
		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willReturn($account);

		// Verify logging calls
		$this->logger
			->expects($this->exactly(2))
			->method('info')
			->willReturnCallback(function ($message, $context) use ($emailUser, $accountName, $emailAddress, $userId) {
				static $callCount = 0;
				$callCount++;

				if ($callCount === 1) {
					$this->assertEquals('Starting IONOS email account creation from web', $message);
					$this->assertEquals([
						'userId' => $userId,
						'emailAddress' => $emailUser,
						'accountName' => $accountName,
					], $context);
				} elseif ($callCount === 2) {
					$this->assertEquals('Account creation completed successfully', $message);
					$this->assertEquals([
						'emailAddress' => $emailAddress,
						'accountName' => $accountName,
						'accountId' => 1,
						'userId' => $userId,
					], $context);
				}
			});

		$response = $this->controller->create($accountName, $emailUser);

		$this->assertEquals($accountResponse, $response);
	}

	public function testCreateWithServiceException(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$userId = 'test-user-123';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock account creation service to throw ServiceException
		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willThrowException(new ServiceException('Failed to create email account'));

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error during account creation: Failed to create email account',
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
		$userId = 'test-user-123';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock account creation service to throw ServiceException with status code
		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willThrowException(new ServiceException('Duplicate email account', 409));

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error during account creation: Duplicate email account',
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

	public function testCreateWithIonosServiceExceptionWithAdditionalData(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$userId = 'test-user-123';

		// Setup user session
		$this->setupUserSession($userId);

		// Create IonosServiceException with additional data
		$additionalData = [
			'errorCode' => 'DUPLICATE_EMAIL',
			'existingEmail' => 'test@example.com',
			'suggestedAlternative' => 'test2@example.com',
		];

		// Mock account creation service to throw IonosServiceException with additional data
		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willThrowException(new IonosServiceException('Email already exists', 409, null, $additionalData));

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error during account creation: Email already exists',
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => 409,
					'message' => 'Email already exists',
					'errorCode' => 'DUPLICATE_EMAIL',
					'existingEmail' => 'test@example.com',
					'suggestedAlternative' => 'test2@example.com',
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'error' => 'IONOS_API_ERROR',
			'statusCode' => 409,
			'message' => 'Email already exists',
			'errorCode' => 'DUPLICATE_EMAIL',
			'existingEmail' => 'test@example.com',
			'suggestedAlternative' => 'test2@example.com',
		]);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}

	public function testCreateWithGenericException(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$userId = 'test-user-123';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock account creation service to throw a generic exception
		$exception = new \Exception('Generic error');
		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willThrowException($exception);

		// Verify error logging for unexpected exceptions
		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'Unexpected error during account creation: Generic error',
				[
					'exception' => $exception,
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::error('Could not create account',
			500,
			[],
			0
		);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}

	public function testCreateWithNoUserSession(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';

		// Mock user session to return null (no user logged in)
		$this->userSession->method('getUser')->willReturn(null);

		// Should catch the ServiceException thrown by getUserIdOrFail
		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error during account creation: No user session found during account creation',
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => 401,
					'message' => 'No user session found during account creation',
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'error' => 'IONOS_API_ERROR',
			'statusCode' => 401,
			'message' => 'No user session found during account creation',
		]);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}
}

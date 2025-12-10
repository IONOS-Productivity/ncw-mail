<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Controller;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Controller\IonosAccountsController;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\IonosAccountConflictResolver;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use OCP\IUser;
use OCP\IUserSession;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosAccountsControllerTest extends TestCase {
	private string $appName;

	private IRequest&MockObject $request;

	private IonosAccountCreationService&MockObject $accountCreationService;

	private IonosMailService&MockObject $ionosMailService;

	private IonosAccountConflictResolver&MockObject $conflictResolver;

	private IUserSession&MockObject $userSession;

	private LoggerInterface|MockObject $logger;

	private IonosAccountsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->appName = 'mail';
		$this->request = $this->createMock(IRequest::class);
		$this->accountCreationService = $this->createMock(IonosAccountCreationService::class);
		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->conflictResolver = $this->createMock(IonosAccountConflictResolver::class);
		$this->userSession = $this->createMock(IUserSession::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new IonosAccountsController(
			$this->appName,
			$this->request,
			$this->accountCreationService,
			$this->ionosMailService,
			$this->conflictResolver,
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
		$userId = 'testuser';

		// Setup user session
		$this->setupUserSession($userId);

		// Create a real MailAccount instance
		$mailAccount = new MailAccount();
		$mailAccount->setId(1);
		$mailAccount->setUserId($userId);
		$mailAccount->setName($accountName);
		$mailAccount->setEmail($emailAddress);

		// Mock account creation service to return a successful account
		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willReturn($mailAccount);

		$response = $this->controller->create($accountName, $emailUser);

		$this->assertInstanceOf(JSONResponse::class, $response);
		$data = $response->getData();
		$this->assertEquals(1, $data['id']);
		$this->assertEquals($accountName, $data['accountName']);
		$this->assertEquals($emailAddress, $data['emailAddress']);
	}

	public function testCreateWithServiceException(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$userId = 'testuser';

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
		$userId = 'testuser';

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

	public function testCreateWithGenericException(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';
		$userId = 'testuser';

		// Setup user session
		$this->setupUserSession($userId);

		// Mock account creation service to throw a generic exception
		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willThrowException(new \Exception('Generic error'));

		$expectedResponse = \OCA\Mail\Http\JsonResponse::error('Could not create account',
			500,
			[],
			0
		);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}
}

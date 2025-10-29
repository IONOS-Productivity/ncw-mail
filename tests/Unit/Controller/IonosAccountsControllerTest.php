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
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\Dto\MailServerConfig;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class IonosAccountsControllerTest extends TestCase {
	private string $appName;

	private IRequest&MockObject $request;

	private IonosMailService&MockObject $ionosMailService;

	private AccountsController&MockObject $accountsController;

	private LoggerInterface|MockObject $logger;

	private IonosAccountsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->appName = 'mail';
		$this->request = $this->createMock(IRequest::class);
		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->accountsController = $this->createMock(AccountsController::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new IonosAccountsController(
			$this->appName,
			$this->request,
			$this->ionosMailService,
			$this->accountsController,
			$this->logger,
		);
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

		// Mock IONOS mail service to throw ServiceException
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willThrowException(new ServiceException('Failed to create email account'));

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error: Failed to create email account',
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => 0,
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'error' => 'IONOS_API_ERROR',
			'statusCode' => 0,
		]);
		$response = $this->controller->create($accountName, $emailUser);

		self::assertEquals($expectedResponse, $response);
	}

	public function testCreateWithServiceExceptionWithStatusCode(): void {
		$accountName = 'Test Account';
		$emailUser = 'test';

		// Mock IONOS mail service to throw ServiceException with HTTP 409 (Duplicate)
		$this->ionosMailService->method('createEmailAccount')
			->with($emailUser)
			->willThrowException(new ServiceException('Duplicate email account', 409));

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error: Duplicate email account',
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => 409,
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'error' => 'IONOS_API_ERROR',
			'statusCode' => 409,
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
}

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
use OCP\AppFramework\Http\JSONResponse;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use ReflectionClass;

class IonosAccountsControllerTest extends TestCase {
	private string $appName;

	private IRequest&MockObject $request;

	private AccountsController&MockObject $accountsController;

	private LoggerInterface|MockObject $logger;

	private IonosAccountsController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->appName = 'mail';
		$this->request = $this->createMock(IRequest::class);
		$this->accountsController = $this->createMock(AccountsController::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new IonosAccountsController(
			$this->appName,
			$this->request,
			$this->accountsController,
			$this->logger,
		);
	}

	public function testCreateWithMissingFields(): void {
		// Test with empty account name
		$response = $this->controller->create('', 'test@example.com');
		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('All fields are required', $data['message']);
		$this->assertEquals('IONOS_API_ERROR', $data['error']);

		// Test with empty email address
		$response = $this->controller->create('Test Account', '');
		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('All fields are required', $data['message']);
		$this->assertEquals('IONOS_API_ERROR', $data['error']);
	}

	public function testCreateWithInvalidEmailFormat(): void {
		$response = $this->controller->create('Test Account', 'invalid-email');
		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertFalse($data['success']);
		$this->assertEquals('Invalid email address format', $data['message']);
		$this->assertEquals('IONOS_API_ERROR', $data['error']);
	}

	public function testCreateSuccess(): void {
		$accountName = 'Test Account';
		$emailAddress = 'test@example.com';

		// Mock successful IONOS API response - using the actual mock data from controller
		$this->mockCreateEmailAccount([
			'success' => true,
			'message' => 'Email account created successfully via IONOS (mock)',
			'mailConfig' => [
				'imap' => [
					'host' => 'mail.localhost',
					'password' => 'tmp',
					'port' => 1143,
					'security' => 'none',
					'username' => $emailAddress, // Updated to use actual email address
				],
				'smtp' => [
					'host' => 'mail.localhost',
					'password' => 'tmp',
					'port' => 1587,
					'security' => 'none',
					'username' => $emailAddress, // Updated to use actual email address
				]
			]
		]);

		// Mock account creation response
		$accountData = ['id' => 1, 'emailAddress' => $emailAddress];
		$accountResponse = $this->createMock(JSONResponse::class);
		$accountResponse->method('getData')->willReturn($accountData);

		$this->accountsController
			->method('create')
			->willReturn($accountResponse);

		$response = $this->controller->create($accountName, $emailAddress);

		// The controller now directly returns the AccountsController response
		$this->assertSame($accountResponse, $response);
	}

	public function testCreateWithServiceException(): void {
		$accountName = 'Test Account';
		$emailAddress = 'test@example.com';

		// Mock failed IONOS API response by throwing ServiceException
		$this->mockCreateEmailAccount(null, new ServiceException('Failed to create email account'));

		$this->logger
			->expects($this->once())
			->method('error')
			->with(
				'IONOS service error: Failed to create email account',
				[
					'emailAddress' => $emailAddress,
					'error' => 'IONOS_API_ERROR',
				]
			);

		$expectedResponse = \OCA\Mail\Http\JsonResponse::fail([
			'emailAddress' => 'test@example.com',
			'error' => 'IONOS_API_ERROR',
		]);
		$response = $this->controller->create($accountName, $emailAddress);

		self::assertEquals($expectedResponse, $response);
	}

	public function testCreateWithGenericException(): void {
		$accountName = 'Test Account';
		$emailAddress = 'test@example.com';

		// Mock IONOS API to throw a generic exception
		$this->mockCreateEmailAccount(null, new \Exception('Generic error'));

		$expectedResponse = \OCA\Mail\Http\JsonResponse::error('Could not create account',
			500,
			[],
			0
		);
		$response = $this->controller->create($accountName, $emailAddress);

		self::assertEquals($expectedResponse, $response);
	}

	public function testCreateIonosEmailAccountSuccess(): void {
		$accountName = 'Test Account';
		$emailAddress = 'test@example.com';

		// Mock successful IONOS API response - using actual mock data with correct email address
		$mockResponse = [
			'success' => true,
			'mailConfig' => [
				'imap' => [
					'host' => 'mail.localhost',
					'port' => 1143,
					'security' => 'none',
					'username' => $emailAddress, // Updated to use actual email address
				],
				'smtp' => [
					'host' => 'mail.localhost',
					'port' => 1587,
					'security' => 'none',
					'username' => $emailAddress, // Updated to use actual email address
				]
			]
		];

		$this->mockCreateEmailAccount($mockResponse);

		$reflection = new ReflectionClass($this->controller);
		$method = $reflection->getMethod('createIonosEmailAccount');
		$method->setAccessible(true);

		$result = $method->invoke($this->controller, $accountName, $emailAddress);
		$this->assertEquals($mockResponse['mailConfig'], $result);
	}

	public function testCreateIonosEmailAccountFailure(): void {
		$accountName = 'Test Account';
		$emailAddress = 'test@example.com';

		// Mock failed IONOS API response
		$this->mockCreateEmailAccount(['success' => false]);

		$this->logger
			->expects($this->once())
			->method('error');

		$reflection = new ReflectionClass($this->controller);
		$method = $reflection->getMethod('createIonosEmailAccount');
		$method->setAccessible(true);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Failed to create email account');

		$method->invoke($this->controller, $accountName, $emailAddress);
	}

	public function testCreateIonosEmailAccountMissingMailConfig(): void {
		$accountName = 'Test Account';
		$emailAddress = 'test@example.com';

		// Mock IONOS API response without mailConfig
		$this->mockCreateEmailAccount(['success' => true]);

		$this->logger
			->expects($this->once())
			->method('error');

		$reflection = new ReflectionClass($this->controller);
		$method = $reflection->getMethod('createIonosEmailAccount');
		$method->setAccessible(true);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Invalid IONOS API response: missing mail configuration');

		$method->invoke($this->controller, $accountName, $emailAddress);
	}

	public function testCreateNextcloudMailAccount(): void {
		$accountName = 'Test Account';
		$emailAddress = 'test@example.com';
		$mailConfig = [
			'imap' => [
				'host' => 'mail.localhost',
				'port' => 1143,
				'security' => 'none',
				'username' => $emailAddress, // Updated to use actual email address
				'password' => 'tmp'
			],
			'smtp' => [
				'host' => 'mail.localhost',
				'port' => 1587,
				'security' => 'none',
				'username' => $emailAddress, // Updated to use actual email address
				'password' => 'tmp'
			]
		];

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
				$emailAddress, // Updated to use actual email address
				'tmp',
				'mail.localhost',
				1587,
				'none',
				$emailAddress, // Updated to use actual email address
				'tmp',
			)
			->willReturn($expectedResponse);

		$reflection = new ReflectionClass($this->controller);
		$method = $reflection->getMethod('createNextcloudMailAccount');
		$method->setAccessible(true);

		$result = $method->invoke($this->controller, $accountName, $emailAddress, $mailConfig);

		$this->assertSame($expectedResponse, $result);
	}


	public function testCallIonosCreateEmailAPIWithInvalidDomain(): void {
		$accountName = 'Test Account';
		$emailAddress = 'invalid-email-without-at';

		$reflection = new ReflectionClass($this->controller);
		$method = $reflection->getMethod('createIonosEmailAccount');
		$method->setAccessible(true);

		$this->expectException(ServiceException::class);
		$this->expectExceptionMessage('Invalid email address: unable to extract domain');

		$method->invoke($this->controller, $accountName, $emailAddress);
	}

	/**
	 * Helper method to mock the createIonosEmailAccount method
	 */
	private function mockCreateEmailAccount($returnValue, $exception = null): void {
		// Create a partial mock to override the protected method
		$controllerMock = $this->getMockBuilder(IonosAccountsController::class)
			->setConstructorArgs([
				$this->appName,
				$this->request,
				$this->accountsController,
				$this->logger
			])
			->onlyMethods(['createEmailAccount'])
			->getMock();

		if ($exception) {
			$controllerMock->method('createEmailAccount')->willThrowException($exception);
		} else {
			$controllerMock->method('createEmailAccount')->willReturn($returnValue);
		}

		// Replace the controller instance with the mock
		$this->controller = $controllerMock;
	}
}

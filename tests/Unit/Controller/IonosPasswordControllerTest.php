<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Controller;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Controller\IonosPasswordController;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\IRequest;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosPasswordControllerTest extends TestCase {
	private string $appName;

	private IRequest&MockObject $request;

	private IonosMailService&MockObject $ionosMailService;

	private LoggerInterface&MockObject $logger;

	private IonosPasswordController $controller;

	protected function setUp(): void {
		parent::setUp();

		$this->appName = 'mail';
		$this->request = $this->createMock(IRequest::class);
		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->controller = new IonosPasswordController(
			$this->appName,
			$this->request,
			$this->ionosMailService,
			$this->logger,
		);
	}

	public function testGenerateWithMissingAccountId(): void {
		$this->logger->expects($this->once())
			->method('error')
			->with('Account ID is required for app password generation');

		$response = $this->controller->generate(null);

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('Account ID is required', $data['data']['error']);
	}

	public function testGenerateSuccess(): void {
		$accountId = 123;
		$generatedPassword = 'test-app-password-12345';

		$this->ionosMailService->expects($this->once())
			->method('generateUserAppPassword')
			->willReturn($generatedPassword);

		$loggerCalls = [];
		$this->logger->expects($this->exactly(2))
			->method('info')
			->willReturnCallback(function ($message, $context) use (&$loggerCalls, $accountId) {
				$loggerCalls[] = [$message, $context];
			});

		$response = $this->controller->generate($accountId);

		// Verify logger calls
		$this->assertCount(2, $loggerCalls);
		$this->assertEquals('Generating IONOS app password', $loggerCalls[0][0]);
		$this->assertEquals(['accountId' => $accountId], $loggerCalls[0][1]);
		$this->assertEquals('IONOS app password generated successfully', $loggerCalls[1][0]);
		$this->assertEquals(['accountId' => $accountId], $loggerCalls[1][1]);

		$this->assertEquals(200, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('success', $data['status']);
		$this->assertEquals($generatedPassword, $data['data']['password']);
	}

	public function testGenerateServiceException(): void {
		$accountId = 123;
		$statusCode = 404;
		$errorMessage = 'Account not found';

		$exception = new ServiceException($errorMessage, $statusCode);

		$this->ionosMailService->expects($this->once())
			->method('generateUserAppPassword')
			->willThrowException($exception);

		$this->logger->expects($this->once())
			->method('info')
			->with('Generating IONOS app password', ['accountId' => $accountId]);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'IONOS service error: ' . $errorMessage,
				[
					'error' => 'IONOS_API_ERROR',
					'statusCode' => $statusCode,
				]
			);

		$response = $this->controller->generate($accountId);

		$this->assertEquals(400, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('fail', $data['status']);
		$this->assertEquals('IONOS_API_ERROR', $data['data']['error']);
		$this->assertEquals($statusCode, $data['data']['statusCode']);
	}

	public function testGenerateUnexpectedException(): void {
		$accountId = 123;
		$exception = new \Exception('Unexpected error');

		$this->ionosMailService->expects($this->once())
			->method('generateUserAppPassword')
			->willThrowException($exception);

		$this->logger->expects($this->once())
			->method('info')
			->with('Generating IONOS app password', ['accountId' => $accountId]);

		$this->logger->expects($this->once())
			->method('error')
			->with(
				'Unexpected error generating app password',
				[
					'exception' => $exception,
					'accountId' => $accountId,
				]
			);

		$response = $this->controller->generate($accountId);

		$this->assertEquals(500, $response->getStatus());
		$data = $response->getData();
		$this->assertEquals('error', $data['status']);
		$this->assertEquals('Could not generate app password', $data['message']);
	}
}

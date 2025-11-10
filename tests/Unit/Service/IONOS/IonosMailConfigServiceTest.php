<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Service\IONOS\IonosMailConfigService;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\IConfig;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosMailConfigServiceTest extends TestCase {
	private IConfig&MockObject $config;
	private IonosMailService&MockObject $ionosMailService;
	private LoggerInterface&MockObject $logger;
	private IonosMailConfigService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->config = $this->createMock(IConfig::class);
		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new IonosMailConfigService(
			$this->config,
			$this->ionosMailService,
			$this->logger,
		);
	}

	public function testIsMailConfigAvailableReturnsFalseWhenFeatureDisabled(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('no');

		$this->ionosMailService->expects($this->never())
			->method('mailAccountExistsForCurrentUser');

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableReturnsTrueWhenUserHasNoAccount(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('yes');

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(false);

		$this->logger->expects($this->never())
			->method('debug');

		$result = $this->service->isMailConfigAvailable();

		$this->assertTrue($result);
	}

	public function testIsMailConfigAvailableReturnsFalseWhenUserHasAccount(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('yes');

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willReturn(true);

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS mail config not available - user already has an account');

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}

	public function testIsMailConfigAvailableReturnsFalseOnException(): void {
		$this->config->expects($this->once())
			->method('getAppValue')
			->with('mail', 'ionos-mailconfig-enabled', 'no')
			->willReturn('yes');

		$exception = new \Exception('Test exception');

		$this->ionosMailService->expects($this->once())
			->method('mailAccountExistsForCurrentUser')
			->willThrowException($exception);

		$this->logger->expects($this->once())
			->method('error')
			->with('Error checking IONOS mail config availability', $this->callback(function ($context) use ($exception) {
				return isset($context['exception']) && $context['exception'] === $exception;
			}));

		$result = $this->service->isMailConfigAvailable();

		$this->assertFalse($result);
	}
}

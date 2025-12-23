<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2016-2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-FileCopyrightText: 2015-2016 ownCloud, Inc.
 * SPDX-License-Identifier: AGPL-3.0-only
 */
namespace OCA\Mail\Tests\Unit\AppInfo;

use OCA\Mail\AppInfo\Application;
use OCA\Mail\Provider\MailAccountProvider\Implementations\IonosProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCP\AppFramework\Bootstrap\IBootContext;
use OCP\IServerContainer;
use Psr\Log\LoggerInterface;
use Test\TestCase;

class ApplicationTest extends TestCase {
	private Application $application;

	protected function setUp(): void {
		parent::setUp();
		$this->application = new Application();
	}

	public function testConstructor(): void {
		// Not really a test – it's just about code coverage
		new Application();

		$this->addToAssertionCount(1);
	}

	public function testBootRegistersIonosProvider(): void {
		$bootContext = $this->createMock(IBootContext::class);
		$serverContainer = $this->createMock(IServerContainer::class);
		$providerRegistry = $this->createMock(ProviderRegistryService::class);
		$ionosProvider = $this->createMock(IonosProvider::class);

		$bootContext->expects($this->once())
			->method('getServerContainer')
			->willReturn($serverContainer);

		$serverContainer->expects($this->exactly(2))
			->method('get')
			->willReturnMap([
				[ProviderRegistryService::class, $providerRegistry],
				[IonosProvider::class, $ionosProvider],
			]);

		$providerRegistry->expects($this->once())
			->method('registerProvider')
			->with($ionosProvider);

		$this->application->boot($bootContext);
	}

	public function testBootHandlesExceptionGracefully(): void {
		$bootContext = $this->createMock(IBootContext::class);
		$serverContainer = $this->createMock(IServerContainer::class);
		$logger = $this->createMock(LoggerInterface::class);

		$bootContext->expects($this->once())
			->method('getServerContainer')
			->willReturn($serverContainer);

		$exception = new \Exception('Provider registration failed');

		$serverContainer->expects($this->exactly(2))
			->method('get')
			->willReturnCallback(function ($class) use ($exception, $logger) {
				if ($class === ProviderRegistryService::class) {
					throw $exception;
				}
				if ($class === LoggerInterface::class) {
					return $logger;
				}
				throw new \Exception('Unexpected class: ' . $class);
			});

		$logger->expects($this->once())
			->method('error')
			->with('Failed to register mail account providers', [
				'exception' => $exception,
			]);

		// Should not throw - exception should be caught and logged
		$this->application->boot($bootContext);
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Command;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Command\ProviderList;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\IProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProviderListTest extends TestCase {
	private ProviderRegistryService&MockObject $providerRegistry;
	private IUserManager&MockObject $userManager;
	private ProviderList $command;

	protected function setUp(): void {
		parent::setUp();

		$this->providerRegistry = $this->createMock(ProviderRegistryService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->command = new ProviderList($this->providerRegistry, $this->userManager);
	}

	public function testName(): void {
		$this->assertSame('mail:provider:list', $this->command->getName());
	}

	public function testDescription(): void {
		$this->assertSame('List all registered mail account providers and their capabilities', $this->command->getDescription());
	}

	public function testExecuteWithNoProviders(): void {
		$input = $this->createMock(InputInterface::class);
		$output = $this->createMock(OutputInterface::class);

		$this->providerRegistry->method('getAllProviders')
			->willReturn([]);

		$output->expects($this->once())
			->method('writeln')
			->with('<info>No mail account providers are registered.</info>');

		$result = $this->command->run($input, $output);
		$this->assertSame(0, $result);
	}

	public function testExecuteWithProviders(): void {
		$input = $this->createMock(InputInterface::class);
		$output = $this->createMock(OutputInterface::class);

		// Create mock provider
		$capabilities = $this->createMock(IProviderCapabilities::class);
		$capabilities->method('allowsMultipleAccounts')->willReturn(false);
		$capabilities->method('supportsAppPasswords')->willReturn(true);
		$capabilities->method('supportsPasswordReset')->willReturn(true);
		$capabilities->method('getEmailDomain')->willReturn('example.com');
		$capabilities->method('getConfigSchema')->willReturn([]);
		$capabilities->method('getCreationParameterSchema')->willReturn([]);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getName')->willReturn('IONOS Nextcloud Workspace Mail');
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('getCapabilities')->willReturn($capabilities);

		$this->providerRegistry->method('getAllProviders')
			->willReturn([$provider]);

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(0, $result);
	}
}

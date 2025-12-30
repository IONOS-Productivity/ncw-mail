<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Command;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Command\ProviderGenerateAppPassword;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\IProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProviderGenerateAppPasswordTest extends TestCase {
	private ProviderRegistryService&MockObject $providerRegistry;
	private IUserManager&MockObject $userManager;
	private ProviderGenerateAppPassword $command;

	protected function setUp(): void {
		parent::setUp();

		$this->providerRegistry = $this->createMock(ProviderRegistryService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->command = new ProviderGenerateAppPassword($this->providerRegistry, $this->userManager);
	}

	public function testName(): void {
		$this->assertSame('mail:provider:generate-app-password', $this->command->getName());
	}

	public function testDescription(): void {
		$this->assertSame('Generate a new app password for a provider-managed mail account', $this->command->getDescription());
	}

	public function testExecuteWithInvalidProvider(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'nonexistent'],
				['user-id', 'testuser'],
			]);

		$output = $this->createMock(OutputInterface::class);

		$this->providerRegistry->method('getProvider')
			->with('nonexistent')
			->willReturn(null);

		$this->providerRegistry->method('getAllProviders')
			->willReturn([]);

		$foundErrorMessage = false;
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->willReturnCallback(function ($message) use (&$foundErrorMessage) {
				if (is_string($message) && strpos($message, 'not found') !== false) {
					$foundErrorMessage = true;
				}
			});

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
		$this->assertTrue($foundErrorMessage, 'Expected error message containing "not found" was not found');
	}

	public function testExecuteWithInvalidUser(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'nonexistentuser'],
			]);

		$output = $this->createMock(OutputInterface::class);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('nonexistentuser')
			->willReturn(false);

		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with($this->stringContains('does not exist'));

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
	}

	public function testExecuteWithProviderNotSupportingAppPasswords(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);

		$output = $this->createMock(OutputInterface::class);

		$capabilities = $this->createMock(IProviderCapabilities::class);
		$capabilities->method('supportsAppPasswords')->willReturn(false);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getCapabilities')->willReturn($capabilities);

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		$output->expects($this->atLeastOnce())
			->method('writeln')
			->with($this->stringContains('does not support app password'));

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
	}

	public function testExecuteWithDisabledProvider(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);

		$output = $this->createMock(OutputInterface::class);

		$capabilities = $this->createMock(IProviderCapabilities::class);
		$capabilities->method('supportsAppPasswords')->willReturn(true);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getCapabilities')->willReturn($capabilities);
		$provider->method('isEnabled')->willReturn(false);

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		$foundErrorMessage = false;
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->willReturnCallback(function ($message) use (&$foundErrorMessage) {
				if (is_string($message) && strpos($message, 'not enabled') !== false) {
					$foundErrorMessage = true;
				}
			});

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
		$this->assertTrue($foundErrorMessage, 'Expected error message containing "not enabled" was not found');
	}

	public function testExecuteWithNoProvisionedAccount(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);

		$output = $this->createMock(OutputInterface::class);

		$capabilities = $this->createMock(IProviderCapabilities::class);
		$capabilities->method('supportsAppPasswords')->willReturn(true);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getCapabilities')->willReturn($capabilities);
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('getProvisionedEmail')->with('testuser')->willReturn(null);

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		$foundErrorMessage = false;
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->willReturnCallback(function ($message) use (&$foundErrorMessage) {
				if (is_string($message) && strpos($message, 'does not have a provisioned account') !== false) {
					$foundErrorMessage = true;
				}
			});

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
		$this->assertTrue($foundErrorMessage, 'Expected error message containing "does not have a provisioned account" was not found');
	}

	public function testExecuteSuccessfully(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);

		$output = $this->createMock(OutputInterface::class);

		$capabilities = $this->createMock(IProviderCapabilities::class);
		$capabilities->method('supportsAppPasswords')->willReturn(true);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getCapabilities')->willReturn($capabilities);
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('getProvisionedEmail')->with('testuser')->willReturn('test@example.com');
		$provider->method('generateAppPassword')->with('testuser')->willReturn('new-app-password-123');

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(0, $result);
	}
}

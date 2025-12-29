<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Command;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Command\ProviderStatus;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCA\Mail\Service\AccountService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProviderStatusTest extends TestCase {
	private ProviderRegistryService&MockObject $providerRegistry;
	private IUserManager&MockObject $userManager;
	private AccountService&MockObject $accountService;
	private ProviderStatus $command;

	protected function setUp(): void {
		parent::setUp();

		$this->providerRegistry = $this->createMock(ProviderRegistryService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->command = new ProviderStatus($this->providerRegistry, $this->userManager, $this->accountService);
	}

	public function testName(): void {
		$this->assertSame('mail:provider:status', $this->command->getName());
	}

	public function testDescription(): void {
		$this->assertSame('Check the status and availability of a mail account provider (use -v for detailed information)', $this->command->getDescription());
	}

	public function testExecuteWithInvalidProvider(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'nonexistent'],
				['user-id', null],
			]);
		$input->method('getOption')
			->willReturnMap([
				['verbose', false],
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

	public function testExecuteWithValidProvider(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', null],
			]);
		$input->method('getOption')
			->willReturnMap([
				['verbose', false],
			]);

		$output = $this->createMock(OutputInterface::class);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getName')->willReturn('IONOS Nextcloud Workspace Mail');
		$provider->method('isEnabled')->willReturn(true);

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(0, $result);
	}

	public function testExecuteWithUserIdButUserDoesNotExist(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'nonexistentuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['verbose', false],
			]);

		$output = $this->createMock(OutputInterface::class);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getName')->willReturn('IONOS Nextcloud Workspace Mail');
		$provider->method('isEnabled')->willReturn(true);

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('nonexistentuser')
			->willReturn(false);

		$foundErrorMessage = false;
		$output->expects($this->atLeastOnce())
			->method('writeln')
			->willReturnCallback(function ($message) use (&$foundErrorMessage) {
				if (is_string($message) && strpos($message, 'does not exist') !== false) {
					$foundErrorMessage = true;
				}
			});

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
		$this->assertTrue($foundErrorMessage, 'Expected error message containing "does not exist" was not found');
	}

	public function testExecuteWithUserIdAndProviderAvailable(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['verbose', false],
			]);

		$output = $this->createMock(OutputInterface::class);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getName')->willReturn('IONOS Nextcloud Workspace Mail');
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('isAvailableForUser')->with('testuser')->willReturn(true);
		$provider->method('getExistingAccountEmail')->with('testuser')->willReturn(null);
		$provider->method('getProvisionedEmail')->with('testuser')->willReturn(null);

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		// No existing account, so findByUserIdAndAddress won't be called

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(0, $result);
	}

	public function testExecuteWithUserIdAndExistingAccount(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['verbose', false],
				['output', 'plain'],
			]);

		$output = $this->createMock(OutputInterface::class);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('getName')->willReturn('IONOS Nextcloud Workspace Mail');
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('isAvailableForUser')->with('testuser')->willReturn(false);
		$provider->method('getExistingAccountEmail')->with('testuser')->willReturn('testuser@example.com');
		$provider->method('getProvisionedEmail')->with('testuser')->willReturn('testuser@example.com');

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		// Mock existing account
		$mailAccount = new MailAccount();
		$mailAccount->setId(42);
		$mailAccount->setEmail('testuser@example.com');
		$account = new Account($mailAccount);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with('testuser', 'testuser@example.com')
			->willReturn([$account]);

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(0, $result);
	}
}

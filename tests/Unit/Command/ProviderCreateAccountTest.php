<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Command;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Command\ProviderCreateAccount;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\IProviderCapabilities;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class ProviderCreateAccountTest extends TestCase {
	private ProviderRegistryService&MockObject $providerRegistry;
	private IUserManager&MockObject $userManager;
	private ProviderCreateAccount $command;

	protected function setUp(): void {
		parent::setUp();

		$this->providerRegistry = $this->createMock(ProviderRegistryService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->command = new ProviderCreateAccount($this->providerRegistry, $this->userManager);
	}

	public function testName(): void {
		$this->assertSame('mail:provider:create-account', $this->command->getName());
	}

	public function testDescription(): void {
		$this->assertSame('Create a mail account via an external provider', $this->command->getDescription());
	}

	public function testExecuteWithInvalidProvider(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'nonexistent'],
				['user-id', 'testuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['param', []],
			]);

		$output = $this->createMock(OutputInterface::class);

		$this->providerRegistry->method('getProvider')
			->with('nonexistent')
			->willReturn(null);

		$this->providerRegistry->method('getAllProviders')
			->willReturn([]);

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
	}

	public function testExecuteWithInvalidUser(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'nonexistentuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['param', []],
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

	public function testExecuteWithDisabledProvider(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['param', []],
			]);

		$output = $this->createMock(OutputInterface::class);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('isEnabled')->willReturn(false);

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
	}

	public function testExecuteWithProviderNotAvailableForUser(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['param', []],
			]);

		$output = $this->createMock(OutputInterface::class);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('isAvailableForUser')->with('testuser')->willReturn(false);
		$provider->method('getExistingAccountEmail')->with('testuser')->willReturn('test@example.com');

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
	}

	public function testExecuteWithMissingRequiredParameters(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['param', []],
			]);

		$output = $this->createMock(OutputInterface::class);

		$capabilities = $this->createMock(IProviderCapabilities::class);
		$capabilities->method('getCreationParameterSchema')
			->willReturn([
				'emailUser' => ['required' => true, 'type' => 'string', 'description' => 'Email user'],
				'accountName' => ['required' => true, 'type' => 'string', 'description' => 'Account name'],
			]);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('isAvailableForUser')->with('testuser')->willReturn(true);
		$provider->method('getCapabilities')->willReturn($capabilities);

		$this->providerRegistry->method('getProvider')
			->with('ionos')
			->willReturn($provider);

		$this->userManager->method('userExists')
			->with('testuser')
			->willReturn(true);

		$output->expects($this->atLeastOnce())
			->method('writeln');

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
	}

	public function testExecuteSuccessfully(): void {
		$input = $this->createMock(InputInterface::class);
		$input->method('getArgument')
			->willReturnMap([
				['provider-id', 'ionos'],
				['user-id', 'testuser'],
			]);
		$input->method('getOption')
			->willReturnMap([
				['param', ['emailUser=john', 'accountName=John Doe']],
			]);

		$output = $this->createMock(OutputInterface::class);

		$capabilities = $this->createMock(IProviderCapabilities::class);
		$capabilities->method('getCreationParameterSchema')
			->willReturn([
				'emailUser' => ['required' => true, 'type' => 'string', 'description' => 'Email user'],
				'accountName' => ['required' => true, 'type' => 'string', 'description' => 'Account name'],
			]);

		$mailAccount = new MailAccount();
		$mailAccount->setId(1);
		$mailAccount->setEmail('john@example.com');
		$mailAccount->setName('John Doe');
		$mailAccount->setInboundHost('imap.example.com');
		$mailAccount->setInboundPort(993);
		$mailAccount->setOutboundHost('smtp.example.com');
		$mailAccount->setOutboundPort(587);

		$account = new Account($mailAccount);

		$provider = $this->createMock(IMailAccountProvider::class);
		$provider->method('getId')->willReturn('ionos');
		$provider->method('isEnabled')->willReturn(true);
		$provider->method('isAvailableForUser')->with('testuser')->willReturn(true);
		$provider->method('getCapabilities')->willReturn($capabilities);
		$provider->method('createAccount')
			->with('testuser', ['emailUser' => 'john', 'accountName' => 'John Doe'])
			->willReturn($account);

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

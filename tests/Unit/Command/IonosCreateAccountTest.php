<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Command;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Command\IonosCreateAccount;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosConfigService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

class IonosCreateAccountTest extends TestCase {
	private const TEST_USER_ID = 'testuser';
	private const TEST_ACCOUNT_NAME = 'Test Account';
	private const TEST_EMAIL_USER = 'test@example.com';
	private const TEST_MAIL_DOMAIN = 'example.com';

	private IonosAccountCreationService&MockObject $accountCreationService;
	private IUserManager&MockObject $userManager;
	private IonosConfigService&MockObject $configService;
	private LoggerInterface&MockObject $logger;
	private IonosCreateAccount $command;

	protected function setUp(): void {
		parent::setUp();

		$this->accountCreationService = $this->createMock(IonosAccountCreationService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->configService = $this->createMock(IonosConfigService::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->command = new IonosCreateAccount(
			$this->accountCreationService,
			$this->userManager,
			$this->configService,
			$this->logger
		);
	}

	private function createInputMock(string $userId, string $name, string $emailUser): InputInterface&MockObject {
		$input = $this->createMock(InputInterface::class);
		$arguments = [
			'user-id' => $userId,
			'email-user' => $emailUser,
		];
		$options = [
			'name' => $name,
			'output' => null,
		];
		$input->method('getArgument')
			->willReturnCallback(fn ($arg) => $arguments[$arg] ?? null);
		$input->method('getOption')
			->willReturnCallback(fn ($opt) => $options[$opt] ?? null);
		return $input;
	}

	private function createOutputMock(): OutputInterface&MockObject {
		return $this->createMock(OutputInterface::class);
	}


	public function testName(): void {
		$this->assertSame('mail:ionos:create', $this->command->getName());
	}

	public function testDescription(): void {
		$this->assertSame('Creates IONOS mail account and configure it in Nextcloud', $this->command->getDescription());
	}

	public function testArguments(): void {
		$definition = $this->command->getDefinition();

		// Check arguments
		$arguments = $definition->getArguments();
		$argumentNames = array_map(fn ($arg) => $arg->getName(), $arguments);

		$this->assertCount(2, $arguments);
		$this->assertContains('user-id', $argumentNames);
		$this->assertContains('email-user', $argumentNames);

		foreach ($arguments as $arg) {
			$this->assertTrue($arg->isRequired());
		}

		// Check options
		$options = $definition->getOptions();
		$optionNames = array_map(fn ($opt) => $opt->getName(), $options);

		$this->assertContains('name', $optionNames);
		$this->assertContains('output', $optionNames);

		// Verify 'name' option is required
		$nameOption = $definition->getOption('name');
		$this->assertTrue($nameOption->isValueRequired());

		// Verify 'output' option is optional
		$outputOption = $definition->getOption('output');
		$this->assertTrue($outputOption->isValueOptional());
	}

	public function testInvalidUserId(): void {
		$userId = 'invalidUser';
		$input = $this->createInputMock($userId, self::TEST_ACCOUNT_NAME, 'testuser');
		$output = $this->createOutputMock();
		$output->expects($this->atLeastOnce())
			->method('writeln');

		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willReturn(self::TEST_MAIL_DOMAIN);

		$this->userManager->expects($this->once())
			->method('userExists')
			->with($userId)
			->willReturn(false);

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
	}

	public function testSuccessfulAccountCreation(): void {
		$accountId = 42;

		$input = $this->createInputMock(self::TEST_USER_ID, self::TEST_ACCOUNT_NAME, self::TEST_EMAIL_USER);
		$output = $this->createOutputMock();

		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willReturn(self::TEST_MAIL_DOMAIN);

		$this->userManager->expects($this->once())
			->method('userExists')
			->with(self::TEST_USER_ID)
			->willReturn(true);

		$savedAccount = new MailAccount();
		$savedAccount->setId($accountId);
		$savedAccount->setEmail(self::TEST_EMAIL_USER);

		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with(self::TEST_USER_ID, self::TEST_EMAIL_USER, self::TEST_ACCOUNT_NAME)
			->willReturn($savedAccount);

		$result = $this->command->run($input, $output);
		$this->assertSame(0, $result);
	}

	public function testServiceException(): void {
		$input = $this->createInputMock(self::TEST_USER_ID, self::TEST_ACCOUNT_NAME, self::TEST_EMAIL_USER);
		$output = $this->createOutputMock();

		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willReturn(self::TEST_MAIL_DOMAIN);

		$this->userManager->expects($this->once())
			->method('userExists')
			->with(self::TEST_USER_ID)
			->willReturn(true);

		$exception = new ServiceException('IONOS API error', 500);
		$this->accountCreationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with(self::TEST_USER_ID, self::TEST_EMAIL_USER, self::TEST_ACCOUNT_NAME)
			->willThrowException($exception);

		$result = $this->command->run($input, $output);
		$this->assertSame(1, $result);
	}
}

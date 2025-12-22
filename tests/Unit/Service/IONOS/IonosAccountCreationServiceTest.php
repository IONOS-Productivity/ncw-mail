<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Db\MailAccount;
use OCA\Mail\Exception\ProviderServiceException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\Common\Dto\MailAccountConfig;
use OCA\Mail\Provider\MailAccountProvider\Common\Dto\MailServerConfig;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\IONOS\ConflictResolutionResult;
use OCA\Mail\Service\IONOS\IonosAccountConflictResolver;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\Security\ICrypto;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosAccountCreationServiceTest extends TestCase {

	private IonosMailService&MockObject $ionosMailService;

	private IonosAccountConflictResolver&MockObject $conflictResolver;

	private AccountService&MockObject $accountService;

	private ICrypto&MockObject $crypto;

	private LoggerInterface&MockObject $logger;

	private IonosAccountCreationService $service;

	protected function setUp(): void {
		parent::setUp();

		$this->ionosMailService = $this->createMock(IonosMailService::class);
		$this->conflictResolver = $this->createMock(IonosAccountConflictResolver::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->crypto = $this->createMock(ICrypto::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->service = new IonosAccountCreationService(
			$this->ionosMailService,
			$this->conflictResolver,
			$this->accountService,
			$this->crypto,
			$this->logger,
		);
	}

	public function testCreateOrUpdateAccountNewAccount(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';
		$password = 'test-password-123';

		$mailConfig = $this->createMailAccountConfig($emailAddress, $password);

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([]);

		$this->ionosMailService->expects($this->once())
			->method('createEmailAccountForUser')
			->with($userId, $emailUser)
			->willReturn($mailConfig);

		$this->crypto->expects($this->exactly(2))
			->method('encrypt')
			->with($password)
			->willReturn('encrypted-' . $password);

		$savedAccount = new MailAccount();
		$savedAccount->setId(1);
		$savedAccount->setUserId($userId);
		$savedAccount->setEmail($emailAddress);

		$this->accountService->expects($this->once())
			->method('save')
			->willReturnCallback(function (MailAccount $account) use ($savedAccount) {
				$this->assertEquals('testuser', $account->getUserId());
				$this->assertEquals('Test Account', $account->getName());
				$this->assertEquals('test@example.com', $account->getEmail());
				$this->assertEquals('password', $account->getAuthMethod());
				return $savedAccount;
			});

		$this->logger->expects($this->exactly(3))
			->method('info');

		$result = $this->service->createOrUpdateAccount($userId, $emailUser, $accountName);

		$this->assertInstanceOf(Account::class, $result);
		$this->assertEquals(1, $result->getId());
	}

	public function testCreateOrUpdateAccountExistingNextcloudAccountSuccess(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';
		$newPassword = 'new-password-456';

		$existingAccount = new MailAccount();
		$existingAccount->setId(5);
		$existingAccount->setUserId($userId);
		$existingAccount->setEmail($emailAddress);

		$mailConfig = $this->createMailAccountConfig($emailAddress, $newPassword);

		$resolutionResult = ConflictResolutionResult::retry($mailConfig);

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([$this->createAccountWithMailAccount($existingAccount)]);

		$this->conflictResolver->expects($this->once())
			->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn($resolutionResult);

		$this->crypto->expects($this->exactly(2))
			->method('encrypt')
			->with($newPassword)
			->willReturn('encrypted-' . $newPassword);

		$this->accountService->expects($this->once())
			->method('update')
			->with($existingAccount)
			->willReturn($existingAccount);

		$this->logger->expects($this->exactly(2))
			->method('info');

		$result = $this->service->createOrUpdateAccount($userId, $emailUser, $accountName);

		$this->assertInstanceOf(Account::class, $result);
		$this->assertEquals(5, $result->getId());
	}

	public function testCreateOrUpdateAccountExistingAccountEmailMismatch(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';
		$existingEmail = 'different@example.com';

		$existingAccount = new MailAccount();
		$existingAccount->setId(5);
		$existingAccount->setUserId($userId);
		$existingAccount->setEmail($emailAddress);

		$resolutionResult = ConflictResolutionResult::emailMismatch($emailAddress, $existingEmail);

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([$this->createAccountWithMailAccount($existingAccount)]);

		$this->conflictResolver->expects($this->once())
			->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn($resolutionResult);

		$this->logger->expects($this->once())
			->method('info')
			->with('Nextcloud mail account already exists, resetting credentials', $this->anything());

		try {
			$this->service->createOrUpdateAccount($userId, $emailUser, $accountName);
			$this->fail('Expected ProviderServiceException to be thrown');
		} catch (ProviderServiceException $e) {
			$this->assertEquals(409, $e->getCode());
			$this->assertStringContainsString('IONOS account exists but email mismatch', $e->getMessage());

			$data = $e->getData();
			$this->assertArrayHasKey('expectedEmail', $data);
			$this->assertArrayHasKey('existingEmail', $data);
			$this->assertEquals($emailAddress, $data['expectedEmail']);
			$this->assertEquals($existingEmail, $data['existingEmail']);
		}
	}

	public function testCreateOrUpdateAccountNewAccountWithConflictResolution(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';
		$password = 'reset-password-789';

		$mailConfig = $this->createMailAccountConfig($emailAddress, $password);

		$resolutionResult = ConflictResolutionResult::retry($mailConfig);

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([]);

		// First attempt to create fails
		$this->ionosMailService->expects($this->once())
			->method('createEmailAccountForUser')
			->with($userId, $emailUser)
			->willThrowException(new ServiceException('Account already exists', 409));

		// Conflict resolution succeeds
		$this->conflictResolver->expects($this->once())
			->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn($resolutionResult);

		$this->crypto->expects($this->exactly(2))
			->method('encrypt')
			->with($password)
			->willReturn('encrypted-' . $password);

		$savedAccount = new MailAccount();
		$savedAccount->setId(2);
		$savedAccount->setUserId($userId);
		$savedAccount->setEmail($emailAddress);

		$this->accountService->expects($this->once())
			->method('save')
			->willReturn($savedAccount);

		$this->logger->expects($this->exactly(3))
			->method('info');

		$result = $this->service->createOrUpdateAccount($userId, $emailUser, $accountName);

		$this->assertInstanceOf(Account::class, $result);
		$this->assertEquals(2, $result->getId());
	}

	public function testCreateOrUpdateAccountSetsCorrectCredentials(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';
		$password = 'secret-password';

		$mailConfig = $this->createMailAccountConfig($emailAddress, $password);

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([]);

		$this->ionosMailService->expects($this->once())
			->method('createEmailAccountForUser')
			->with($userId, $emailUser)
			->willReturn($mailConfig);

		$this->crypto->expects($this->exactly(2))
			->method('encrypt')
			->with($password)
			->willReturn('encrypted-' . $password);

		$savedAccount = new MailAccount();
		$savedAccount->setId(10);

		$this->accountService->expects($this->once())
			->method('save')
			->willReturnCallback(function (MailAccount $account) use ($savedAccount, $emailAddress) {
				// Verify IMAP settings
				$this->assertEquals('imap.example.com', $account->getInboundHost());
				$this->assertEquals(993, $account->getInboundPort());
				$this->assertEquals('ssl', $account->getInboundSslMode());
				$this->assertEquals($emailAddress, $account->getInboundUser());
				$this->assertEquals('encrypted-secret-password', $account->getInboundPassword());

				// Verify SMTP settings
				$this->assertEquals('smtp.example.com', $account->getOutboundHost());
				$this->assertEquals(465, $account->getOutboundPort());
				$this->assertEquals('ssl', $account->getOutboundSslMode());
				$this->assertEquals($emailAddress, $account->getOutboundUser());
				$this->assertEquals('encrypted-secret-password', $account->getOutboundPassword());

				return $savedAccount;
			});

		$result = $this->service->createOrUpdateAccount($userId, $emailUser, $accountName);

		$this->assertInstanceOf(Account::class, $result);
	}

	private function createMailAccountConfig(string $emailAddress, string $password): MailAccountConfig {
		$imapConfig = new MailServerConfig(
			host: 'imap.example.com',
			port: 993,
			security: 'ssl',
			username: $emailAddress,
			password: $password,
		);

		$smtpConfig = new MailServerConfig(
			host: 'smtp.example.com',
			port: 465,
			security: 'ssl',
			username: $emailAddress,
			password: $password,
		);

		return new MailAccountConfig(
			email: $emailAddress,
			imap: $imapConfig,
			smtp: $smtpConfig,
		);
	}

	/**
	 * Helper to create an account object with a MailAccount
	 * This simulates the structure returned by AccountService::findByUserIdAndAddress
	 */
	public function testCreateOrUpdateAccountExistingAccountNoIonosAccount(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';

		$existingAccount = new MailAccount();
		$existingAccount->setId(5);
		$existingAccount->setUserId($userId);
		$existingAccount->setEmail($emailAddress);

		$resolutionResult = ConflictResolutionResult::noExistingAccount();

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([$this->createAccountWithMailAccount($existingAccount)]);

		$this->conflictResolver->expects($this->once())
			->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn($resolutionResult);

		$this->expectException(ServiceException::class);
		$this->expectExceptionCode(500);
		$this->expectExceptionMessage('Nextcloud account exists but no IONOS account found');

		$this->service->createOrUpdateAccount($userId, $emailUser, $accountName);
	}

	public function testCreateOrUpdateAccountExistingAccountConflictResolverThrows(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';

		$existingAccount = new MailAccount();
		$existingAccount->setId(5);
		$existingAccount->setUserId($userId);
		$existingAccount->setEmail($emailAddress);

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([$this->createAccountWithMailAccount($existingAccount)]);

		$originalException = new ServiceException('IONOS API error', 503);

		$this->conflictResolver->expects($this->once())
			->method('resolveConflict')
			->with($userId, $emailUser)
			->willThrowException($originalException);

		$this->expectException(ServiceException::class);
		$this->expectExceptionCode(503);
		$this->expectExceptionMessage('Failed to reset IONOS account credentials: IONOS API error');

		$this->service->createOrUpdateAccount($userId, $emailUser, $accountName);
	}

	public function testCreateOrUpdateAccountNewAccountConflictResolutionFails(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([]);

		$originalException = new ServiceException('Account creation failed', 500);

		$this->ionosMailService->expects($this->once())
			->method('createEmailAccountForUser')
			->with($userId, $emailUser)
			->willThrowException($originalException);

		$resolutionResult = ConflictResolutionResult::noExistingAccount();

		$this->conflictResolver->expects($this->once())
			->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn($resolutionResult);

		$this->logger->expects($this->exactly(2))
			->method('info');

		$this->expectException(ServiceException::class);
		$this->expectExceptionCode(500);
		$this->expectExceptionMessage('Account creation failed');

		$this->service->createOrUpdateAccount($userId, $emailUser, $accountName);
	}

	public function testCreateOrUpdateAccountNewAccountConflictResolutionEmailMismatch(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Test Account';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';
		$existingEmail = 'other@example.com';

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([]);

		$originalException = new ServiceException('Account already exists', 409);

		$this->ionosMailService->expects($this->once())
			->method('createEmailAccountForUser')
			->with($userId, $emailUser)
			->willThrowException($originalException);

		$resolutionResult = ConflictResolutionResult::emailMismatch($emailAddress, $existingEmail);

		$this->conflictResolver->expects($this->once())
			->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn($resolutionResult);

		$this->logger->expects($this->exactly(2))
			->method('info');

		try {
			$this->service->createOrUpdateAccount($userId, $emailUser, $accountName);
			$this->fail('Expected ProviderServiceException to be thrown');
		} catch (ProviderServiceException $e) {
			$this->assertEquals(409, $e->getCode());
			$this->assertStringContainsString('IONOS account exists but email mismatch', $e->getMessage());
			$this->assertStringContainsString($emailAddress, $e->getMessage());
			$this->assertStringContainsString($existingEmail, $e->getMessage());

			$data = $e->getData();
			$this->assertArrayHasKey('expectedEmail', $data);
			$this->assertArrayHasKey('existingEmail', $data);
			$this->assertEquals($emailAddress, $data['expectedEmail']);
			$this->assertEquals($existingEmail, $data['existingEmail']);

			// Verify the previous exception is set
			$this->assertSame($originalException, $e->getPrevious());
		}
	}

	public function testUpdateAccountSetsCorrectCredentials(): void {
		$userId = 'testuser';
		$emailUser = 'test';
		$accountName = 'Updated Account Name';
		$domain = 'example.com';
		$emailAddress = 'test@example.com';
		$newPassword = 'new-password-xyz';

		$existingAccount = new MailAccount();
		$existingAccount->setId(7);
		$existingAccount->setUserId($userId);
		$existingAccount->setEmail($emailAddress);
		$existingAccount->setName('Old Account Name');

		$mailConfig = $this->createMailAccountConfig($emailAddress, $newPassword);
		$resolutionResult = ConflictResolutionResult::retry($mailConfig);

		$this->ionosMailService->method('getMailDomain')
			->willReturn($domain);

		$this->accountService->expects($this->once())
			->method('findByUserIdAndAddress')
			->with($userId, $emailAddress)
			->willReturn([$this->createAccountWithMailAccount($existingAccount)]);

		$this->conflictResolver->expects($this->once())
			->method('resolveConflict')
			->with($userId, $emailUser)
			->willReturn($resolutionResult);

		$this->crypto->expects($this->exactly(2))
			->method('encrypt')
			->with($newPassword)
			->willReturn('encrypted-' . $newPassword);

		$this->accountService->expects($this->once())
			->method('update')
			->willReturnCallback(function (MailAccount $account) use ($existingAccount, $emailAddress, $accountName) {
				// Verify account name is updated
				$this->assertEquals($accountName, $account->getName());

				// Verify IMAP settings
				$this->assertEquals('imap.example.com', $account->getInboundHost());
				$this->assertEquals(993, $account->getInboundPort());
				$this->assertEquals('ssl', $account->getInboundSslMode());
				$this->assertEquals($emailAddress, $account->getInboundUser());
				$this->assertEquals('encrypted-new-password-xyz', $account->getInboundPassword());

				// Verify SMTP settings
				$this->assertEquals('smtp.example.com', $account->getOutboundHost());
				$this->assertEquals(465, $account->getOutboundPort());
				$this->assertEquals('ssl', $account->getOutboundSslMode());
				$this->assertEquals($emailAddress, $account->getOutboundUser());
				$this->assertEquals('encrypted-new-password-xyz', $account->getOutboundPassword());

				return $existingAccount;
			});

		$result = $this->service->createOrUpdateAccount($userId, $emailUser, $accountName);

		$this->assertInstanceOf(Account::class, $result);
		$this->assertEquals(7, $result->getId());
	}

	private function createAccountWithMailAccount(MailAccount $mailAccount): object {
		return new class($mailAccount) {
			public function __construct(
				private MailAccount $mailAccount,
			) {
			}

			public function getId(): int {
				return $this->mailAccount->getId();
			}

			public function getEmail(): string {
				return $this->mailAccount->getEmail();
			}

			public function getMailAccount(): MailAccount {
				return $this->mailAccount;
			}
		};
	}
}

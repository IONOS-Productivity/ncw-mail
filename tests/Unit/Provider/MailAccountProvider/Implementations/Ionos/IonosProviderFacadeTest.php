<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Provider\MailAccountProvider\Implementations\Ionos;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Account;
use OCA\Mail\Exception\AccountAlreadyExistsException;
use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Provider\MailAccountProvider\Dto\MailboxInfo;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\IonosProviderFacade;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core\IonosAccountMutationService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\Core\IonosAccountQueryService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosAccountCreationService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosConfigService;
use OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service\IonosMailConfigService;
use OCA\Mail\Service\AccountService;
use OCP\IUserManager;
use PHPUnit\Framework\MockObject\MockObject;
use Psr\Log\LoggerInterface;

class IonosProviderFacadeTest extends TestCase {
	private IonosConfigService&MockObject $configService;
	private IonosAccountQueryService&MockObject $queryService;
	private IonosAccountMutationService&MockObject $mutationService;
	private IonosAccountCreationService&MockObject $creationService;
	private IonosMailConfigService&MockObject $mailConfigService;
	private AccountService&MockObject $accountService;
	private IUserManager&MockObject $userManager;
	private LoggerInterface&MockObject $logger;
	private IonosProviderFacade $facade;

	protected function setUp(): void {
		parent::setUp();

		$this->configService = $this->createMock(IonosConfigService::class);
		$this->queryService = $this->createMock(IonosAccountQueryService::class);
		$this->mutationService = $this->createMock(IonosAccountMutationService::class);
		$this->creationService = $this->createMock(IonosAccountCreationService::class);
		$this->mailConfigService = $this->createMock(IonosMailConfigService::class);
		$this->accountService = $this->createMock(AccountService::class);
		$this->userManager = $this->createMock(IUserManager::class);
		$this->logger = $this->createMock(LoggerInterface::class);

		$this->facade = new IonosProviderFacade(
			$this->configService,
			$this->queryService,
			$this->mutationService,
			$this->creationService,
			$this->mailConfigService,
			$this->accountService,
			$this->userManager,
			$this->logger,
		);
	}

	public function testIsEnabledReturnsTrue(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(true);

		$result = $this->facade->isEnabled();

		$this->assertTrue($result);
	}

	public function testIsEnabledReturnsFalse(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willReturn(false);

		$result = $this->facade->isEnabled();

		$this->assertFalse($result);
	}

	public function testIsEnabledHandlesException(): void {
		$this->configService->expects($this->once())
			->method('isIonosIntegrationEnabled')
			->willThrowException(new \Exception('Config error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('IONOS provider is not enabled', $this->anything());

		$result = $this->facade->isEnabled();

		$this->assertFalse($result);
	}

	public function testIsAvailableForUserReturnsTrueWhenConfigAvailable(): void {
		$userId = 'user123';

		$this->mailConfigService->expects($this->once())
			->method('isMailConfigAvailable')
			->with($userId)
			->willReturn(true);

		$result = $this->facade->isAvailableForUser($userId);

		$this->assertTrue($result);
	}

	public function testIsAvailableForUserReturnsFalseWhenConfigNotAvailable(): void {
		$userId = 'user123';

		$this->mailConfigService->expects($this->once())
			->method('isMailConfigAvailable')
			->with($userId)
			->willReturn(false);

		$result = $this->facade->isAvailableForUser($userId);

		$this->assertFalse($result);
	}

	public function testIsAvailableForUserHandlesException(): void {
		$userId = 'user123';

		$this->mailConfigService->expects($this->once())
			->method('isMailConfigAvailable')
			->with($userId)
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('error')
			->with('Error checking IONOS availability for user', $this->anything());

		$result = $this->facade->isAvailableForUser($userId);

		$this->assertFalse($result);
	}

	public function testCreateAccountSuccess(): void {
		$userId = 'user123';
		$emailUser = 'john.doe';
		$accountName = 'John Doe';
		$account = $this->createMock(Account::class);

		$this->logger->expects($this->once())
			->method('info')
			->with('Creating IONOS account via facade', [
				'userId' => $userId,
				'emailUser' => $emailUser,
			]);

		$this->creationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willReturn($account);

		$result = $this->facade->createAccount($userId, $emailUser, $accountName);

		$this->assertSame($account, $result);
	}

	public function testUpdateAccountSuccess(): void {
		$userId = 'user123';
		$emailUser = 'john.doe';
		$accountName = 'John Doe';
		$account = $this->createMock(Account::class);

		$this->logger->expects($this->once())
			->method('info')
			->with('Updating IONOS account via facade', [
				'userId' => $userId,
				'emailUser' => $emailUser,
			]);

		$this->creationService->expects($this->once())
			->method('createOrUpdateAccount')
			->with($userId, $emailUser, $accountName)
			->willReturn($account);

		$result = $this->facade->updateAccount($userId, $emailUser, $accountName);

		$this->assertSame($account, $result);
	}

	public function testDeleteAccountSuccess(): void {
		$userId = 'user123';
		$email = 'user123@example.com';

		$this->logger->expects($this->once())
			->method('info')
			->with('Deleting IONOS account via facade', [
				'userId' => $userId,
				'email' => $email,
			]);

		$this->mutationService->expects($this->once())
			->method('tryDeleteEmailAccount')
			->with($userId, $email);

		$result = $this->facade->deleteAccount($userId, $email);

		$this->assertTrue($result);
	}

	public function testDeleteAccountHandlesException(): void {
		$userId = 'user123';
		$email = 'user123@example.com';

		$this->logger->expects($this->once())
			->method('info')
			->with('Deleting IONOS account via facade', [
				'userId' => $userId,
				'email' => $email,
			]);

		$this->mutationService->expects($this->once())
			->method('tryDeleteEmailAccount')
			->with($userId, $email)
			->willThrowException(new \Exception('Deletion failed'));

		$this->logger->expects($this->once())
			->method('error')
			->with('Error deleting IONOS account via facade', $this->anything());

		$result = $this->facade->deleteAccount($userId, $email);

		$this->assertFalse($result);
	}

	public function testGetProvisionedEmailSuccess(): void {
		$userId = 'user123';
		$email = 'user@ionos.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn($email);

		$result = $this->facade->getProvisionedEmail($userId);

		$this->assertSame($email, $result);
	}

	public function testGetProvisionedEmailHandlesException(): void {
		$userId = 'user123';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Error getting IONOS provisioned email', $this->anything());

		$result = $this->facade->getProvisionedEmail($userId);

		$this->assertNull($result);
	}

	public function testManagesEmailReturnsTrue(): void {
		$userId = 'user123';
		$email = 'user@ionos.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn($email);

		$result = $this->facade->managesEmail($userId, $email);

		$this->assertTrue($result);
	}

	public function testManagesEmailReturnsTrueCaseInsensitive(): void {
		$userId = 'user123';
		$email = 'user@ionos.com';
		$checkEmail = 'USER@IONOS.COM';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn($email);

		$result = $this->facade->managesEmail($userId, $checkEmail);

		$this->assertTrue($result);
	}

	public function testManagesEmailReturnsFalseWhenNoIonosAccount(): void {
		$userId = 'user123';
		$email = 'user@other.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn(null);

		$result = $this->facade->managesEmail($userId, $email);

		$this->assertFalse($result);
	}

	public function testManagesEmailReturnsFalseWhenDifferentEmail(): void {
		$userId = 'user123';
		$ionosEmail = 'user@ionos.com';
		$checkEmail = 'other@ionos.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willReturn($ionosEmail);

		$result = $this->facade->managesEmail($userId, $checkEmail);

		$this->assertFalse($result);
	}

	public function testManagesEmailHandlesException(): void {
		$userId = 'user123';
		$email = 'user@ionos.com';

		$this->queryService->expects($this->once())
			->method('getIonosEmailForUser')
			->with($userId)
			->willThrowException(new \Exception('Service error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Error getting IONOS provisioned email', $this->anything());

		$result = $this->facade->managesEmail($userId, $email);

		$this->assertFalse($result);
	}

	public function testGetEmailDomainSuccess(): void {
		$domain = 'ionos.com';

		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willReturn($domain);

		$result = $this->facade->getEmailDomain();

		$this->assertSame($domain, $result);
	}

	public function testGetEmailDomainHandlesException(): void {
		$this->configService->expects($this->once())
			->method('getMailDomain')
			->willThrowException(new \Exception('Config error'));

		$this->logger->expects($this->once())
			->method('debug')
			->with('Could not get IONOS email domain', $this->anything());

		$result = $this->facade->getEmailDomain();

		$this->assertNull($result);
	}

	public function testGenerateAppPasswordSuccess(): void {
		$userId = 'user123';
		$appPassword = 'generated-app-password-xyz';

		$this->logger->expects($this->once())
			->method('info')
			->with('Generating IONOS app password via facade', [
				'userId' => $userId,
			]);

		$this->mutationService->expects($this->once())
			->method('resetAppPassword')
			->with($userId, IonosConfigService::APP_PASSWORD_NAME_USER)
			->willReturn($appPassword);

		$result = $this->facade->generateAppPassword($userId);

		$this->assertSame($appPassword, $result);
	}

	public function testGenerateAppPasswordThrowsException(): void {
		$userId = 'user123';
		$exception = new \Exception('Password generation failed');

		$this->logger->expects($this->once())
			->method('info')
			->with('Generating IONOS app password via facade', [
				'userId' => $userId,
			]);

		$this->mutationService->expects($this->once())
			->method('resetAppPassword')
			->with($userId, IonosConfigService::APP_PASSWORD_NAME_USER)
			->willThrowException($exception);

		$this->expectException(\Exception::class);
		$this->expectExceptionMessage('Password generation failed');

		$this->facade->generateAppPassword($userId);
	}

	public function testGetMailboxesSuccess(): void {
		$mockResponse1 = $this->createMock(\IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse::class);
		$mockResponse1->method('getEmail')->willReturn('user1@example.com');
		$mockResponse1->method('getNextcloudUserId')->willReturn('user1');

		$mockResponse2 = $this->createMock(\IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse::class);
		$mockResponse2->method('getEmail')->willReturn('user2@example.com');
		$mockResponse2->method('getNextcloudUserId')->willReturn('user2');

		$accountResponses = [$mockResponse1, $mockResponse2];

		$this->queryService->expects($this->once())
			->method('getAllMailAccountResponses')
			->willReturn($accountResponses);

		$mockUser1 = $this->createMock(\OCP\IUser::class);
		$mockUser2 = $this->createMock(\OCP\IUser::class);

		$this->userManager->expects($this->exactly(2))
			->method('get')
			->willReturnMap([
				['user1', $mockUser1],
				['user2', $mockUser2],
			]);

		$mockMailAccount1 = $this->getMockBuilder(\OCA\Mail\Db\MailAccount::class)
			->disableOriginalConstructor()
			->addMethods(['getId', 'getName', 'getEmail'])
			->getMock();
		$mockMailAccount1->method('getId')->willReturn(1);
		$mockMailAccount1->method('getName')->willReturn('User 1 Mail');
		$mockMailAccount1->method('getEmail')->willReturn('user1@example.com');

		$mockAccount1 = $this->createMock(Account::class);
		$mockAccount1->method('getMailAccount')->willReturn($mockMailAccount1);
		$mockAccount1->method('getEmail')->willReturn('user1@example.com');

		$mockMailAccount2 = $this->getMockBuilder(\OCA\Mail\Db\MailAccount::class)
			->disableOriginalConstructor()
			->addMethods(['getId', 'getName', 'getEmail'])
			->getMock();
		$mockMailAccount2->method('getId')->willReturn(2);
		$mockMailAccount2->method('getName')->willReturn('User 2 Mail');
		$mockMailAccount2->method('getEmail')->willReturn('user2@example.com');

		$mockAccount2 = $this->createMock(Account::class);
		$mockAccount2->method('getMailAccount')->willReturn($mockMailAccount2);
		$mockAccount2->method('getEmail')->willReturn('user2@example.com');

		$this->accountService->expects($this->exactly(2))
			->method('findByUserId')
			->willReturnMap([
				['user1', [$mockAccount1]],
				['user2', [$mockAccount2]],
			]);

		$result = $this->facade->getMailboxes();

		$this->assertIsArray($result);
		$this->assertCount(2, $result);
		$this->assertContainsOnlyInstancesOf(MailboxInfo::class, $result);

		$this->assertEquals('user1', $result[0]->userId);
		$this->assertEquals('user1@example.com', $result[0]->email);
		$this->assertTrue($result[0]->userExists);
		$this->assertEquals(1, $result[0]->mailAppAccountId);
		$this->assertEquals('User 1 Mail', $result[0]->mailAppAccountName);
		$this->assertTrue($result[0]->mailAppAccountExists);

		$this->assertEquals('user2', $result[1]->userId);
		$this->assertEquals('user2@example.com', $result[1]->email);
		$this->assertTrue($result[1]->userExists);
		$this->assertEquals(2, $result[1]->mailAppAccountId);
		$this->assertEquals('User 2 Mail', $result[1]->mailAppAccountName);
		$this->assertTrue($result[1]->mailAppAccountExists);
	}

	public function testGetMailboxesWithNonExistentUser(): void {
		$mockResponse = $this->createMock(\IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse::class);
		$mockResponse->method('getEmail')->willReturn('deleted@example.com');
		$mockResponse->method('getNextcloudUserId')->willReturn('deleteduser');

		$accountResponses = [$mockResponse];

		$this->queryService->expects($this->once())
			->method('getAllMailAccountResponses')
			->willReturn($accountResponses);

		$this->userManager->expects($this->once())
			->method('get')
			->with('deleteduser')
			->willReturn(null);

		$this->accountService->expects($this->never())
			->method('findByUserId');

		$result = $this->facade->getMailboxes();

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertContainsOnlyInstancesOf(MailboxInfo::class, $result);

		$this->assertEquals('deleteduser', $result[0]->userId);
		$this->assertEquals('deleted@example.com', $result[0]->email);
		$this->assertFalse($result[0]->userExists);
		$this->assertNull($result[0]->mailAppAccountId);
		$this->assertNull($result[0]->mailAppAccountName);
		$this->assertFalse($result[0]->mailAppAccountExists);
	}

	public function testGetMailboxesWithNoMailAppAccount(): void {
		$mockResponse = $this->createMock(\IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse::class);
		$mockResponse->method('getEmail')->willReturn('nomail@example.com');
		$mockResponse->method('getNextcloudUserId')->willReturn('user1');

		$accountResponses = [$mockResponse];

		$this->queryService->expects($this->once())
			->method('getAllMailAccountResponses')
			->willReturn($accountResponses);

		$mockUser = $this->createMock(\OCP\IUser::class);

		$this->userManager->expects($this->once())
			->method('get')
			->with('user1')
			->willReturn($mockUser);

		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with('user1')
			->willReturn([]);

		$result = $this->facade->getMailboxes();

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertContainsOnlyInstancesOf(MailboxInfo::class, $result);

		$this->assertEquals('user1', $result[0]->userId);
		$this->assertEquals('nomail@example.com', $result[0]->email);
		$this->assertTrue($result[0]->userExists);
		$this->assertNull($result[0]->mailAppAccountId);
		$this->assertNull($result[0]->mailAppAccountName);
		$this->assertFalse($result[0]->mailAppAccountExists);
	}

	public function testGetMailboxesWithDifferentEmailInMailApp(): void {
		$mockResponse = $this->createMock(\IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse::class);
		$mockResponse->method('getEmail')->willReturn('provider@example.com');
		$mockResponse->method('getNextcloudUserId')->willReturn('user1');

		$accountResponses = [$mockResponse];

		$this->queryService->expects($this->once())
			->method('getAllMailAccountResponses')
			->willReturn($accountResponses);

		$mockUser = $this->createMock(\OCP\IUser::class);

		$this->userManager->expects($this->once())
			->method('get')
			->with('user1')
			->willReturn($mockUser);

		$mockMailAccount = $this->getMockBuilder(\OCA\Mail\Db\MailAccount::class)
			->disableOriginalConstructor()
			->addMethods(['getEmail'])
			->getMock();
		$mockMailAccount->method('getEmail')->willReturn('different@example.com');

		$mockAccount = $this->createMock(Account::class);
		$mockAccount->method('getMailAccount')->willReturn($mockMailAccount);
		$mockAccount->method('getEmail')->willReturn('different@example.com');

		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with('user1')
			->willReturn([$mockAccount]);

		$result = $this->facade->getMailboxes();

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertContainsOnlyInstancesOf(MailboxInfo::class, $result);

		$this->assertEquals('user1', $result[0]->userId);
		$this->assertEquals('provider@example.com', $result[0]->email);
		$this->assertTrue($result[0]->userExists);
		$this->assertNull($result[0]->mailAppAccountId);
		$this->assertNull($result[0]->mailAppAccountName);
		$this->assertFalse($result[0]->mailAppAccountExists);
	}

	public function testGetMailboxesWithAccountServiceException(): void {
		$mockResponse = $this->createMock(\IONOS\MailConfigurationAPI\Client\Model\MailAccountResponse::class);
		$mockResponse->method('getEmail')->willReturn('user@example.com');
		$mockResponse->method('getNextcloudUserId')->willReturn('user1');

		$accountResponses = [$mockResponse];

		$this->queryService->expects($this->once())
			->method('getAllMailAccountResponses')
			->willReturn($accountResponses);

		$mockUser = $this->createMock(\OCP\IUser::class);

		$this->userManager->expects($this->once())
			->method('get')
			->with('user1')
			->willReturn($mockUser);

		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with('user1')
			->willThrowException(new \Exception('Account service error'));

		$this->logger->expects($this->atLeastOnce())
			->method('debug');

		$result = $this->facade->getMailboxes();

		$this->assertIsArray($result);
		$this->assertCount(1, $result);
		$this->assertContainsOnlyInstancesOf(MailboxInfo::class, $result);

		$this->assertEquals('user1', $result[0]->userId);
		$this->assertEquals('user@example.com', $result[0]->email);
		$this->assertTrue($result[0]->userExists);
		$this->assertNull($result[0]->mailAppAccountId);
		$this->assertNull($result[0]->mailAppAccountName);
		$this->assertFalse($result[0]->mailAppAccountExists);
	}

	public function testGetMailboxesEmpty(): void {
		$this->queryService->expects($this->once())
			->method('getAllMailAccountResponses')
			->willReturn([]);

		$this->logger->expects($this->atLeastOnce())
			->method('debug');

		$result = $this->facade->getMailboxes();

		$this->assertIsArray($result);
		$this->assertEmpty($result);
	}

	public function testUpdateMailboxSuccess(): void {
		$userId = 'user1';
		$currentEmail = 'old@example.com';
		$newLocalpart = 'new';
		$newEmail = 'new@example.com';

		$this->mutationService->expects($this->once())
			->method('updateMailboxLocalpart')
			->with($userId, $newLocalpart)
			->willReturn($newEmail);

		$mockUser = $this->createMock(\OCP\IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with($userId)
			->willReturn($mockUser);

		$mockMailAccount = $this->getMockBuilder(\OCA\Mail\Db\MailAccount::class)
			->disableOriginalConstructor()
			->addMethods(['getId', 'getName', 'getEmail', 'setEmail', 'setInboundUser', 'setOutboundUser'])
			->getMock();
		$mockMailAccount->method('getId')->willReturn(1);
		$mockMailAccount->method('getName')->willReturn('User Mail');

		$mockAccount = $this->createMock(Account::class);
		$mockAccount->method('getEmail')->willReturn($currentEmail);
		$mockAccount->method('getMailAccount')->willReturn($mockMailAccount);

		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with($userId)
			->willReturn([$mockAccount]);

		$this->accountService->expects($this->once())
			->method('update')
			->with($mockMailAccount)
			->willReturn($mockMailAccount);

		$result = $this->facade->updateMailbox($userId, $currentEmail, $newLocalpart);

		$this->assertInstanceOf(MailboxInfo::class, $result);
		$this->assertEquals($userId, $result->userId);
		$this->assertEquals($newEmail, $result->email);
		$this->assertTrue($result->userExists);
		$this->assertTrue($result->mailAppAccountExists);
	}

	public function testUpdateMailboxSelectsCorrectAccountWhenMultipleExist(): void {
		$userId = 'user1';
		$currentEmail = 'target@example.com';
		$newLocalpart = 'updated';
		$newEmail = 'updated@example.com';

		$this->mutationService->expects($this->once())
			->method('updateMailboxLocalpart')
			->with($userId, $newLocalpart)
			->willReturn($newEmail);

		$mockUser = $this->createMock(\OCP\IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with($userId)
			->willReturn($mockUser);

		$mockMailAccountOther = $this->getMockBuilder(\OCA\Mail\Db\MailAccount::class)
			->disableOriginalConstructor()
			->addMethods(['getId', 'getName'])
			->getMock();
		$mockMailAccountOther->method('getId')->willReturn(2);
		$mockMailAccountOther->method('getName')->willReturn('Other Account');

		$mockAccountOther = $this->createMock(Account::class);
		$mockAccountOther->method('getEmail')->willReturn('other@example.com');
		$mockAccountOther->method('getMailAccount')->willReturn($mockMailAccountOther);

		$mockMailAccountTarget = $this->getMockBuilder(\OCA\Mail\Db\MailAccount::class)
			->disableOriginalConstructor()
			->addMethods(['getId', 'getName', 'setEmail', 'setInboundUser', 'setOutboundUser'])
			->getMock();
		$mockMailAccountTarget->method('getId')->willReturn(3);
		$mockMailAccountTarget->method('getName')->willReturn('Target Account');

		$mockAccountTarget = $this->createMock(Account::class);
		$mockAccountTarget->method('getEmail')->willReturn($currentEmail);
		$mockAccountTarget->method('getMailAccount')->willReturn($mockMailAccountTarget);

		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with($userId)
			->willReturn([$mockAccountOther, $mockAccountTarget]);

		$this->accountService->expects($this->once())
			->method('update')
			->with($mockMailAccountTarget)
			->willReturn($mockMailAccountTarget);

		$result = $this->facade->updateMailbox($userId, $currentEmail, $newLocalpart);

		$this->assertInstanceOf(MailboxInfo::class, $result);
		$this->assertEquals(3, $result->mailAppAccountId);
		$this->assertTrue($result->mailAppAccountExists);
	}

	public function testUpdateMailboxMatchesCaseInsensitively(): void {
		$userId = 'user1';
		$currentEmail = 'User@Example.COM';
		$newLocalpart = '';

		$mockUser = $this->createMock(\OCP\IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with($userId)
			->willReturn($mockUser);

		$mockMailAccount = $this->getMockBuilder(\OCA\Mail\Db\MailAccount::class)
			->disableOriginalConstructor()
			->addMethods(['getId', 'getName'])
			->getMock();
		$mockMailAccount->method('getId')->willReturn(1);
		$mockMailAccount->method('getName')->willReturn('User Mail');

		$mockAccount = $this->createMock(Account::class);
		$mockAccount->method('getEmail')->willReturn('user@example.com');
		$mockAccount->method('getMailAccount')->willReturn($mockMailAccount);

		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with($userId)
			->willReturn([$mockAccount]);

		$this->accountService->expects($this->once())
			->method('update')
			->with($mockMailAccount)
			->willReturn($mockMailAccount);

		$result = $this->facade->updateMailbox($userId, $currentEmail, $newLocalpart);

		$this->assertInstanceOf(MailboxInfo::class, $result);
		$this->assertTrue($result->mailAppAccountExists);
	}

	public function testUpdateMailboxWithNonExistentUser(): void {
		$userId = 'nonexistent';
		$currentEmail = 'user@example.com';
		$newLocalpart = 'newlocal';
		$newEmail = 'newlocal@example.com';

		$this->mutationService->expects($this->once())
			->method('updateMailboxLocalpart')
			->with($userId, $newLocalpart)
			->willReturn($newEmail);

		$this->userManager->expects($this->once())
			->method('get')
			->with($userId)
			->willReturn(null);

		$this->accountService->expects($this->never())
			->method('findByUserId');

		$result = $this->facade->updateMailbox($userId, $currentEmail, $newLocalpart);

		$this->assertInstanceOf(MailboxInfo::class, $result);
		$this->assertEquals($newEmail, $result->email);
		$this->assertFalse($result->userExists);
		$this->assertFalse($result->mailAppAccountExists);
	}

	public function testUpdateMailboxWithNoMatchingAccount(): void {
		$userId = 'user1';
		$currentEmail = 'current@example.com';
		$newLocalpart = '';

		$mockUser = $this->createMock(\OCP\IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with($userId)
			->willReturn($mockUser);

		$mockAccount = $this->createMock(Account::class);
		$mockAccount->method('getEmail')->willReturn('other@example.com');

		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with($userId)
			->willReturn([$mockAccount]);

		$this->accountService->expects($this->never())
			->method('update');

		$result = $this->facade->updateMailbox($userId, $currentEmail, $newLocalpart);

		$this->assertInstanceOf(MailboxInfo::class, $result);
		$this->assertFalse($result->mailAppAccountExists);
		$this->assertNull($result->mailAppAccountId);
	}

	public function testUpdateMailboxHandlesAccountServiceException(): void {
		$userId = 'user1';
		$currentEmail = 'user@example.com';
		$newLocalpart = '';

		$mockUser = $this->createMock(\OCP\IUser::class);
		$this->userManager->expects($this->once())
			->method('get')
			->with($userId)
			->willReturn($mockUser);

		$this->accountService->expects($this->once())
			->method('findByUserId')
			->with($userId)
			->willThrowException(new \Exception('DB error'));

		$this->logger->expects($this->once())
			->method('warning')
			->with('Could not update local mail account', $this->anything());

		$result = $this->facade->updateMailbox($userId, $currentEmail, $newLocalpart);

		$this->assertInstanceOf(MailboxInfo::class, $result);
		$this->assertTrue($result->userExists);
		$this->assertFalse($result->mailAppAccountExists);
	}

	public function testUpdateMailboxThrowsAccountAlreadyExistsExceptionOn409(): void {
		$userId = 'user1';
		$currentEmail = 'user@example.com';
		$newLocalpart = 'existing';

		$serviceException = new ServiceException('Conflict', 409);

		$this->mutationService->expects($this->once())
			->method('updateMailboxLocalpart')
			->with($userId, $newLocalpart)
			->willThrowException($serviceException);

		$this->expectException(AccountAlreadyExistsException::class);

		$this->facade->updateMailbox($userId, $currentEmail, $newLocalpart);
	}

	public function testUpdateMailboxRethrowsOtherServiceExceptions(): void {
		$userId = 'user1';
		$currentEmail = 'user@example.com';
		$newLocalpart = 'failing';

		$serviceException = new ServiceException('Server error', 500);

		$this->mutationService->expects($this->once())
			->method('updateMailboxLocalpart')
			->with($userId, $newLocalpart)
			->willThrowException($serviceException);

		$this->expectException(ServiceException::class);
		$this->expectExceptionCode(500);

		$this->facade->updateMailbox($userId, $currentEmail, $newLocalpart);
	}

}


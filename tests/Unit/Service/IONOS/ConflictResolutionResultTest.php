<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Tests\Unit\Service\IONOS;

use ChristophWurst\Nextcloud\Testing\TestCase;
use OCA\Mail\Service\IONOS\ConflictResolutionResult;
use OCA\Mail\Service\IONOS\Dto\MailAccountConfig;
use OCA\Mail\Service\IONOS\Dto\MailServerConfig;

class ConflictResolutionResultTest extends TestCase {
	private MailAccountConfig $accountConfig;

	protected function setUp(): void {
		parent::setUp();

		$imapConfig = new MailServerConfig(
			host: 'imap.example.com',
			port: 993,
			security: 'ssl',
			username: 'user@example.com',
			password: 'imap-password',
		);

		$smtpConfig = new MailServerConfig(
			host: 'smtp.example.com',
			port: 587,
			security: 'tls',
			username: 'user@example.com',
			password: 'smtp-password',
		);

		$this->accountConfig = new MailAccountConfig(
			email: 'user@example.com',
			imap: $imapConfig,
			smtp: $smtpConfig,
		);
	}

	public function testRetryFactoryMethod(): void {
		$result = ConflictResolutionResult::retry($this->accountConfig);

		$this->assertInstanceOf(ConflictResolutionResult::class, $result);
		$this->assertTrue($result->canRetry());
		$this->assertInstanceOf(MailAccountConfig::class, $result->getAccountConfig());
		$this->assertSame($this->accountConfig, $result->getAccountConfig());
		$this->assertFalse($result->hasEmailMismatch());
		$this->assertNull($result->getExpectedEmail());
		$this->assertNull($result->getExistingEmail());
	}

	public function testNoExistingAccountFactoryMethod(): void {
		$result = ConflictResolutionResult::noExistingAccount();

		$this->assertInstanceOf(ConflictResolutionResult::class, $result);
		$this->assertFalse($result->canRetry());
		$this->assertNull($result->getAccountConfig());
		$this->assertFalse($result->hasEmailMismatch());
		$this->assertNull($result->getExpectedEmail());
		$this->assertNull($result->getExistingEmail());
	}

	public function testEmailMismatchFactoryMethod(): void {
		$expectedEmail = 'expected@example.com';
		$existingEmail = 'existing@example.com';

		$result = ConflictResolutionResult::emailMismatch($expectedEmail, $existingEmail);

		$this->assertInstanceOf(ConflictResolutionResult::class, $result);
		$this->assertFalse($result->canRetry());
		$this->assertNull($result->getAccountConfig());
		$this->assertTrue($result->hasEmailMismatch());
		$this->assertEquals($expectedEmail, $result->getExpectedEmail());
		$this->assertEquals($existingEmail, $result->getExistingEmail());
	}

	public function testRetryResultHasCorrectState(): void {
		$result = ConflictResolutionResult::retry($this->accountConfig);

		// Verify all state is correct for retry scenario
		$this->assertTrue($result->canRetry(), 'Should be able to retry');
		$this->assertNotNull($result->getAccountConfig(), 'Should have account config');
		$this->assertEquals('user@example.com', $result->getAccountConfig()->getEmail());
	}

	public function testNoExistingAccountResultHasCorrectState(): void {
		$result = ConflictResolutionResult::noExistingAccount();

		// Verify all state is correct for no existing account scenario
		$this->assertFalse($result->canRetry(), 'Should not be able to retry');
		$this->assertNull($result->getAccountConfig(), 'Should not have account config');
		$this->assertFalse($result->hasEmailMismatch(), 'Should not have email mismatch');
	}

	public function testEmailMismatchResultHasCorrectState(): void {
		$result = ConflictResolutionResult::emailMismatch('user1@example.com', 'user2@example.com');

		// Verify all state is correct for email mismatch scenario
		$this->assertFalse($result->canRetry(), 'Should not be able to retry');
		$this->assertNull($result->getAccountConfig(), 'Should not have account config');
		$this->assertTrue($result->hasEmailMismatch(), 'Should have email mismatch');
		$this->assertNotNull($result->getExpectedEmail(), 'Should have expected email');
		$this->assertNotNull($result->getExistingEmail(), 'Should have existing email');
	}

	public function testEmailMismatchWithSameEmail(): void {
		// Even with same email, if using emailMismatch() factory, it should still mark as mismatch
		$email = 'same@example.com';
		$result = ConflictResolutionResult::emailMismatch($email, $email);

		$this->assertTrue($result->hasEmailMismatch());
		$this->assertEquals($email, $result->getExpectedEmail());
		$this->assertEquals($email, $result->getExistingEmail());
	}

	public function testRetryResultPreservesAccountConfigData(): void {
		$result = ConflictResolutionResult::retry($this->accountConfig);
		$retrievedConfig = $result->getAccountConfig();

		$this->assertNotNull($retrievedConfig);
		$this->assertEquals('user@example.com', $retrievedConfig->getEmail());
		$this->assertEquals('imap.example.com', $retrievedConfig->getImap()->getHost());
		$this->assertEquals('smtp.example.com', $retrievedConfig->getSmtp()->getHost());
	}

	public function testEmailMismatchWithEmptyStrings(): void {
		$result = ConflictResolutionResult::emailMismatch('', '');

		$this->assertTrue($result->hasEmailMismatch());
		$this->assertEquals('', $result->getExpectedEmail());
		$this->assertEquals('', $result->getExistingEmail());
	}

	public function testMultipleInstancesAreIndependent(): void {
		$result1 = ConflictResolutionResult::retry($this->accountConfig);
		$result2 = ConflictResolutionResult::noExistingAccount();
		$result3 = ConflictResolutionResult::emailMismatch('a@test.com', 'b@test.com');

		// Each instance should maintain its own state
		$this->assertTrue($result1->canRetry());
		$this->assertFalse($result2->canRetry());
		$this->assertFalse($result3->canRetry());

		$this->assertNotNull($result1->getAccountConfig());
		$this->assertNull($result2->getAccountConfig());
		$this->assertNull($result3->getAccountConfig());

		$this->assertFalse($result1->hasEmailMismatch());
		$this->assertFalse($result2->hasEmailMismatch());
		$this->assertTrue($result3->hasEmailMismatch());
	}
}

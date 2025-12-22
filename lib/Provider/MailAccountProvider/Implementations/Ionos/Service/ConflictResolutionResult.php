<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Mail\Provider\MailAccountProvider\Implementations\Ionos\Service;

use OCA\Mail\Provider\MailAccountProvider\Common\Dto\MailAccountConfig;

/**
 * Result of conflict resolution when IONOS account creation fails
 */
class ConflictResolutionResult {

	private function __construct(
		private readonly bool $canRetry,
		private readonly ?MailAccountConfig $accountConfig,
		private readonly ?string $expectedEmail,
		private readonly ?string $existingEmail,
	) {
	}

	/**
	 * Create result indicating account creation can be retried with existing config
	 */
	public static function retry(MailAccountConfig $config): self {
		return new self(
			canRetry: true,
			accountConfig: $config,
			expectedEmail: null,
			existingEmail: null,
		);
	}

	/**
	 * Create result indicating no existing account was found
	 */
	public static function noExistingAccount(): self {
		return new self(
			canRetry: false,
			accountConfig: null,
			expectedEmail: null,
			existingEmail: null,
		);
	}

	/**
	 * Create result indicating email mismatch between expected and existing account
	 */
	public static function emailMismatch(string $expectedEmail, string $existingEmail): self {
		return new self(
			canRetry: false,
			accountConfig: null,
			expectedEmail: $expectedEmail,
			existingEmail: $existingEmail,
		);
	}

	public function canRetry(): bool {
		return $this->canRetry;
	}

	public function getAccountConfig(): ?MailAccountConfig {
		return $this->accountConfig;
	}

	public function hasEmailMismatch(): bool {
		return $this->expectedEmail !== null && $this->existingEmail !== null;
	}

	public function getExpectedEmail(): ?string {
		return $this->expectedEmail;
	}

	public function getExistingEmail(): ?string {
		return $this->existingEmail;
	}
}

<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider;

/**
 * Base implementation of provider capabilities
 *
 * Providers can extend this class and override methods as needed
 */
class ProviderCapabilities implements IProviderCapabilities {
	public function __construct(
		private bool $multipleAccounts = false,
		private bool $appPasswords = false,
		private bool $passwordReset = false,
		private array $configSchema = [],
		private array $creationParameterSchema = [],
	) {
	}

	public function allowsMultipleAccounts(): bool {
		return $this->multipleAccounts;
	}

	public function supportsAppPasswords(): bool {
		return $this->appPasswords;
	}

	public function supportsPasswordReset(): bool {
		return $this->passwordReset;
	}

	public function getConfigSchema(): array {
		return $this->configSchema;
	}

	public function getCreationParameterSchema(): array {
		return $this->creationParameterSchema;
	}
}

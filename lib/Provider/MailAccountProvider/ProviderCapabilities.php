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
		private array $configSchema = [],
		private array $creationParameterSchema = [],
		private ?string $emailDomain = null,
	) {
	}

	public function allowsMultipleAccounts(): bool {
		return $this->multipleAccounts;
	}

	public function getConfigSchema(): array {
		return $this->configSchema;
	}

	public function getCreationParameterSchema(): array {
		return $this->creationParameterSchema;
	}

	public function getEmailDomain(): ?string {
		return $this->emailDomain;
	}
}

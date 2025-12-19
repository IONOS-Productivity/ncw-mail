<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider;

/**
 * Interface for defining provider capabilities
 *
 * Providers use this to declare what features they support
 */
interface IProviderCapabilities {
	/**
	 * Check if the provider allows multiple accounts per user
	 *
	 * @return bool True if multiple accounts are allowed
	 */
	public function allowsMultipleAccounts(): bool;

	/**
	 * Check if the provider supports app password generation
	 *
	 * @return bool True if app passwords can be generated
	 */
	public function supportsAppPasswords(): bool;

	/**
	 * Check if the provider supports password reset
	 *
	 * @return bool True if passwords can be reset
	 */
	public function supportsPasswordReset(): bool;

	/**
	 * Get the configuration schema for this provider
	 *
	 * Returns an array describing the configuration fields needed
	 * for this provider to work.
	 *
	 * @return array<string, array{type: string, required: bool, description: string}> Config schema
	 */
	public function getConfigSchema(): array;

	/**
	 * Get the parameter schema for account creation
	 *
	 * Returns an array describing what parameters are needed
	 * when creating an account (e.g., username, domain).
	 *
	 * @return array<string, array{type: string, required: bool, description: string, default?: mixed}> Parameter schema
	 */
	public function getCreationParameterSchema(): array;

	/**
	 * Get the email domain for this provider (if applicable)
	 *
	 * Returns the domain suffix used for email addresses created by this provider.
	 * For example, "example.com" for accounts like "user@example.com"
	 *
	 * @return string|null The email domain or null if not applicable
	 */
	public function getEmailDomain(): ?string;
}

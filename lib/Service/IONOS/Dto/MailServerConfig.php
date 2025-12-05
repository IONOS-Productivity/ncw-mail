<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Service\IONOS\Dto;

/**
 * Data transfer object for mail server configuration (IMAP/SMTP)
 */
class MailServerConfig {
	public function __construct(
		private readonly string $host,
		private readonly int $port,
		private readonly string $security,
		private readonly string $username,
		private readonly string $password,
	) {
	}

	public function getHost(): string {
		return $this->host;
	}

	public function getPort(): int {
		return $this->port;
	}

	public function getSecurity(): string {
		return $this->security;
	}

	public function getUsername(): string {
		return $this->username;
	}

	public function getPassword(): string {
		return $this->password;
	}

	/**
	 * Create a new instance with a different password
	 *
	 * @param string $newPassword The new password to use
	 * @return self New instance with updated password
	 */
	public function withPassword(string $newPassword): self {
		return new self(
			host: $this->host,
			port: $this->port,
			security: $this->security,
			username: $this->username,
			password: $newPassword,
		);
	}

	/**
	 * Convert to array format for backwards compatibility
	 *
	 * @return array{host: string, port: int, security: string, username: string, password: string}
	 */
	public function toArray(): array {
		return [
			'host' => $this->host,
			'port' => $this->port,
			'security' => $this->security,
			'username' => $this->username,
			'password' => $this->password,
		];
	}
}

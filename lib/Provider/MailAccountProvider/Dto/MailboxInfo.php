<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Provider\MailAccountProvider\Dto;

/**
 * Data transfer object for mailbox information
 *
 * Contains mailbox details along with user and mail app account status
 */
class MailboxInfo {
	public function __construct(
		public readonly string $userId,
		public readonly string $email,
		public readonly bool $userExists,
		public readonly ?int $mailAppAccountId,
		public readonly ?string $mailAppAccountName,
		public readonly bool $mailAppAccountExists,
		public readonly ?string $userName = null,
	) {
	}

	/**
	 * Convert to array representation
	 *
	 * @return array{
	 *     userId: string,
	 *     email: string,
	 *     userExists: bool,
	 *     mailAppAccountId: int|null,
	 *     mailAppAccountName: string|null,
	 *     mailAppAccountExists: bool,
	 *     userName: string|null
	 * }
	 */
	public function toArray(): array {
		return [
			'userId' => $this->userId,
			'email' => $this->email,
			'userExists' => $this->userExists,
			'mailAppAccountId' => $this->mailAppAccountId,
			'mailAppAccountName' => $this->mailAppAccountName,
			'mailAppAccountExists' => $this->mailAppAccountExists,
			'userName' => $this->userName,
		];
	}

	/**
	 * Create a new instance with updated user name
	 *
	 * @param string|null $userName The user's display name
	 * @return self New instance with updated user name
	 */
	public function withUserName(?string $userName): self {
		return new self(
			$this->userId,
			$this->email,
			$this->userExists,
			$this->mailAppAccountId,
			$this->mailAppAccountName,
			$this->mailAppAccountExists,
			$userName,
		);
	}

	/**
	 * Create a new instance with updated mail app account name
	 *
	 * @param string|null $mailAppAccountName The mail app account display name
	 * @return self New instance with updated mail app account name
	 */
	public function withMailAppAccountName(?string $mailAppAccountName): self {
		return new self(
			$this->userId,
			$this->email,
			$this->userExists,
			$this->mailAppAccountId,
			$mailAppAccountName,
			$this->mailAppAccountExists,
			$this->userName,
		);
	}
}

<?php

declare(strict_types=1);
/**
 * SPDX-FileCopyrightText: 2024 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */
namespace OCA\Mail\Send;

use Horde_Imap_Client_Socket;
use OCA\Mail\Account;
use OCA\Mail\Db\LocalMessage;
use OCA\Mail\Exception\SentMailboxNotSetException;
use Psr\Log\LoggerInterface;

class SentMailboxHandler extends AHandler {
	public function __construct(
		private LoggerInterface $logger,
	) {
	}

	#[\Override]
	public function process(
		Account $account,
		LocalMessage $localMessage,
		Horde_Imap_Client_Socket $client,
	): LocalMessage {
		if ($account->getMailAccount()->getSentMailboxId() === null) {
			$this->logger->warning('No sent mailbox configured for account', [
				'accountId' => $account->getId(),
				'userId' => $account->getUserId(),
			]);
			throw new SentMailboxNotSetException();
		}
		return $this->processNext($account, $localMessage, $client);
	}
}

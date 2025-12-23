<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2020 Nextcloud GmbH and Nextcloud contributors
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Listener;

use OCA\Mail\Exception\ClientException;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCA\Mail\Service\AccountService;
use OCA\Mail\Service\IONOS\IonosMailService;
use OCP\EventDispatcher\Event;
use OCP\EventDispatcher\IEventListener;
use OCP\User\Events\UserDeletedEvent;
use Psr\Log\LoggerInterface;

/**
 * @template-implements IEventListener<Event|UserDeletedEvent>
 */
class UserDeletedListener implements IEventListener {
	/** @var AccountService */
	private $accountService;

	/** @var LoggerInterface */
	private $logger;

	public function __construct(
		AccountService $accountService,
		LoggerInterface $logger,
		private readonly IonosMailService $ionosMailService,
		private readonly ProviderRegistryService $providerRegistry,
	) {
		$this->accountService = $accountService;
		$this->logger = $logger;
	}

	#[\Override]
	public function handle(Event $event): void {
		if (!($event instanceof UserDeletedEvent)) {
			// Unrelated
			return;
		}

		$user = $event->getUser();
		$userId = $user->getUID();

		$accounts = $this->accountService->findByUserId($userId);

		// Delete provider-managed accounts (generic system)
		// This works with any registered provider (IONOS, Office365, etc.)
		$this->providerRegistry->deleteProviderManagedAccounts($userId, $accounts);

		// Delete IONOS mailbox if IONOS integration is enabled
		$this->ionosMailService->tryDeleteEmailAccount($userId);

		// Delete all mail accounts in Nextcloud
		foreach ($accounts as $account) {
			try {
				$this->accountService->delete(
					$userId,
					$account->getId()
				);
			} catch (ClientException $e) {
				$this->logger->error('Could not delete user\'s Mail account: ' . $e->getMessage(), [
					'exception' => $e,
				]);
			}
		}
	}
}

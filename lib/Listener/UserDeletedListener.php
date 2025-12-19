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

		// Delete provider-managed accounts (generic system)
		// This works with any registered provider (IONOS, Office365, etc.)
		foreach ($this->accountService->findByUserId($userId) as $account) {
			$email = $account->getEmail();

			// Check if this account is managed by a provider
			$provider = $this->providerRegistry->findProviderForEmail($userId, $email);
			if ($provider !== null) {
				try {
					$this->logger->info('Deleting provider-managed account', [
						'provider' => $provider->getId(),
						'userId' => $userId,
						'email' => $email,
					]);

					$provider->deleteAccount($userId, $email);

					$this->logger->info('Successfully deleted provider-managed account', [
						'provider' => $provider->getId(),
						'userId' => $userId,
						'email' => $email,
					]);
				} catch (\Exception $e) {
					$this->logger->error('Failed to delete provider-managed account', [
						'provider' => $provider->getId(),
						'userId' => $userId,
						'email' => $email,
						'exception' => $e,
					]);
					// Continue with other accounts even if one fails
				}
			}

			// Delete the Nextcloud mail account
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

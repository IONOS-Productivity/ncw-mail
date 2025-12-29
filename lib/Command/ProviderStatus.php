<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Command;

use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCA\Mail\Service\AccountService;
use OCP\IUserManager;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command to check provider status and availability
 */
final class ProviderStatus extends ProviderCommandBase {
	public const ARGUMENT_PROVIDER_ID = 'provider-id';
	public const ARGUMENT_USER_ID = 'user-id';

	public function __construct(
		ProviderRegistryService $providerRegistry,
		IUserManager $userManager,
		private AccountService $accountService,
	) {
		parent::__construct($providerRegistry, $userManager);
	}

	protected function configure(): void {
		$this->setName('mail:provider:status');
		$this->setDescription('Check the status and availability of a mail account provider (use -v for detailed information)');
		$this->addArgument(
			self::ARGUMENT_PROVIDER_ID,
			InputArgument::REQUIRED,
			'Provider ID (e.g., "ionos")'
		);
		$this->addArgument(
			self::ARGUMENT_USER_ID,
			InputArgument::OPTIONAL,
			'User ID to check provider availability for specific user'
		);
		$this->addOutputOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$providerId = $input->getArgument(self::ARGUMENT_PROVIDER_ID);
		$userId = $input->getArgument(self::ARGUMENT_USER_ID);
		$verbose = $output->isVerbose();
		$outputFormat = $this->getOutputFormat($input, $output);

		$provider = $this->getProviderOrFail($providerId, $output);
		if ($provider === null) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => sprintf('Provider "%s" not found', $providerId)], $outputFormat, $output);
			}
			return 1;
		}

		if ($userId !== null && !$this->validateUserExists($userId, $output)) {
			if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
				$this->outputData(['error' => sprintf('User "%s" not found', $userId)], $outputFormat, $output);
			}
			return 1;
		}

		// JSON output
		if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
			$data = $this->formatStatusData($provider, $userId, $verbose);
			$this->outputData($data, $outputFormat, $output);
			return 0;
		}

		// Plain text output
		$this->displayProviderBasicInfo($provider, $output);

		if ($verbose) {
			$this->displayProviderCapabilities($provider, $output);
		}

		if ($userId !== null) {
			$this->displayUserAvailability($provider, $userId, $output);
		}

		$output->writeln('');
		return 0;
	}

	/**
	 * Display basic provider information
	 */
	protected function displayProviderBasicInfo(IMailAccountProvider $provider, OutputInterface $output): void {
		$output->writeln(sprintf('<info>Provider: %s (%s)</info>', $provider->getName(), $provider->getId()));
		$output->writeln('');

		$isEnabled = $provider->isEnabled();
		$output->writeln(sprintf(
			'Enabled: %s',
			$isEnabled ? '<info>Yes</info>' : '<error>No</error>'
		));

		if (!$isEnabled) {
			$output->writeln('<comment>Provider is not enabled. Check configuration.</comment>');
		}
	}

	/**
	 * Display provider capabilities
	 */
	protected function displayProviderCapabilities(IMailAccountProvider $provider, OutputInterface $output): void {
		$capabilities = $provider->getCapabilities();
		$output->writeln('');
		$output->writeln('<comment>Capabilities:</comment>');
		$output->writeln(sprintf('  Multiple Accounts: %s', $capabilities->allowsMultipleAccounts() ? 'Yes' : 'No'));
		$output->writeln(sprintf('  App Passwords: %s', $capabilities->supportsAppPasswords() ? 'Yes' : 'No'));
		$output->writeln(sprintf('  Password Reset: %s', $capabilities->supportsPasswordReset() ? 'Yes' : 'No'));
		$output->writeln(sprintf('  Email Domain: %s', $capabilities->getEmailDomain() ?? 'N/A'));
	}

	/**
	 * Display provider availability for a specific user
	 */
	protected function displayUserAvailability(
		IMailAccountProvider $provider,
		string $userId,
		OutputInterface $output,
	): void {
		$output->writeln('');
		$output->writeln(sprintf('<info>User: %s</info>', $userId));

		$existingEmail = $provider->getExistingAccountEmail($userId);
		$provisionedEmail = $provider->getProvisionedEmail($userId);
		$accountId = $this->getExistingAccountId($userId, $existingEmail);

		// User is available if no existing account is blocking them
		$canCreateAccount = $existingEmail === null;

		$output->writeln(sprintf(
			'Can Create Account: %s',
			$canCreateAccount ? '<info>Yes</info>' : '<comment>No</comment>'
		));

		$output->writeln(sprintf(
			'Existing Nextcloud Account: %s',
			$existingEmail !== null ? sprintf('<info>%s (ID: %d)</info>', $existingEmail, $accountId) : '<comment>None</comment>'
		));

		$output->writeln(sprintf(
			'Provisioned Provider Account: %s',
			$provisionedEmail !== null ? sprintf('<info>%s</info>', $provisionedEmail) : '<comment>None</comment>'
		));

		if ($existingEmail !== null) {
			$output->writeln('');
			$output->writeln('<comment>Note: User already has an account configured in Nextcloud.</comment>');
			$output->writeln('<comment>To create a new provider account, delete the existing account first.</comment>');
		} elseif ($provisionedEmail !== null) {
			$output->writeln('');
			$output->writeln('<comment>Note: Account is already provisioned with the provider.</comment>');
		}
	}

	/**
	 * Format provider status data for JSON output
	 */
	protected function formatStatusData(
		IMailAccountProvider $provider,
		?string $userId,
		bool $verbose,
	): array {
		$capabilities = $provider->getCapabilities();

		$data = [
			'provider' => [
				'id' => $provider->getId(),
				'name' => $provider->getName(),
				'enabled' => $provider->isEnabled(),
			],
		];

		if ($verbose) {
			$data['capabilities'] = [
				'multipleAccounts' => $capabilities->allowsMultipleAccounts(),
				'appPasswords' => $capabilities->supportsAppPasswords(),
				'passwordReset' => $capabilities->supportsPasswordReset(),
				'emailDomain' => $capabilities->getEmailDomain(),
			];
		}

		if ($userId !== null) {
			$existingEmail = $provider->getExistingAccountEmail($userId);
			$provisionedEmail = $provider->getProvisionedEmail($userId);
			$accountId = $this->getExistingAccountId($userId, $existingEmail);
			$canCreateAccount = $existingEmail === null;

			$data['user'] = [
				'id' => $userId,
				'canCreateAccount' => $canCreateAccount,
				'existingNextcloudAccount' => $existingEmail,
				'existingNextcloudAccountId' => $accountId,
				'provisionedProviderAccount' => $provisionedEmail,
			];
		}

		return $data;
	}

	/**
	 * Get the account ID for an existing account
	 *
	 * @param string $userId The user ID
	 * @param string|null $email The email address
	 * @return int|null The account ID or null if no account exists
	 */
	private function getExistingAccountId(string $userId, ?string $email): ?int {
		if ($email === null) {
			return null;
		}

		try {
			$accounts = $this->accountService->findByUserIdAndAddress($userId, $email);
			if (count($accounts) > 0) {
				return $accounts[0]->getId();
			}
		} catch (\Exception $e) {
			// If we can't find the account, just return null
			return null;
		}

		return null;
	}
}

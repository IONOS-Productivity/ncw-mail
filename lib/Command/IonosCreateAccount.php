<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Command;

use OCA\Mail\Exception\ServiceException;
use OCA\Mail\Service\IONOS\IonosAccountCreationService;
use OCA\Mail\Service\IONOS\IonosConfigService;
use OCP\IUserManager;
use Psr\Log\LoggerInterface;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

final class IonosCreateAccount extends Command {
	public const ARGUMENT_USER_ID = 'user-id';
	public const ARGUMENT_EMAIL_USER = 'email-user';
	public const OPTION_NAME = 'name';
	public const OPTION_OUTPUT = 'output';

	public function __construct(
		private IonosAccountCreationService $accountCreationService,
		private IUserManager $userManager,
		private IonosConfigService $configService,
		private LoggerInterface $logger,
	) {
		parent::__construct();
	}

	/**
	 * @return void
	 */
	protected function configure() {
		$this->setName('mail:ionos:create');
		$this->setDescription('Creates IONOS mail account and configure it in Nextcloud');
		$this->addArgument(self::ARGUMENT_USER_ID, InputArgument::REQUIRED, 'User ID');
		$this->addArgument(self::ARGUMENT_EMAIL_USER, InputArgument::REQUIRED, 'IONOS Email user. (The local part of the email address before @domain)');
		$this->addOption(self::OPTION_NAME, '', InputOption::VALUE_REQUIRED, 'Account name');
		$this->addOption(self::OPTION_OUTPUT, '', InputOption::VALUE_OPTIONAL, 'Output format (json, json_pretty)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$userId = $input->getArgument(self::ARGUMENT_USER_ID);
		$emailUser = $input->getArgument(self::ARGUMENT_EMAIL_USER);
		$name = $input->getOption(self::OPTION_NAME);
		$outputFormat = $input->getOption(self::OPTION_OUTPUT);
		$isJsonOutput = in_array($outputFormat, ['json', 'json_pretty'], true);

		// Preflight checks
		if (!$isJsonOutput) {
			$output->writeln('<info>Running preflight checks...</info>');
		}

		// Check if IONOS integration is enabled and configured
		if (!$this->configService->isIonosIntegrationEnabled()) {
			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'IONOS integration is not enabled or not properly configured',
					'details' => [
						'ionos-mailconfig-enabled' => 'must be set to "yes"',
						'ionos_mailconfig_api_base_url' => 'must be configured',
						'ionos_mailconfig_api_auth_user' => 'must be configured',
						'ionos_mailconfig_api_auth_pass' => 'must be configured',
						'ncw.ext_ref' => 'must be configured in system config',
					]
				], $outputFormat);
			} else {
				$output->writeln('<error>IONOS integration is not enabled or not properly configured</error>');
				$output->writeln('<comment>Please verify the following configuration:</comment>');
				$output->writeln('  - ionos-mailconfig-enabled is set to "yes"');
				$output->writeln('  - ionos_mailconfig_api_base_url is configured');
				$output->writeln('  - ionos_mailconfig_api_auth_user is configured');
				$output->writeln('  - ionos_mailconfig_api_auth_pass is configured');
				$output->writeln('  - ncw.ext_ref is configured in system config');
			}
			return 1;
		}

		// Get and display the mail domain
		$mailDomain = $this->configService->getMailDomain();
		if ($mailDomain === '' || $mailDomain === null) {
			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'Mail domain could not be determined',
					'details' => 'Please verify ncw.customerDomain is configured in system config'
				], $outputFormat);
			} else {
				$output->writeln('<error>Mail domain could not be determined</error>');
				$output->writeln('<comment>Please verify ncw.customerDomain is configured in system config</comment>');
			}
			return 1;
		}

		if (!$isJsonOutput) {
			$output->writeln('<info>✓ IONOS API is properly configured</info>');
			$output->writeln('<info>✓ Mail domain: ' . $mailDomain . '</info>');
			$output->writeln('');
		}

		if (!$this->userManager->userExists($userId)) {
			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'User does not exist',
					'userId' => $userId
				], $outputFormat);
			} else {
				$output->writeln("<error>User $userId does not exist</error>");
			}
			return 1;
		}

		if (!$isJsonOutput) {
			$output->writeln('Creating IONOS mail account...');
			$output->writeln('  user-id: ' . $userId);
			$output->writeln('  name: ' . $name);
			$output->writeln('  email-user: ' . $emailUser);
			$output->writeln('  full-email: ' . $emailUser . '@' . $mailDomain);
		}

		try {
			$this->logger->info('Starting IONOS email account creation from CLI', [
				'userId' => $userId,
				'emailUser' => $emailUser,
				'accountName' => $name
			]);

			// Use the shared account creation service
			$account = $this->accountCreationService->createOrUpdateAccount($userId, $emailUser, $name);

			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => true,
					'account' => [
						'id' => $account->getId(),
						'userId' => $account->getUserId(),
						'name' => $account->getName(),
						'email' => $account->getEmail(),
						'inbound' => [
							'host' => $account->getMailAccount()->getInboundHost(),
							'port' => $account->getMailAccount()->getInboundPort(),
							'sslMode' => $account->getMailAccount()->getInboundSslMode(),
							'user' => $account->getMailAccount()->getInboundUser(),
						],
						'outbound' => [
							'host' => $account->getMailAccount()->getOutboundHost(),
							'port' => $account->getMailAccount()->getOutboundPort(),
							'sslMode' => $account->getMailAccount()->getOutboundSslMode(),
							'user' => $account->getMailAccount()->getOutboundUser(),
						]
					]
				], $outputFormat);
			} else {
				$output->writeln('<info>Account created successfully!</info>');
				$output->writeln('  Account ID: ' . $account->getId());
				$output->writeln('  Email: ' . $account->getEmail());
			}

			return 0;
		} catch (ServiceException $e) {
			$this->logger->error('IONOS service error: ' . $e->getMessage(), [
				'statusCode' => $e->getCode()
			]);

			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'IONOS service error',
					'message' => $e->getMessage(),
					'statusCode' => $e->getCode()
				], $outputFormat);
			} else {
				$output->writeln('<error>Failed to create IONOS account: ' . $e->getMessage() . '</error>');
			}
			return 1;
		} catch (\Exception $e) {
			$this->logger->error('Unexpected error creating account: ' . $e->getMessage());

			if ($isJsonOutput) {
				$this->outputJson($output, [
					'success' => false,
					'error' => 'Unexpected error',
					'message' => $e->getMessage()
				], $outputFormat);
			} else {
				$output->writeln('<error>Could not create account: ' . $e->getMessage() . '</error>');
			}
			return 1;
		}
	}

	/**
	 * Output data as JSON based on the specified format
	 */
	private function outputJson(OutputInterface $output, array $data, ?string $format): void {
		if ($format === 'json_pretty') {
			$output->writeln(json_encode($data, JSON_PRETTY_PRINT));
		} else {
			$output->writeln(json_encode($data));
		}
	}
}

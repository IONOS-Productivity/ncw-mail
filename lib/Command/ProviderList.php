<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Command;

use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Style\SymfonyStyle;

final class ProviderList extends Command {
	public const OPTION_OUTPUT = 'output';

	public function __construct(
		private ProviderRegistryService $providerRegistry,
	) {
		parent::__construct();
	}

	protected function configure(): void {
		$this->setName('mail:provider:list');
		$this->setDescription('List all registered mail account providers');
		$this->addOption(self::OPTION_OUTPUT, 'o', InputOption::VALUE_OPTIONAL, 'Output format (json, json_pretty)');
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$io = new SymfonyStyle($input, $output);
		$outputFormat = $input->getOption(self::OPTION_OUTPUT);
		$isJsonOutput = in_array($outputFormat, ['json', 'json_pretty'], true);

		$allProviders = $this->providerRegistry->getAllProviders();
		$enabledProviders = $this->providerRegistry->getEnabledProviders();

		if ($isJsonOutput) {
			$providersData = [];
			foreach ($allProviders as $provider) {
				$capabilities = $provider->getCapabilities();
				$providersData[] = [
					'id' => $provider->getId(),
					'name' => $provider->getName(),
					'enabled' => $provider->isEnabled(),
					'capabilities' => [
						'multipleAccounts' => $capabilities->allowsMultipleAccounts(),
						'appPasswords' => $capabilities->supportsAppPasswords(),
						'passwordReset' => $capabilities->supportsPasswordReset(),
					],
					'parameterSchema' => $capabilities->getCreationParameterSchema(),
				];
			}

			$data = [
				'total' => count($allProviders),
				'enabled' => count($enabledProviders),
				'providers' => $providersData,
			];

			$options = $outputFormat === 'json_pretty' ? JSON_PRETTY_PRINT : 0;
			$output->writeln(json_encode($data, $options));
		} else {
			$io->title('Mail Account Providers');

			if (empty($allProviders)) {
				$io->warning('No providers registered');
				return 0;
			}

			$io->writeln(sprintf('Total providers: %d (%d enabled)', count($allProviders), count($enabledProviders)));
			$io->newLine();

			foreach ($allProviders as $provider) {
				$capabilities = $provider->getCapabilities();
				$status = $provider->isEnabled() ? '<info>✓ Enabled</info>' : '<comment>✗ Disabled</comment>';

				$io->section($provider->getName() . " ({$provider->getId()})");
				$io->writeln("Status: $status");
				$io->newLine();

				$io->writeln('<comment>Capabilities:</comment>');
				$io->listing([
					'Multiple Accounts: ' . ($capabilities->allowsMultipleAccounts() ? 'Yes' : 'No'),
					'App Passwords: ' . ($capabilities->supportsAppPasswords() ? 'Yes' : 'No'),
					'Password Reset: ' . ($capabilities->supportsPasswordReset() ? 'Yes' : 'No'),
				]);

				$schema = $capabilities->getCreationParameterSchema();
				if (!empty($schema)) {
					$io->writeln('<comment>Creation Parameters:</comment>');
					$paramsList = [];
					foreach ($schema as $paramName => $paramSchema) {
						$required = $paramSchema['required'] ? 'required' : 'optional';
						$paramsList[] = sprintf('%s (%s) - %s', $paramName, $required, $paramSchema['description']);
					}
					$io->listing($paramsList);
				}

				$io->newLine();
			}

			if (!empty($enabledProviders)) {
				$io->success('Providers are ready to use!');
				$io->note('Create an account with: occ mail:provider:create <provider-id> <user-id> --param key=value');
			}
		}

		return 0;
	}
}

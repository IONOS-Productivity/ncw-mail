<?php

declare(strict_types=1);

/**
 * SPDX-FileCopyrightText: 2025 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

namespace OCA\Mail\Command;

use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderRegistryService;
use OCP\IUserManager;
use Symfony\Component\Console\Helper\Table;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * occ command to list all registered mail account providers
 */
final class ProviderList extends ProviderCommandBase {
	public function __construct(
		ProviderRegistryService $providerRegistry,
		IUserManager $userManager,
	) {
		parent::__construct($providerRegistry, $userManager);
	}

	protected function configure(): void {
		$this->setName('mail:provider:list');
		$this->setDescription('List all registered mail account providers and their capabilities');
		$this->addOutputOption();
	}

	protected function execute(InputInterface $input, OutputInterface $output): int {
		$providers = $this->providerRegistry->getAllProviders();
		$outputFormat = $this->getOutputFormat($input, $output);

		if (empty($providers)) {
			if ($outputFormat === self::OUTPUT_FORMAT_PLAIN) {
				$output->writeln('<info>No mail account providers are registered.</info>');
			} else {
				$this->outputData(['providers' => []], $outputFormat, $output);
			}
			return 0;
		}

		if ($outputFormat !== self::OUTPUT_FORMAT_PLAIN) {
			$data = $this->formatProvidersData($providers);
			$this->outputData($data, $outputFormat, $output);
			return 0;
		}

		$output->writeln('<info>Registered Mail Account Providers:</info>');
		$output->writeln('');

		$this->renderProvidersTable($providers, $output);
		$output->writeln('');

		$this->displayProviderSchemas($providers, $output);

		return 0;
	}

	/**
	 * Format providers data for JSON output
	 *
	 * @param IMailAccountProvider[] $providers
	 * @return array<string, mixed>
	 */
	protected function formatProvidersData(array $providers): array {
		$data = [];
		foreach ($providers as $provider) {
			$capabilities = $provider->getCapabilities();
			$data[] = [
				'id' => $provider->getId(),
				'name' => $provider->getName(),
				'enabled' => $provider->isEnabled(),
				'capabilities' => [
					'multipleAccounts' => $capabilities->allowsMultipleAccounts(),
					'appPasswords' => $capabilities->supportsAppPasswords(),
					'passwordReset' => $capabilities->supportsPasswordReset(),
					'emailDomain' => $capabilities->getEmailDomain(),
				],
				'configSchema' => $capabilities->getConfigSchema(),
				'creationSchema' => $capabilities->getCreationParameterSchema(),
			];
		}
		return ['providers' => $data];
	}

	/**
	 * Render a table displaying providers and their capabilities
	 *
	 * @param IMailAccountProvider[] $providers
	 */
	protected function renderProvidersTable(array $providers, OutputInterface $output): void {
		$table = new Table($output);
		$table->setHeaders(['ID', 'Name', 'Enabled', 'Multiple Accounts', 'App Passwords', 'Password Reset', 'Email Domain']);

		foreach ($providers as $provider) {
			$capabilities = $provider->getCapabilities();
			$table->addRow([
				$provider->getId(),
				$provider->getName(),
				$provider->isEnabled() ? '<info>Yes</info>' : '<comment>No</comment>',
				$capabilities->allowsMultipleAccounts() ? 'Yes' : 'No',
				$capabilities->supportsAppPasswords() ? 'Yes' : 'No',
				$capabilities->supportsPasswordReset() ? 'Yes' : 'No',
				$capabilities->getEmailDomain() ?? '<comment>N/A</comment>',
			]);
		}

		$table->render();
	}

	/**
	 * Display configuration and creation parameter schemas for each provider
	 *
	 * @param IMailAccountProvider[] $providers
	 */
	protected function displayProviderSchemas(array $providers, OutputInterface $output): void {
		foreach ($providers as $provider) {
			$capabilities = $provider->getCapabilities();
			$configSchema = $capabilities->getConfigSchema();
			$creationSchema = $capabilities->getCreationParameterSchema();

			if (empty($configSchema) && empty($creationSchema)) {
				continue;
			}

			$output->writeln(sprintf('<comment>%s (%s):</comment>', $provider->getName(), $provider->getId()));

			if (!empty($configSchema)) {
				$output->writeln('  Configuration Parameters:');
				$this->displaySchema($configSchema, $output);
			}

			if (!empty($creationSchema)) {
				$output->writeln('  Account Creation Parameters:');
				$this->displaySchema($creationSchema, $output);
			}
			$output->writeln('');
		}
	}

	/**
	 * Display a parameter schema
	 *
	 * @param array<string, array{type?: string, required?: bool, description?: string}> $schema
	 */
	protected function displaySchema(array $schema, OutputInterface $output): void {
		foreach ($schema as $key => $schemaItem) {
			$required = $schemaItem['required'] ?? false;
			$type = $schemaItem['type'] ?? 'string';
			$description = $schemaItem['description'] ?? '';
			$output->writeln(sprintf(
				'    - %s (%s%s): %s',
				$key,
				$type,
				$required ? ', required' : '',
				$description
			));
		}
	}
}

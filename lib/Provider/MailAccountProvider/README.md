# Mail Account Provider System

This directory contains the pluggable mail account provider system for Nextcloud Mail.

## Overview

The provider system allows external mail services (like IONOS, Office365, Google Workspace, etc.) to provision mail accounts through their APIs and integrate seamlessly with Nextcloud Mail.

## Architecture

```
lib/Provider/MailAccountProvider/
├── IMailAccountProvider.php          # Main provider interface
├── IProviderCapabilities.php         # Capabilities interface
├── ProviderCapabilities.php          # Base capabilities implementation
├── ProviderRegistryService.php       # Central provider registry
└── Implementations/
    ├── IonosProvider.php             # IONOS implementation
    └── [Other providers...]
```

## Key Interfaces

### IMailAccountProvider

Main interface that all providers must implement:

- `getId()`: Unique provider identifier (e.g., 'ionos', 'office365')
- `getName()`: Human-readable name
- `getCapabilities()`: What features the provider supports
- `isEnabled()`: Is the provider configured and ready to use?
- `isAvailableForUser()`: Can this user create accounts with this provider?
- `createAccount()`: Provision a new mail account
- `updateAccount()`: Update existing account (e.g., reset password)
- `deleteAccount()`: Delete account from provider
- `managesEmail()`: Does this provider manage a specific email address?
- `getProvisionedEmail()`: What email did this provider provision for a user?

### IProviderCapabilities

Declares what features a provider supports:

- `allowsMultipleAccounts()`: Can a user have multiple accounts?
- `supportsAppPasswords()`: Can generate app-specific passwords?
- `supportsPasswordReset()`: Can reset account passwords?
- `getConfigSchema()`: What configuration fields are needed?
- `getCreationParameterSchema()`: What parameters are needed to create an account?

## Creating a New Provider

### 1. Create Provider Class

```php
<?php
namespace OCA\Mail\Provider\MailAccountProvider\Implementations;

use OCA\Mail\Account;
use OCA\Mail\Provider\MailAccountProvider\IMailAccountProvider;
use OCA\Mail\Provider\MailAccountProvider\ProviderCapabilities;

class MyProvider implements IMailAccountProvider {
    public function getId(): string {
        return 'myprovider';
    }

    public function getName(): string {
        return 'My Email Service';
    }

    public function getCapabilities(): IProviderCapabilities {
        return new ProviderCapabilities(
            multipleAccounts: true,
            appPasswords: false,
            passwordReset: true,
            configSchema: [
                'myprovider_api_url' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'API endpoint URL',
                ],
                'myprovider_api_key' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'API authentication key',
                ],
            ],
            creationParameterSchema: [
                'username' => [
                    'type' => 'string',
                    'required' => true,
                    'description' => 'Email username',
                ],
                'displayName' => [
                    'type' => 'string',
                    'required' => false,
                    'description' => 'User display name',
                    'default' => '',
                ],
            ],
        );
    }

    public function isEnabled(): bool {
        // Check if configuration is valid
        try {
            $apiUrl = $this->config->getAppValue('mail', 'myprovider_api_url');
            $apiKey = $this->config->getAppValue('mail', 'myprovider_api_key');
            return !empty($apiUrl) && !empty($apiKey);
        } catch (\Exception $e) {
            return false;
        }
    }

    public function isAvailableForUser(string $userId): bool {
        // Determine if user can create accounts
        // E.g., check if they already have one (if multipleAccounts=false)
        return true;
    }

    public function createAccount(string $userId, array $parameters): Account {
        // 1. Validate parameters
        $username = $parameters['username'] ?? '';
        if (empty($username)) {
            throw new \InvalidArgumentException('username is required');
        }

        // 2. Call external API to provision mailbox
        $mailConfig = $this->callProviderAPI($userId, $username);

        // 3. Create Nextcloud Mail account
        $account = new MailAccount();
        $account->setUserId($userId);
        $account->setEmail($mailConfig['email']);
        $account->setInboundHost($mailConfig['imap_host']);
        // ... set other properties ...

        return new Account($this->accountService->save($account));
    }

    // Implement other methods...
}
```

### 2. Register Provider

In `lib/AppInfo/Application.php`:

```php
public function boot(IBootContext $context): void {
    $container = $context->getServerContainer();
    $providerRegistry = $container->get(ProviderRegistryService::class);

    // Register your provider
    $myProvider = $container->get(MyProvider::class);
    $providerRegistry->registerProvider($myProvider);
}
```

### 3. Configure Provider

```bash
# Via occ command
occ config:app:set mail myprovider_api_url --value="https://api.example.com"
occ config:app:set mail myprovider_api_key --value="secret-key"

# Or via Admin UI (future enhancement)
```

### 4. Use Provider

Your provider will automatically:
- Appear in `GET /api/providers` if enabled
- Be usable via `POST /api/providers/myprovider/accounts`
- Work with generic CLI commands (when implemented)
- Show in UI provider selection (when implemented)

## API Endpoints

### Get Available Providers
```http
GET /apps/mail/api/providers
```

Returns list of providers available to current user with capabilities and parameter schemas.

### Create Account
```http
POST /apps/mail/api/providers/{providerId}/accounts
Content-Type: application/json

{
    "param1": "value1",
    "param2": "value2"
}
```

Creates a mail account using the specified provider with given parameters.

### Generate App Password
```http
POST /apps/mail/api/providers/{providerId}/password
Content-Type: application/json

{
    "accountId": 123
}
```

Generates an app-specific password (if provider supports it).

## Configuration Storage

Providers store configuration using standard Nextcloud mechanisms:

**App Config** (per-app settings):
```php
$this->appConfig->getValueString('mail', 'myprovider_setting');
$this->appConfig->setValueString('mail', 'myprovider_setting', $value);
```

**System Config** (global settings):
```php
$this->config->getSystemValue('myprovider.global_setting');
$this->config->setSystemValue('myprovider.global_setting', $value);
```

## Design Principles

1. **No Database Changes**: Account metadata derived at runtime
2. **Plug-and-Play**: Providers are self-contained
3. **Declarative**: Capabilities and schemas describe behavior
4. **Safe Defaults**: Errors don't break the app
5. **Backward Compatible**: Existing accounts unaffected

## Testing

When creating a provider, test:

1. **Configuration validation**: isEnabled() works correctly
2. **User availability**: isAvailableForUser() logic
3. **Account creation**: Full flow including API calls
4. **Account deletion**: Cleanup on provider side
5. **Error handling**: API failures, invalid parameters
6. **Email management**: managesEmail() correctly identifies accounts

## Examples

See `Implementations/IonosProvider.php` for a complete, production-ready example.

## Further Reading

- `PROVIDER_REFACTORING_GUIDE.md`: Architecture and implementation details
- `IMPLEMENTATION_SUMMARY.md`: Current status and next steps
- Core interfaces in this directory for full API documentation

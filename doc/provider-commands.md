# Provider OCC Commands

This document describes the new OCC commands for managing external mail account providers (e.g., IONOS Mail).

## Available Commands

### 1. `mail:provider:list`

List all registered mail account providers and their capabilities.

**Usage:**
```bash
php occ mail:provider:list
```

**Example Output:**
```
Registered Mail Account Providers:

+--------+------------+---------+-------------------+--------------+----------------+--------------+
| ID     | Name       | Enabled | Multiple Accounts | App Passwords| Password Reset | Email Domain |
+--------+------------+---------+-------------------+--------------+----------------+--------------+
| ionos  | IONOS Mail | Yes     | No                | Yes          | Yes            | example.com  |
+--------+------------+---------+-------------------+--------------+----------------+--------------+

IONOS Mail (ionos):
  Configuration Parameters:
    - ionos_mailconfig_api_base_url (string, required): Base URL for the IONOS Mail Configuration API
    - ionos_mailconfig_api_auth_user (string, required): Basic auth username for IONOS API
    - ionos_mailconfig_api_auth_pass (string, required): Basic auth password for IONOS API
    - ionos_mailconfig_api_allow_insecure (boolean): Allow insecure connections (for development)
    - ncw.ext_ref (string, required): External reference ID (system config)
    - ncw.customerDomain (string, required): Customer domain for email addresses (system config)
  Account Creation Parameters:
    - accountName (string, required): Name
    - emailUser (string, required): User
```

---

### 2. `mail:provider:status`

Check the status and availability of a mail account provider.

**Usage:**
```bash
php occ mail:provider:status <provider-id> [<user-id>] [--verbose|-v]
```

**Arguments:**
- `provider-id` (required): Provider ID (e.g., "ionos")
- `user-id` (optional): User ID to check provider availability for specific user

**Options:**
- `-v, --verbose`: Show detailed information including capabilities

**Examples:**

Check provider status:
```bash
php occ mail:provider:status ionos
```

Check if provider is available for a specific user:
```bash
php occ mail:provider:status ionos alice
```

With verbose output:
```bash
php occ mail:provider:status ionos alice -v
```

**Example Output:**
```
Provider: IONOS Mail (ionos)

Enabled: Yes

User: alice
Available for User: Yes
```

---

### 3. `mail:provider:create-account`

Create a mail account via an external provider.

**Usage:**
```bash
php occ mail:provider:create-account <provider-id> <user-id> -p <key>=<value> ...
```

**Arguments:**
- `provider-id` (required): Provider ID (e.g., "ionos")
- `user-id` (required): User ID to create the account for

**Options:**
- `-p, --param`: Parameters in key=value format (can be used multiple times)

**Example:**
```bash
php occ mail:provider:create-account ionos alice \
  -p emailUser=alice \
  -p accountName="Alice Smith"
```

**Example Output:**
```
Creating account for user "alice" via provider "ionos"...

Account created successfully!

Account ID: 42
Email: alice@example.com
Name: Alice Smith
IMAP Host: imap.example.com:993
SMTP Host: smtp.example.com:587
```

---

### 4. `mail:provider:generate-app-password`

Generate a new app password for a provider-managed mail account.

**Usage:**
```bash
php occ mail:provider:generate-app-password <provider-id> <user-id>
```

**Arguments:**
- `provider-id` (required): Provider ID (e.g., "ionos")
- `user-id` (required): User ID to generate app password for

**Example:**
```bash
php occ mail:provider:generate-app-password ionos alice
```

**Example Output:**
```
Generating app password for user "alice" (email: alice@example.com)...

App password generated successfully!

New App Password: AbCd1234EfGh5678IjKl

IMPORTANT: This password will only be shown once. Make sure to save it securely.
The mail account in Nextcloud has been automatically updated with the new password.
```

---

## Common Workflows

### Initial Setup

1. **List available providers:**
   ```bash
   php occ mail:provider:list
   ```

2. **Check provider configuration:**
   ```bash
   php occ mail:provider:status ionos -v
   ```

### Account Management

1. **Create a new account:**
   ```bash
   php occ mail:provider:create-account ionos alice \
     -p emailUser=alice \
     -p accountName="Alice Smith"
   ```

2. **Reset password (generate app password):**
   ```bash
   php occ mail:provider:generate-app-password ionos alice
   ```

### Troubleshooting

1. **Check if provider is available for user:**
   ```bash
   php occ mail:provider:status ionos alice
   ```

2. **Verify provider configuration:**
   ```bash
   php occ mail:provider:list
   ```

---

## Error Handling

The commands provide clear error messages:

- **Provider not found:** Lists available providers
- **User does not exist:** Validates user existence
- **Provider not enabled:** Suggests checking configuration
- **Provider not available for user:** Explains why (e.g., account already exists)
- **Missing required parameters:** Shows what parameters are needed with descriptions

---

## Testing

All commands include comprehensive unit tests:
- `tests/Unit/Command/ProviderListTest.php`
- `tests/Unit/Command/ProviderStatusTest.php`
- `tests/Unit/Command/ProviderCreateAccountTest.php`
- `tests/Unit/Command/ProviderGenerateAppPasswordTest.php`

Run tests:
```bash
vendor/bin/phpunit -c tests/phpunit.unit.xml tests/Unit/Command/Provider*
```

---

## Implementation Details

### Architecture

All provider commands follow the same pattern:
1. Validate inputs (provider ID, user ID, parameters)
2. Check provider status and availability
3. Execute the operation via `ProviderRegistryService`
4. Provide detailed feedback to the user

### Dependencies

Commands use:
- `ProviderRegistryService`: Access to registered providers
- `IUserManager`: User validation
- Symfony Console components for CLI interaction

### Code Location

- Commands: `lib/Command/Provider*.php`
- Tests: `tests/Unit/Command/Provider*Test.php`
- Registration: `appinfo/info.xml`

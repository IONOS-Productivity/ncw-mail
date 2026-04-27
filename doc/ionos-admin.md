<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->
# IONOS administration notes

Admin settings that exist only in the IONOS fork of the Mail app. Upstream admin
documentation lives in the Nextcloud admin manual.

### Disable alias creation

By default, users can create new mail aliases. Administrators can prevent this globally while still allowing users to view and delete their existing aliases.

```bash
# Disable alias creation for all users
occ config:app:set mail allow_new_mail_aliases --value=no

# Re-enable alias creation (default)
occ config:app:set mail allow_new_mail_aliases --value=yes
```

When disabled, the "Add alias" button is hidden in the user interface and API requests to create new aliases are rejected. Existing aliases remain visible and can be deleted.

The setting can also be toggled from the Mail section of the Nextcloud admin settings page.

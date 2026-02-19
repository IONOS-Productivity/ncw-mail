<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="row"
		:class="{ 'row--editing': editing }"
		:data-cy-mailbox-row="mailbox.userId">
		<!-- Email Address -->
		<div class="row__cell row__cell--email"
			data-cy-mailbox-list-cell-email>
			<!-- View Mode: Show full email -->
			<strong v-if="!editing"
				:title="mailbox.email.length > 30 ? mailbox.email : null"
				class="email-address">
				{{ mailbox.email }}
			</strong>

			<!-- Edit Mode: Show localpart input with domain suffix -->
			<NcTextField v-else
				ref="localpartField"
				class="cell-field"
				:value.sync="editedLocalpart"
				:label="t('mail', 'Email username')"
				:disabled="loading"
				autocomplete="off"
				spellcheck="false"
				@keydown.enter="saveChanges">
				<template #helper-text>
					<span class="domain-hint">{{ emailDomain }}</span>
				</template>
			</NcTextField>
		</div>

		<!-- Display Name -->
		<div class="row__cell row__cell--displayname"
			data-cy-mailbox-list-cell-displayname>
			<template v-if="mailbox.mailAppAccountExists">
				<!-- View Mode -->
				<span v-if="!editing"
					:title="(mailbox.mailAppAccountName || '').length > 25 ? mailbox.mailAppAccountName : null">
					{{ mailbox.mailAppAccountName || t('mail', 'No name') }}
				</span>

				<!-- Edit Mode -->
				<NcTextField v-else
					ref="displayNameField"
					class="cell-field"
					:value.sync="editedDisplayName"
					:label="t('mail', 'Display name')"
					:disabled="loading"
					autocomplete="off"
					spellcheck="false"
					@keydown.enter="saveChanges" />
			</template>
		</div>

		<!-- Linked User -->
		<div class="row__cell row__cell--linked-user"
			data-cy-mailbox-list-cell-linked-user>
			<div class="user-info">
				<!-- Avatar if user exists, placeholder icon if deleted -->
				<NcAvatar v-if="mailbox.userExists"
					:user="mailbox.userId"
					:size="32"
					:display-name="mailbox.userName || mailbox.userId"
					disable-menu
					:show-user-status="false" />
				<div v-else class="user-icon-placeholder">
					<IconAccountOff :size="32" />
				</div>

				<div class="user-details">
					<span v-if="mailbox.userName" class="user-display">
						{{ mailbox.userName }}
					</span>
					<span class="row__subtitle">{{ mailbox.userId }}</span>
				</div>
			</div>
		</div>

		<!-- Status -->
		<div class="row__cell row__cell--status"
			data-cy-mailbox-list-cell-status>
			<div class="status-indicators">
				<!-- User exists / deleted -->
				<div class="status-item" :class="userStatusClass">
					<component :is="userStatusIcon" :size="16" />
					<span class="status-label">{{ userStatusLabel }}</span>
				</div>

				<!-- Mail app account configured -->
				<div class="status-item" :class="accountStatusClass">
					<component :is="accountStatusIcon" :size="16" />
					<span class="status-label">{{ accountStatusLabel }}</span>
				</div>
			</div>
		</div>

		<!-- Actions -->
		<div class="row__cell row__cell--actions"
			data-cy-mailbox-list-cell-actions>
			<NcActions :inline="1">
				<!-- Edit/Save Button (only if user exists) -->
				<NcActionButton v-if="mailbox.userExists"
					:disabled="loading"
					@click="toggleEdit">
					<template #icon>
						<NcLoadingIcon v-if="loading" :size="20" />
						<IconCheck v-else-if="editing" :size="20" />
						<IconPencil v-else :size="20" />
					</template>
					{{ editing ? t('mail', 'Save') : t('mail', 'Edit') }}
				</NcActionButton>

				<!-- Delete Button (only in view mode) -->
				<NcActionButton v-if="!editing" @click="$emit('delete', mailbox)">
					<template #icon>
						<IconDelete :size="20" />
					</template>
					{{ t('mail', 'Delete') }}
				</NcActionButton>
			</NcActions>
		</div>
	</div>
</template>

<script>
import { NcAvatar, NcActions, NcActionButton, NcTextField, NcLoadingIcon } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconCheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import IconAlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import IconAccountOff from 'vue-material-design-icons/AccountOff.vue'
import IconEmailOff from 'vue-material-design-icons/EmailOff.vue'
import { updateMailbox } from '../../../service/ProviderMailboxService.js'

export default {
	name: 'ProviderMailboxListItem',
	components: {
		NcAvatar,
		NcActions,
		NcActionButton,
		NcLoadingIcon,
		NcTextField,
		IconPencil,
		IconDelete,
		IconCheck,
		IconCheckCircle,
		IconAlertCircle,
		IconAccountOff,
		IconEmailOff,
	},
	props: {
		mailbox: {
			type: Object,
			required: true,
		},
		providerId: {
			type: String,
			required: true,
		},
	},
	emits: ['delete', 'update'],
	data() {
		return {
			editing: false,
			editedLocalpart: this.extractLocalpart(this.mailbox.email),
			editedDisplayName: this.mailbox.mailAppAccountName || '',
			loading: false,
		}
	},
	computed: {
		emailDomain() {
			const atIndex = this.mailbox.email.indexOf('@')
			return atIndex >= 0 ? this.mailbox.email.substring(atIndex) : ''
		},
		localpartFromEmail() {
			const atIndex = this.mailbox.email.indexOf('@')
			return atIndex >= 0 ? this.mailbox.email.substring(0, atIndex) : this.mailbox.email
		},
		// User status
		userStatusIcon() {
			return this.mailbox.userExists ? 'IconCheckCircle' : 'IconAccountOff'
		},
		userStatusClass() {
			return this.mailbox.userExists ? 'status-ok' : 'status-error'
		},
		userStatusLabel() {
			return this.mailbox.userExists
				? this.t('mail', 'User exists')
				: this.t('mail', 'User deleted')
		},
		// Mail app account status
		accountStatusIcon() {
			return this.mailbox.mailAppAccountExists ? 'IconCheckCircle' : 'IconEmailOff'
		},
		accountStatusClass() {
			return this.mailbox.mailAppAccountExists ? 'status-ok' : 'status-warning'
		},
		accountStatusLabel() {
			return this.mailbox.mailAppAccountExists
				? this.t('mail', 'Mail configured')
				: this.t('mail', 'Not configured')
		},
	},
	methods: {
		extractLocalpart(email) {
			const atIndex = email.indexOf('@')
			return atIndex >= 0 ? email.substring(0, atIndex) : email
		},
		toggleEdit() {
			if (this.editing) {
				// Save changes
				this.saveChanges()
			} else {
				// Enter edit mode
				this.editing = true
				// Reset edited values when entering edit mode
				this.editedLocalpart = this.localpartFromEmail
				this.editedDisplayName = this.mailbox.mailAppAccountName || ''
				// Focus localpart field on next tick
				this.$nextTick(() => {
					this.$refs.localpartField?.$refs?.inputField?.$refs?.input?.focus()
				})
			}
		},
		async saveChanges() {
			if (this.loading) {
				return
			}

			// Prepare update data
			const data = {}
			let hasChanges = false

			// Check localpart changes
			const trimmedLocalpart = this.editedLocalpart.trim()
			if (trimmedLocalpart !== this.localpartFromEmail) {
				// Validate localpart
				if (trimmedLocalpart === '') {
					showError(this.t('mail', 'Email username cannot be empty'))
					return
				}
				if (!/^[a-zA-Z0-9._-]+$/.test(trimmedLocalpart)) {
					showError(this.t('mail', 'Email username contains invalid characters. Use only letters, numbers, dots, hyphens, and underscores.'))
					return
				}
				data.localpart = trimmedLocalpart
				hasChanges = true
			}

			// Check display name changes (only if mail app account exists)
			if (this.mailbox.mailAppAccountExists) {
				const trimmedDisplayName = this.editedDisplayName.trim()
				if (trimmedDisplayName !== (this.mailbox.mailAppAccountName || '')) {
					// Validate display name
					if (trimmedDisplayName === '') {
						showError(this.t('mail', 'Display name cannot be empty'))
						return
					}
					data.mailAppAccountName = trimmedDisplayName
					hasChanges = true
				}
			}

			// Exit if no changes
			if (!hasChanges) {
				showSuccess(this.t('mail', 'No changes to save'))
				this.editing = false
				return
			}

			this.loading = true
			try {
				const response = await updateMailbox(
					this.providerId,
					this.mailbox.userId,
					data,
				)

				showSuccess(this.t('mail', 'Mailbox updated successfully'))

				// Emit update event to parent with new mailbox data
				this.$emit('update', response.data)

				// Exit edit mode on success
				this.editing = false
			} catch (error) {
				console.error('Failed to update mailbox', error)

				// Extract error message from response
				let errorMsg = this.t('mail', 'Failed to update mailbox')
				if (error.response?.data?.data?.message) {
					errorMsg = error.response.data.data.message
				} else if (error.response?.status === 409) {
					errorMsg = this.t('mail', 'Email address already exists')
				}

				showError(errorMsg)
				// Revert to original values
				this.editedLocalpart = this.localpartFromEmail
				this.editedDisplayName = this.mailbox.mailAppAccountName || ''
			} finally {
				this.loading = false
			}
		},
	},
}
</script>

<style scoped lang="scss">
@use './shared/styles' as styles;

.row {
	border-bottom: 1px solid var(--color-border);
	transition: background-color 0.1s ease;

	&:last-child {
		border-bottom: none;
	}

	&:hover {
		background-color: var(--color-background-hover);

		// Keep sticky cells in sync with hover background
		.row__cell--email,
		.row__cell--actions {
			background-color: var(--color-background-hover);
		}
	}

	&--editing {
		background-color: var(--color-background-hover);

		.row__cell--email,
		.row__cell--actions {
			background-color: var(--color-background-hover);
		}
	}

	@include styles.row;
	@include styles.cell;

	// Row-specific cell overrides
	.row__cell {
		// Allow email cell to overflow for editing fields
		&--email {
			.email-address {
				font-family: monospace;
				font-size: 14px;
				font-weight: 600;
			}

			.domain-hint {
				color: var(--color-text-lighter);
				font-size: 12px;
				font-family: monospace;
			}

			.cell-field {
				width: 100%;
			}
		}

		&--linked-user {
			.user-info {
				display: flex;
				align-items: center;
				gap: 10px;

				.user-icon-placeholder {
					width: 32px;
					height: 32px;
					display: flex;
					align-items: center;
					justify-content: center;
					color: var(--color-text-lighter);
					flex-shrink: 0;
				}

				.user-details {
					display: flex;
					flex-direction: column;
					min-width: 0;

					.user-display {
						font-weight: 500;
						font-size: 14px;
						overflow: hidden;
						text-overflow: ellipsis;
						white-space: nowrap;
					}
				}
			}
		}

		&--status {
			.status-indicators {
				display: flex;
				flex-direction: column;
				gap: 4px;

				.status-item {
					display: flex;
					align-items: center;
					gap: 5px;
					font-size: 12px;

					&.status-ok {
						color: var(--color-success);
					}

					&.status-warning {
						color: var(--color-warning);
					}

					&.status-error {
						color: var(--color-error);
					}

					.status-label {
						color: var(--color-text-maxcontrast);
						font-size: 12px;
					}
				}
			}
		}
	}
}
</style>

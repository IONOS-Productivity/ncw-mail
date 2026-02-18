<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<tr class="mailbox-list-item" :class="{ 'editing': editing }">
		<td class="email-column">
			<!-- View Mode: Show full email -->
			<span v-if="!editing" class="email-address">{{ mailbox.email }}</span>

			<!-- Edit Mode: Show localpart input with domain suffix -->
			<template v-else>
				<NcTextField ref="localpartField"
					class="mailbox-field localpart-field"
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
			</template>
		</td>
		<td class="displayname-column">
			<!-- Show nothing if mail app account doesn't exist -->
			<div v-if="!mailbox.mailAppAccountExists" class="no-account">
				<!-- Empty cell -->
			</div>

			<!-- Show display name with editing if account exists -->
			<div v-else class="displayname-content">
				<!-- View Mode: Show name -->
				<span v-if="!editing" class="display-name">{{ mailbox.mailAppAccountName || t('mail', 'No name') }}</span>

				<!-- Edit Mode: Show name input -->
				<NcTextField v-else
					ref="displayNameField"
					class="mailbox-field displayname-field"
					:value.sync="editedDisplayName"
					:label="t('mail', 'Display name')"
					:disabled="loading"
					autocomplete="off"
					spellcheck="false"
					@keydown.enter="saveChanges" />
			</div>
		</td>
		<td class="user-column">
			<div class="user-info">
				<!-- Show avatar if user exists, otherwise show icon -->
				<NcAvatar v-if="mailbox.userExists"
					:user="mailbox.userId"
					:size="32"
					:display-name="mailbox.userName || mailbox.userId" />
				<div v-else class="user-icon-placeholder">
					<IconAccountOff :size="32" />
				</div>

				<div class="user-details">
					<!-- Display: userName (userId) -->
					<span v-if="mailbox.userName" class="user-display">
						{{ mailbox.userName }} <span class="user-id-inline">({{ mailbox.userId }})</span>
					</span>
					<span v-else class="user-display">{{ mailbox.userId }}</span>
				</div>
			</div>
		</td>
		<td v-if="debug" class="status-column">
			<div class="status-indicators">
				<!-- User exists status -->
				<div class="status-item" :class="userStatusClass">
					<component :is="userStatusIcon" :size="16" />
					<span class="status-label">{{ userStatusLabel }}</span>
				</div>

				<!-- Mail app account status -->
				<div class="status-item" :class="accountStatusClass">
					<component :is="accountStatusIcon" :size="16" />
					<span class="status-label">{{ accountStatusLabel }}</span>
				</div>
			</div>
		</td>
		<td class="actions-column">
			<div class="actions">
				<NcActions :inline="1">
					<!-- Edit/Save Button (only if user exists) -->
					<NcActionButton v-if="mailbox.userExists"
						:disabled="loading"
						@click="toggleEdit">
						<template #icon>
							<IconLoading v-if="loading" :size="20" />
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
		</td>
	</tr>
</template>

<script>
import { NcAvatar, NcActions, NcActionButton, NcTextField } from '@nextcloud/vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'
import IconCheck from 'vue-material-design-icons/Check.vue'
import IconCheckCircle from 'vue-material-design-icons/CheckCircle.vue'
import IconAlertCircle from 'vue-material-design-icons/AlertCircle.vue'
import IconAccountOff from 'vue-material-design-icons/AccountOff.vue'
import IconEmailOff from 'vue-material-design-icons/EmailOff.vue'
import IconLoading from 'vue-material-design-icons/Loading.vue'
import { updateMailbox } from '../../../service/ProviderMailboxService.js'

export default {
	name: 'ProviderMailboxListItem',
	components: {
		NcAvatar,
		NcActions,
		NcActionButton,
		NcTextField,
		IconPencil,
		IconDelete,
		IconCheck,
		IconCheckCircle,
		IconAlertCircle,
		IconAccountOff,
		IconEmailOff,
		IconLoading,
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
		debug: {
			type: Boolean,
			default: false,
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
.mailbox-list-item {
	td {
		padding: 12px;
		vertical-align: middle;
	}

	&.editing {
		background-color: var(--color-background-hover);
	}

	.email-column {
		.email-address {
			font-family: monospace;
			font-size: 14px;
		}

		.mailbox-field.localpart-field {
			width: 100%;
			max-width: 300px;

			:deep(.helper-text) {
				.domain-hint {
					color: var(--color-text-lighter);
					font-size: 12px;
					font-family: monospace;
				}
			}
		}
	}

	.user-column {
		.user-info {
			display: flex;
			align-items: center;
			gap: 12px;

			.user-icon-placeholder {
				width: 32px;
				height: 32px;
				display: flex;
				align-items: center;
				justify-content: center;
				color: var(--color-text-lighter);
			}

			.user-details {
				.user-display {
					font-weight: 500;
					font-size: 14px;

					.user-id-inline {
						font-weight: normal;
						color: var(--color-text-lighter);
						font-size: 13px;
					}
				}
			}
		}
	}

	.displayname-column {
		.displayname-content {
			.display-name {
				font-size: 14px;
			}

			.mailbox-field.displayname-field {
				width: 100%;
				max-width: 300px;
			}
		}

		.no-account {
			// Empty cell when no mail app account exists
		}
	}

	.status-column {
		.status-indicators {
			display: flex;
			flex-direction: column;
			gap: 8px;

			.status-item {
				display: flex;
				align-items: center;
				gap: 6px;
				font-size: 13px;

				&.status-ok {
					color: var(--color-success);

					.status-label {
						color: var(--color-text-lighter);
					}
				}

				&.status-warning {
					color: var(--color-warning);

					.status-label {
						color: var(--color-text-lighter);
					}
				}

				&.status-error {
					color: var(--color-error);

					.status-label {
						color: var(--color-text-lighter);
					}
				}

				.status-label {
					font-size: 12px;
				}
			}
		}
	}

	.actions-column {
		text-align: right;

		.actions {
			display: flex;
			justify-content: flex-end;
		}
	}
}
</style>

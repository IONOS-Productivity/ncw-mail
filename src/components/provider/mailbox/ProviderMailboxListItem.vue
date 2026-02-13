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
					:disabled="isLoadingField"
					:trailing-button-label="t('mail', 'Submit')"
					:show-trailing-button="true"
					trailing-button-icon="arrowRight"
					:class="{ 'icon-loading-small': loading.localpart }"
					:data-loading="loading.localpart || undefined"
					autocomplete="off"
					spellcheck="false"
					@trailing-button-click="updateLocalpart">
					<template #helper-text>
						<span class="domain-hint">{{ emailDomain }}</span>
					</template>
				</NcTextField>
			</template>
		</td>
		<td class="user-column">
			<div class="user-info">
				<NcAvatar :user="mailbox.userId"
					:size="32"
					:display-name="mailbox.name || mailbox.userId" />
				<div class="user-details">
					<!-- View Mode: Show name -->
					<span v-if="!editing" class="user-name">{{ mailbox.name || mailbox.userId }}</span>

					<!-- Edit Mode: Show name input -->
					<NcTextField v-else
						ref="nameField"
						class="mailbox-field name-field"
						:value.sync="editedName"
						:label="t('mail', 'Display name')"
						:disabled="isLoadingField"
						:trailing-button-label="t('mail', 'Submit')"
						:show-trailing-button="true"
						trailing-button-icon="arrowRight"
						:class="{ 'icon-loading-small': loading.name }"
						:data-loading="loading.name || undefined"
						autocomplete="off"
						spellcheck="false"
						@trailing-button-click="updateName" />

					<span class="user-id">{{ mailbox.userId }}</span>
				</div>
			</div>
		</td>
		<td class="actions-column">
			<div class="actions">
				<NcActions :inline="1">
					<!-- Edit/Done Toggle Button -->
					<NcActionButton :disabled="isLoadingField" @click="toggleEdit">
						<template #icon>
							<IconCheck v-if="editing" :size="20" />
							<IconPencil v-else :size="20" />
						</template>
						{{ editing ? t('mail', 'Done') : t('mail', 'Edit') }}
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
			editedName: this.mailbox.name || '',
			editedLocalpart: this.extractLocalpart(this.mailbox.email),
			loading: {
				name: false,
				localpart: false,
			},
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
		isLoadingField() {
			return this.loading.name || this.loading.localpart
		},
	},
	methods: {
		extractLocalpart(email) {
			const atIndex = email.indexOf('@')
			return atIndex >= 0 ? email.substring(0, atIndex) : email
		},
		toggleEdit() {
			this.editing = !this.editing
			if (this.editing) {
				// Reset edited values when entering edit mode
				this.editedName = this.mailbox.name || ''
				this.editedLocalpart = this.localpartFromEmail
				// Focus first field on next tick
				this.$nextTick(() => {
					this.$refs.nameField?.$refs?.inputField?.$refs?.input?.focus()
				})
			} else {
				// Reset values when canceling
				this.editedName = this.mailbox.name || ''
				this.editedLocalpart = this.localpartFromEmail
			}
		},
		async updateName() {
			// Trim whitespace
			const trimmedName = this.editedName.trim()

			// Validate: name cannot be empty
			if (trimmedName === '') {
				showError(this.t('mail', 'Display name cannot be empty'))
				return
			}

			// Skip if no change
			if (trimmedName === this.mailbox.name) {
				showSuccess(this.t('mail', 'Display name unchanged'))
				return
			}

			this.loading.name = true
			try {
				await this.updateMailboxField({ name: trimmedName })
				showSuccess(this.t('mail', 'Display name updated successfully'))
			} catch (error) {
				// Error already handled in updateMailboxField
				// Revert to original value
				this.editedName = this.mailbox.name || ''
			} finally {
				this.loading.name = false
			}
		},
		async updateLocalpart() {
			// Trim whitespace
			const trimmedLocalpart = this.editedLocalpart.trim()

			// Validate: localpart cannot be empty
			if (trimmedLocalpart === '') {
				showError(this.t('mail', 'Email username cannot be empty'))
				return
			}

			// Validate: localpart regex
			if (!/^[a-zA-Z0-9._-]+$/.test(trimmedLocalpart)) {
				showError(this.t('mail', 'Email username contains invalid characters. Use only letters, numbers, dots, hyphens, and underscores.'))
				return
			}

			// Skip if no change
			if (trimmedLocalpart === this.localpartFromEmail) {
				showSuccess(this.t('mail', 'Email username unchanged'))
				return
			}

			this.loading.localpart = true
			try {
				await this.updateMailboxField({ localpart: trimmedLocalpart })
				showSuccess(this.t('mail', 'Email address updated successfully'))
			} catch (error) {
				// Error already handled in updateMailboxField
				// Revert to original value
				this.editedLocalpart = this.localpartFromEmail
			} finally {
				this.loading.localpart = false
			}
		},
		async updateMailboxField(data) {
			try {
				const response = await updateMailbox(
					this.providerId,
					this.mailbox.userId,
					data,
				)

				// Emit update event to parent with new mailbox data
				this.$emit('update', response.data)
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
				throw error // Re-throw for caller to handle
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

			.user-details {
				display: flex;
				flex-direction: column;
				gap: 2px;

				.user-name {
					font-weight: 500;
					font-size: 14px;
				}

				.user-id {
					font-size: 12px;
					color: var(--color-text-lighter);
				}

				.mailbox-field.name-field {
					width: 100%;
					max-width: 300px;
					margin-bottom: 4px;
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

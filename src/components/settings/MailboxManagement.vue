<!--
  - SPDX-FileCopyrightText: 2026 IONOS SE
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mailbox-management">
		<h3>{{ t('mail', 'E-Mails verwalten') }}</h3>

		<div v-if="loading" class="loading">
			<NcLoadingIcon :size="32" />
			<p>{{ t('mail', 'Loading mailboxes...') }}</p>
		</div>

		<div v-else-if="error" class="error">
			<p>{{ error }}</p>
		</div>

		<div v-else-if="mailboxes.length === 0" class="empty">
			<p>{{ t('mail', 'No IONOS mailboxes found.') }}</p>
		</div>

		<div v-else class="mailbox-list">
			<div class="mailbox-list-header">
				<div class="column-email">
					{{ t('mail', 'E-Mail-Postfach') }}
				</div>
				<div class="column-user">
					{{ t('mail', 'Verknüpfter Benutzer') }}
				</div>
				<div class="column-actions">
					{{ t('mail', 'Actions') }}
				</div>
			</div>

			<MailboxListItem v-for="mailbox in mailboxes"
				:key="mailbox.userId"
				:mailbox="mailbox"
				@edit="onEdit"
				@delete="onDelete" />
		</div>

		<!-- Edit Modal -->
		<NcModal v-if="showEditModal"
			:name="t('mail', 'Edit Mailbox')"
			@close="closeEditModal">
			<div class="modal-content">
				<h2>{{ t('mail', 'Edit Email Address') }}</h2>
				<p>{{ t('mail', 'Change the local part of the email address (before @)') }}</p>

				<div class="form-group">
					<label for="localpart">{{ t('mail', 'Email User') }}</label>
					<div class="email-input-container">
						<input id="localpart"
							v-model="editLocalpart"
							type="text"
							:placeholder="t('mail', 'username')"
							@keyup.enter="saveEdit">
						<span class="email-domain">@{{ emailDomain }}</span>
					</div>
					<p v-if="editError" class="error-message">
						{{ editError }}
					</p>
				</div>

				<div class="modal-actions">
					<NcButton @click="closeEditModal">
						{{ t('mail', 'Cancel') }}
					</NcButton>
					<NcButton type="primary"
						:disabled="editSaving || !editLocalpart"
						@click="saveEdit">
						<template v-if="editSaving">
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('mail', 'Save') }}
					</NcButton>
				</div>
			</div>
		</NcModal>

		<!-- Delete Confirmation Dialog -->
		<NcModal v-if="showDeleteModal"
			:name="t('mail', 'Account deletion')"
			@close="closeDeleteModal">
			<div class="modal-content">
				<h2>{{ t('mail', 'Account deletion') }}</h2>
				<p>
					{{ t('mail', 'Fully delete {username}\'s mailbox including all their email data.', { username: deleteMailbox?.displayName || deleteMailbox?.username }) }}
				</p>
				<p class="warning">
					{{ t('mail', 'This action cannot be undone.') }}
				</p>

				<div class="modal-actions">
					<NcButton @click="closeDeleteModal">
						{{ t('mail', 'Cancel') }}
					</NcButton>
					<NcButton type="error"
						:disabled="deleteProcessing"
						@click="confirmDelete">
						<template v-if="deleteProcessing">
							<NcLoadingIcon :size="20" />
						</template>
						{{ t('mail', 'Delete {username}\'s mailbox', { username: deleteMailbox?.username }) }}
					</NcButton>
				</div>
			</div>
		</NcModal>
	</div>
</template>

<script>
import NcButton from '@nextcloud/vue/components/NcButton'
import NcLoadingIcon from '@nextcloud/vue/components/NcLoadingIcon'
import NcModal from '@nextcloud/vue/components/NcModal'
import MailboxListItem from './MailboxListItem.vue'
import { showError, showSuccess } from '@nextcloud/dialogs'
import axios from '@nextcloud/axios'
import { generateUrl } from '@nextcloud/router'
import logger from '../../logger.js'

export default {
	name: 'MailboxManagement',
	components: {
		NcButton,
		NcLoadingIcon,
		NcModal,
		MailboxListItem,
	},
	data() {
		return {
			loading: true,
			error: null,
			mailboxes: [],
			emailDomain: '',

			// Edit state
			showEditModal: false,
			editMailbox: null,
			editLocalpart: '',
			editError: null,
			editSaving: false,

			// Delete state
			showDeleteModal: false,
			deleteMailbox: null,
			deleteProcessing: false,
		}
	},
	mounted() {
		this.loadMailboxes()
	},
	methods: {
		async loadMailboxes() {
			this.loading = true
			this.error = null

			try {
				const url = generateUrl('/apps/mail/api/admin/mailboxes')
				const response = await axios.get(url)

				this.mailboxes = response.data.mailboxes || []

				// Extract domain from first mailbox if available
				if (this.mailboxes.length > 0) {
					const firstEmail = this.mailboxes[0].email
					const atIndex = firstEmail.indexOf('@')
					if (atIndex !== -1) {
						this.emailDomain = firstEmail.substring(atIndex + 1)
					}
				}

				logger.info('Loaded mailboxes', { count: this.mailboxes.length })
			} catch (error) {
				logger.error('Failed to load mailboxes', { error })
				this.error = this.t('mail', 'Failed to load mailboxes. Please try again.')
				showError(this.t('mail', 'Failed to load mailboxes'))
			} finally {
				this.loading = false
			}
		},

		onEdit(mailbox) {
			this.editMailbox = mailbox
			// Extract localpart from email
			const atIndex = mailbox.email.indexOf('@')
			this.editLocalpart = atIndex !== -1 ? mailbox.email.substring(0, atIndex) : ''
			this.editError = null
			this.showEditModal = true
		},

		closeEditModal() {
			this.showEditModal = false
			this.editMailbox = null
			this.editLocalpart = ''
			this.editError = null
			this.editSaving = false
		},

		async saveEdit() {
			if (!this.editLocalpart || this.editSaving) {
				return
			}

			this.editSaving = true
			this.editError = null

			try {
				const url = generateUrl('/apps/mail/api/admin/mailboxes/{userId}', {
					userId: this.editMailbox.userId,
				})

				const response = await axios.patch(url, {
					newLocalpart: this.editLocalpart,
				})

				if (response.data.success) {
					showSuccess(this.t('mail', 'Mailbox updated successfully'))
					this.closeEditModal()
					// Reload mailboxes to show updated email
					await this.loadMailboxes()
				} else {
					this.editError = response.data.error || this.t('mail', 'Failed to update mailbox')
				}
			} catch (error) {
				logger.error('Failed to update mailbox', { error })

				if (error.response?.data?.error) {
					this.editError = error.response.data.error
				} else {
					this.editError = this.t('mail', 'Failed to update mailbox. Please try again.')
					showError(this.t('mail', 'Failed to update mailbox'))
				}
			} finally {
				this.editSaving = false
			}
		},

		onDelete(mailbox) {
			this.deleteMailbox = mailbox
			this.showDeleteModal = true
		},

		closeDeleteModal() {
			this.showDeleteModal = false
			this.deleteMailbox = null
			this.deleteProcessing = false
		},

		async confirmDelete() {
			if (!this.deleteMailbox || this.deleteProcessing) {
				return
			}

			this.deleteProcessing = true

			try {
				const url = generateUrl('/apps/mail/api/admin/mailboxes/{userId}', {
					userId: this.deleteMailbox.userId,
				})

				await axios.delete(url)

				showSuccess(this.t('mail', 'Mailbox deleted successfully'))
				this.closeDeleteModal()
				// Reload mailboxes to remove deleted one
				await this.loadMailboxes()
			} catch (error) {
				logger.error('Failed to delete mailbox', { error })
				showError(this.t('mail', 'Failed to delete mailbox. Please try again.'))
				this.deleteProcessing = false
			}
		},
	},
}
</script>

<style lang="scss" scoped>
.mailbox-management {
	margin-top: 24px;

	h3 {
		font-size: 18px;
		font-weight: 600;
		margin-bottom: 16px;
	}

	.loading,
	.error,
	.empty {
		padding: 32px;
		text-align: center;
	}

	.error {
		color: var(--color-error);
	}

	.mailbox-list {
		.mailbox-list-header {
			display: flex;
			padding: 12px 16px;
			background-color: var(--color-background-dark);
			border-radius: 8px 8px 0 0;
			font-weight: 600;
			font-size: 14px;

			.column-email {
				flex: 0 0 35%;
			}

			.column-user {
				flex: 1;
			}

			.column-actions {
				flex: 0 0 120px;
				text-align: right;
			}
		}
	}
}

.modal-content {
	padding: 24px;

	h2 {
		margin-bottom: 16px;
	}

	p {
		margin-bottom: 16px;
		color: var(--color-text-lighter);
	}

	.warning {
		color: var(--color-error);
		font-weight: 600;
	}

	.form-group {
		margin-bottom: 24px;

		label {
			display: block;
			margin-bottom: 8px;
			font-weight: 600;
		}

		.email-input-container {
			display: flex;
			align-items: center;
			gap: 4px;

			input {
				flex: 1;
				padding: 8px 12px;
				border: 1px solid var(--color-border-dark);
				border-radius: 4px;
				font-size: 14px;

				&:focus {
					outline: none;
					border-color: var(--color-primary);
				}
			}

			.email-domain {
				color: var(--color-text-lighter);
				font-size: 14px;
			}
		}

		.error-message {
			margin-top: 8px;
			color: var(--color-error);
			font-size: 13px;
		}
	}

	.modal-actions {
		display: flex;
		justify-content: flex-end;
		gap: 8px;
		margin-top: 24px;
	}
}
</style>

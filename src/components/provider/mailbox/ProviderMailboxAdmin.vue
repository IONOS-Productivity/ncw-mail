<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<SettingsSection :name="t('mail', 'Email Administration')"
		:description="t('mail', 'Manage email accounts for your users')">
		<div class="mailbox-administration">
			<h3>{{ t('mail', 'Manage Emails') }}</h3>

			<!-- Provider Selection (if multiple providers available) -->
			<NcSelect v-if="providers.length > 1"
				v-model="selectedProvider"
				:options="providers"
				label="name"
				:placeholder="t('mail', 'Select email provider')"
				:disabled="loading"
				class="provider-selector"
				@input="onProviderChange">
				<template #selected-option="{ name }">
					{{ name }}
				</template>
			</NcSelect>

			<!-- Loading state -->
			<div v-if="loading" class="loading-container">
				<NcLoadingIcon :size="32" />
				<p>{{ t('mail', 'Loading mailboxes...') }}</p>
			</div>

			<!-- Error state -->
			<NcNoteCard v-else-if="error" type="error">
				{{ error }}
			</NcNoteCard>

			<!-- Empty state -->
			<NcEmptyContent v-else-if="mailboxes.length === 0"
				:name="t('mail', 'No mailboxes found')"
				:description="t('mail', 'There are no mailboxes to display.')">
				<template #icon>
					<IconMail :size="64" />
				</template>
			</NcEmptyContent>

			<!-- Mailbox list -->
			<div v-else class="mailbox-list">
				<table class="mailbox-table">
					<thead>
						<tr>
							<th>{{ t('mail', 'Email Address') }}</th>
							<th>{{ t('mail', 'Linked User') }}</th>
							<th class="actions-column">{{ t('mail', 'Actions') }}</th>
						</tr>
					</thead>
					<tbody>
						<ProviderMailboxListItem v-for="mailbox in mailboxes"
							:key="mailbox.userId"
							:mailbox="mailbox"
							@delete="handleDelete" />
					</tbody>
				</table>
			</div>

			<!-- Deletion modal -->
			<ProviderMailboxDeletionModal v-if="showDeleteModal"
				:mailbox="selectedMailbox"
				@confirm="confirmDelete"
				@cancel="cancelDelete" />
		</div>
	</SettingsSection>
</template>

<script>
import { NcEmptyContent, NcLoadingIcon, NcNoteCard, NcSelect, NcSettingsSection as SettingsSection } from '@nextcloud/vue'
import IconMail from 'vue-material-design-icons/Email.vue'

import ProviderMailboxListItem from './ProviderMailboxListItem.vue'
import ProviderMailboxDeletionModal from './ProviderMailboxDeletionModal.vue'
import { getMailboxes, getEnabledProviders, deleteMailbox } from '../../../service/ProviderMailboxService.js'
import { showError, showSuccess } from '@nextcloud/dialogs'

export default {
	name: 'ProviderMailboxAdmin',
	components: {
		SettingsSection,
		NcEmptyContent,
		NcLoadingIcon,
		NcNoteCard,
		NcSelect,
		IconMail,
		ProviderMailboxListItem,
		ProviderMailboxDeletionModal,
	},
	data() {
		return {
			loading: false,
			error: null,
			mailboxes: [],
			providers: [],
			selectedProvider: null,
			showDeleteModal: false,
			selectedMailbox: null,
		}
	},
	async mounted() {
		await this.loadProviders()
		await this.loadMailboxes()
	},
	methods: {
		async loadProviders() {
			try {
				const response = await getEnabledProviders()
				this.providers = response.data?.providers || []

				// Auto-select if only one provider or select the first one
				if (this.providers.length > 0) {
					this.selectedProvider = this.providers[0]
				}
			} catch (error) {
				console.error('Failed to load providers', error)
				this.error = this.t('mail', 'Failed to load providers')
			}
		},
		async loadMailboxes() {
			this.loading = true
			this.error = null

			try {
				// Check if a provider is selected
				if (!this.selectedProvider) {
					this.mailboxes = []
					return
				}

				const providerId = this.selectedProvider.id
				const response = await getMailboxes(providerId)
				this.mailboxes = response.data?.mailboxes || []
			} catch (error) {
				console.error('Failed to load mailboxes', error)
				this.error = this.t('mail', 'Failed to load mailboxes')
			} finally {
				this.loading = false
			}
		},
		onProviderChange() {
			// Reload mailboxes when provider changes
			this.loadMailboxes()
		},
		handleDelete(mailbox) {
			this.selectedMailbox = mailbox
			this.showDeleteModal = true
		},
		async confirmDelete() {
			if (!this.selectedMailbox || !this.selectedProvider) {
				return
			}

			try {
				const providerId = this.selectedProvider.id
				await deleteMailbox(providerId, this.selectedMailbox.userId)

				showSuccess(this.t('mail', 'Mailbox deleted successfully'))

				// Remove from list
				this.mailboxes = this.mailboxes.filter(
					m => m.userId !== this.selectedMailbox.userId
				)
			} catch (error) {
				console.error('Failed to delete mailbox', error)
				const errorMsg = error.response?.data?.data?.message || this.t('mail', 'Failed to delete mailbox')
				showError(errorMsg)
			} finally {
				this.cancelDelete()
			}
		},
		cancelDelete() {
			this.showDeleteModal = false
			this.selectedMailbox = null
		},
	},
}
</script>

<style scoped lang="scss">
.mailbox-administration {
	padding: 20px 0;

	h3 {
		margin-bottom: 20px;
		font-weight: 600;
		font-size: 18px;
	}

	.provider-selector {
		margin-bottom: 20px;
		max-width: 400px;
	}

	.loading-container {
		display: flex;
		flex-direction: column;
		align-items: center;
		justify-content: center;
		padding: 40px 0;
		gap: 16px;

		p {
			color: var(--color-text-lighter);
		}
	}

	.mailbox-list {
		margin-top: 20px;
	}

	.mailbox-table {
		width: 100%;
		border-collapse: collapse;

		thead {
			background-color: var(--color-background-dark);

			th {
					text-align: start;
				padding: 12px;
				font-weight: 600;
				border-bottom: 2px solid var(--color-border);

				&.actions-column {
						text-align: end;
					width: 120px;
				}
			}
		}

		tbody {
			tr {
				border-bottom: 1px solid var(--color-border);

				&:hover {
					background-color: var(--color-background-hover);
				}
			}
		}
	}
}
</style>

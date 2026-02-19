<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<SettingsSection :name="t('mail', 'Email Administration')"
		:description="t('mail', 'Manage email accounts for your users')">
		<div class="mailbox-administration">
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

			<!-- Mailbox list table -->
			<div v-else class="mailbox-list">
				<div class="mailbox-table">
					<!-- Sticky header -->
					<thead class="mailbox-table__header">
						<div class="header">
							<div class="header__cell header__cell--email"
								data-cy-mailbox-list-header-email>
								<strong>{{ t('mail', 'Email Address') }}</strong>
							</div>
							<div class="header__cell header__cell--displayname"
								data-cy-mailbox-list-header-displayname>
								<span>{{ t('mail', 'Display Name') }}</span>
							</div>
							<div class="header__cell header__cell--linked-user"
								data-cy-mailbox-list-header-linked-user>
								<span>{{ t('mail', 'Linked User') }}</span>
							</div>
							<div class="header__cell header__cell--status"
								data-cy-mailbox-list-header-status>
								<span>{{ t('mail', 'Status') }}</span>
							</div>
							<div class="header__cell header__cell--actions"
								data-cy-mailbox-list-header-actions>
								<span class="hidden-visually">{{ t('mail', 'Actions') }}</span>
							</div>
						</div>
					</thead>

					<!-- Body rows -->
					<div class="mailbox-table__body">
						<ProviderMailboxListItem v-for="mailbox in mailboxes"
							:key="mailbox.userId"
							:mailbox="mailbox"
							:provider-id="selectedProvider.id"
							@delete="handleDelete"
							@update="handleUpdate" />
					</div>

					<!-- Footer: entry count -->
					<tfoot class="mailbox-table__footer">
						<span class="mailbox-count">
							{{ n('mail', '%n mailbox', '%n mailboxes', mailboxes.length) }}
						</span>
					</tfoot>
				</div>
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
import { n } from '@nextcloud/l10n'
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
			loading: true,
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
		n,
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
		handleUpdate(updatedMailbox) {
			// Find and update mailbox in list
			const index = this.mailboxes.findIndex(m => m.userId === updatedMailbox.userId)
			if (index !== -1) {
				this.$set(this.mailboxes, index, updatedMailbox)
			}
		},
		async confirmDelete() {
			if (!this.selectedMailbox || !this.selectedProvider) {
				return
			}

			try {
				const providerId = this.selectedProvider.id
				await deleteMailbox(providerId, this.selectedMailbox.userId, this.selectedMailbox.email)

				showSuccess(this.t('mail', 'Mailbox deleted successfully'))

				// Remove from list
				this.mailboxes = this.mailboxes.filter(
					m => m.userId !== this.selectedMailbox.userId,
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
@use './shared/styles' as styles;

.mailbox-administration {
	.provider-selector {
		margin-bottom: 20px;
		max-width: 400px;
		width: 100%;
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
}

.mailbox-list {
	margin-top: 8px;
}

.mailbox-table {
	--row-height: 55px;
	--cell-padding: 7px;
	--cell-width: 200px;
	--cell-width-large: 300px;
	--sticky-column-z-index: 1;

	// Block display + overflow: auto enables horizontal scroll
	// while keeping sticky columns pinned
	display: block;
	overflow: auto;
	border: 1px solid var(--color-border);
	border-radius: var(--border-radius-large);

	&__header {
		position: sticky;
		top: 0;
		z-index: calc(var(--sticky-column-z-index) + 1);
		display: block;

		.header {
			border-bottom: 1px solid var(--color-border);
			background-color: var(--color-background-dark);

			@include styles.row;
			@include styles.cell;
		}

		// Header-specific overrides
		.header__cell {
			font-weight: 600;

			span {
				overflow: hidden;
				text-overflow: ellipsis;
				white-space: nowrap;
			}
		}
	}

	&__body {
		display: flex;
		flex-direction: column;
		width: 100%;
	}

	&__footer {
		display: block;
		position: sticky;
		inset-inline-start: 0;

		.mailbox-count {
			display: block;
			padding: 8px var(--cell-padding);
			color: var(--color-text-maxcontrast);
			font-size: 13px;
			border-top: 1px solid var(--color-border);
		}
	}
}
</style>

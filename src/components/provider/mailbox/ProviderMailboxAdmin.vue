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
			<VirtualList v-else
				:data-component="MailboxListItem"
				:data-sources="mailboxes"
				data-key="userId"
				data-cy-mailbox-list
				:item-height="rowHeight"
				:style="style"
				:extra-props="{
					providerId: selectedProvider.id,
					debug,
				}"
				@delete="handleDelete"
				@update="handleUpdate">
				<template #before>
					<caption class="hidden-visually">
						{{ t('mail', 'List of mailboxes. This list is not fully rendered for performance reasons. The mailboxes will be rendered as you navigate through the list.') }}
					</caption>
				</template>

				<template #header>
					<MailboxListHeader :debug="debug" />
				</template>

				<template #footer>
					<MailboxListFooter :loading="loading"
						:mailboxes="mailboxes" />
				</template>
			</VirtualList>

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

import VirtualList from './shared/VirtualList.vue'
import MailboxListHeader from './shared/MailboxListHeader.vue'
import MailboxListFooter from './shared/MailboxListFooter.vue'
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
		VirtualList,
		MailboxListHeader,
		MailboxListFooter,
		ProviderMailboxListItem,
		ProviderMailboxDeletionModal,
	},

	setup() {
		// non reactive properties
		return {
			rowHeight: 55,
			MailboxListItem: ProviderMailboxListItem,
		}
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
			debug: false,
		}
	},

	computed: {
		style() {
			return {
				'--row-height': `${this.rowHeight}px`,
			}
		},
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
				this.debug = response.data?.debug || false
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
.mailbox-administration {
	display: flex;
	flex-direction: column;

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

	:deep(.mailbox-list) {
		flex: 1;
		min-height: 0;
	}
}
</style>

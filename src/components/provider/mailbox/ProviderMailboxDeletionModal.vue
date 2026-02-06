<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<NcDialog :name="t('mail', 'Mailbox deletion')"
		:open="true"
		@update:open="handleClose">
		<div class="deletion-modal-content">
			<NcNoteCard type="warning">
				<p>
					{{
						t(
							'mail',
							'Are you sure you want to delete the mailbox for {email}?',
							{ email: mailbox?.email || '' }
						)
					}}
				</p>
				<p class="warning-text">
					{{ t('mail', 'This action cannot be undone. All emails and settings for this account will be permanently deleted.') }}
				</p>
			</NcNoteCard>

			<div class="mailbox-details">
				<div class="detail-row">
					<span class="label">{{ t('mail', 'Email Address:') }}</span>
					<span class="value">{{ mailbox?.email || '' }}</span>
				</div>
				<div class="detail-row">
					<span class="label">{{ t('mail', 'User:') }}</span>
					<span class="value">{{ mailbox?.name || mailbox?.userId || '' }}</span>
				</div>
			</div>
		</div>

		<template #actions>
			<NcButton type="secondary" @click="handleClose">
				{{ t('mail', 'Cancel') }}
			</NcButton>
			<NcButton type="error" @click="handleConfirm">
				<template #icon>
					<IconDelete :size="20" />
				</template>
				{{ t('mail', 'Delete mailbox') }}
			</NcButton>
		</template>
	</NcDialog>
</template>

<script>
import { NcDialog, NcButton, NcNoteCard } from '@nextcloud/vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'ProviderMailboxDeletionModal',
	components: {
		NcDialog,
		NcButton,
		NcNoteCard,
		IconDelete,
	},
	props: {
		mailbox: {
			type: Object,
			default: null,
		},
	},
	emits: ['confirm', 'cancel'],
	methods: {
		handleConfirm() {
			this.$emit('confirm')
		},
		handleClose() {
			this.$emit('cancel')
		},
	},
}
</script>

<style scoped lang="scss">
.deletion-modal-content {
	padding: 16px 0;

	.warning-text {
		margin-top: 12px;
		font-weight: 500;
		color: var(--color-error);
	}

	.mailbox-details {
		margin-top: 20px;
		padding: 16px;
		background-color: var(--color-background-dark);
		border-radius: var(--border-radius);

		.detail-row {
			display: flex;
			gap: 12px;
			margin-bottom: 8px;

			&:last-child {
				margin-bottom: 0;
			}

			.label {
				font-weight: 600;
				min-width: 120px;
			}

			.value {
				color: var(--color-text-lighter);
			}
		}
	}
}
</style>

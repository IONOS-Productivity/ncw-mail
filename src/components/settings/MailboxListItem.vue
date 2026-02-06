<!--
  - SPDX-FileCopyrightText: 2026 IONOS SE
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<div class="mailbox-list-item">
		<div class="column-email">
			<span class="email-address">{{ mailbox.email }}</span>
		</div>
		<div class="column-user">
			<div class="user-info">
				<NcAvatar :user="mailbox.userId"
					:display-name="mailbox.displayName"
					:size="32" />
				<div class="user-details">
					<span class="user-name">{{ mailbox.displayName }}</span>
					<span class="user-id">({{ mailbox.username }})</span>
				</div>
			</div>
		</div>
		<div class="column-actions">
			<NcActions>
				<NcActionButton @click="$emit('edit', mailbox)">
					<template #icon>
						<IconPencil :size="20" />
					</template>
					{{ t('mail', 'Edit') }}
				</NcActionButton>
				<NcActionButton @click="$emit('delete', mailbox)">
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
import NcActions from '@nextcloud/vue/components/NcActions'
import NcActionButton from '@nextcloud/vue/components/NcActionButton'
import NcAvatar from '@nextcloud/vue/components/NcAvatar'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'MailboxListItem',
	components: {
		NcActions,
		NcActionButton,
		NcAvatar,
		IconPencil,
		IconDelete,
	},
	props: {
		mailbox: {
			type: Object,
			required: true,
		},
	},
	emits: ['edit', 'delete'],
}
</script>

<style lang="scss" scoped>
.mailbox-list-item {
	display: flex;
	padding: 16px;
	border-bottom: 1px solid var(--color-border);
	align-items: center;

	&:hover {
		background-color: var(--color-background-hover);
	}

	.column-email {
		flex: 0 0 35%;

		.email-address {
			font-family: monospace;
			font-size: 14px;
			color: var(--color-text-darker);
		}
	}

	.column-user {
		flex: 1;

		.user-info {
			display: flex;
			align-items: center;
			gap: 12px;

			.user-details {
				display: flex;
				flex-direction: column;
				gap: 2px;

				.user-name {
					font-size: 14px;
					font-weight: 500;
					color: var(--color-text-darker);
				}

				.user-id {
					font-size: 13px;
					color: var(--color-text-lighter);
				}
			}
		}
	}

	.column-actions {
		flex: 0 0 120px;
		display: flex;
		justify-content: flex-end;
	}
}
</style>

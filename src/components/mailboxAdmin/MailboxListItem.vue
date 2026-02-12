<!--
  - SPDX-FileCopyrightText: 2025 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<tr class="mailbox-list-item">
		<td class="email-column">
			<span class="email-address">{{ mailbox.email }}</span>
		</td>
		<td class="user-column">
			<div class="user-info">
				<NcAvatar :user="mailbox.userId"
					:size="32"
					:display-name="mailbox.name || mailbox.userId" />
				<div class="user-details">
					<span class="user-name">{{ mailbox.name || mailbox.userId }}</span>
					<span class="user-id">{{ mailbox.userId }}</span>
				</div>
			</div>
		</td>
		<td class="actions-column">
			<div class="actions">
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
		</td>
	</tr>
</template>

<script>
import { NcAvatar, NcActions, NcActionButton } from '@nextcloud/vue'
import IconPencil from 'vue-material-design-icons/Pencil.vue'
import IconDelete from 'vue-material-design-icons/Delete.vue'

export default {
	name: 'MailboxListItem',
	components: {
		NcAvatar,
		NcActions,
		NcActionButton,
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

<style scoped lang="scss">
.mailbox-list-item {
	td {
		padding: 12px;
		vertical-align: middle;
	}

	.email-column {
		.email-address {
			font-family: monospace;
			font-size: 14px;
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

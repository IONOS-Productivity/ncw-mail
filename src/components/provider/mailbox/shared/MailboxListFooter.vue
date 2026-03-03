<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<tr class="footer">
		<th scope="row">
			<span class="hidden-visually">{{ t('mail', 'Total rows summary') }}</span>
		</th>
		<td class="footer__cell footer__cell--loading">
			<NcLoadingIcon v-if="loading"
				:title="t('mail', 'Loading mailboxes...')"
				:size="32" />
		</td>
		<td class="footer__cell footer__cell--count">
			<span :aria-describedby="countDescId">{{ mailboxCount }}</span>
			<span :id="countDescId"
				class="hidden-visually">
				{{ t('mail', 'Scroll to load more rows') }}
			</span>
		</td>
	</tr>
</template>

<script>
import { NcLoadingIcon } from '@nextcloud/vue'
import { n } from '@nextcloud/l10n'

export default {
	name: 'MailboxListFooter',

	components: {
		NcLoadingIcon,
	},

	props: {
		loading: {
			type: Boolean,
			required: true,
		},
		mailboxes: {
			type: Array,
			required: true,
		},
	},

	data() {
		return {
			countDescId: `mailbox-count-desc-${this._uid}`,
		}
	},

	computed: {
		mailboxCount() {
			if (this.loading) {
				return n(
					'mail',
					'{count} mailbox …',
					'{count} mailboxes …',
					this.mailboxes.length,
					{
						count: this.mailboxes.length,
					},
				)
			}
			return n(
				'mail',
				'{count} mailbox',
				'{count} mailboxes',
				this.mailboxes.length,
				{
					count: this.mailboxes.length,
				},
			)
		},
	},

}

</script>

<style lang="scss" scoped>
@use './styles';

.footer {
	border-top: 1px solid var(--color-border);

	@include styles.row;
	@include styles.cell;

	&__cell {
		position: sticky;
		color: var(--color-text-maxcontrast);
		padding: 8px var(--cell-padding);
		font-size: 13px;

		&--loading {
			inset-inline-start: 0;
			min-width: var(--cell-width);
			width: var(--cell-width);
			align-items: center;
			padding: 0;
		}

		&--count {
			inset-inline-start: var(--cell-width);
			min-width: var(--cell-width);
			width: var(--cell-width);
		}
	}
}
</style>

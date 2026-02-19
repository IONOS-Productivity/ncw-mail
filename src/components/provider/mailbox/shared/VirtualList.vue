<!--
  - SPDX-FileCopyrightText: 2026 STRATO GmbH
  - SPDX-License-Identifier: AGPL-3.0-or-later
-->

<template>
	<table class="mailbox-list">
		<slot name="before" />

		<thead ref="thead"
			role="rowgroup"
			class="mailbox-list__header">
			<slot name="header" />
		</thead>

		<tbody :style="tbodyStyle"
			class="mailbox-list__body">
			<component :is="dataComponent"
				v-for="item in renderedItems"
				:key="item[dataKey]"
				:mailbox="item"
				v-bind="extraProps"
				@delete="$emit('delete', $event)"
				@update="$emit('update', $event)" />
		</tbody>

		<tfoot ref="tfoot"
			role="rowgroup"
			class="mailbox-list__footer">
			<slot name="footer" />
		</tfoot>
	</table>
</template>

<script>
import debounce from 'lodash/fp/debounce.js'

// Items to render before and after the visible area
const bufferItems = 3

export default {
	name: 'VirtualList',

	props: {
		dataComponent: {
			type: [Object, Function],
			required: true,
		},
		dataKey: {
			type: String,
			required: true,
		},
		dataSources: {
			type: Array,
			required: true,
		},
		itemHeight: {
			type: Number,
			required: true,
		},
		extraProps: {
			type: Object,
			default: () => ({}),
		},
	},

	data() {
		return {
			index: 0,
			headerHeight: 0,
			tableHeight: 0,
			resizeObserver: null,
		}
	},

	computed: {
		startIndex() {
			return Math.max(0, this.index - bufferItems)
		},

		shownItems() {
			return Math.ceil((this.tableHeight - this.headerHeight) / this.itemHeight) + bufferItems * 2
		},

		renderedItems() {
			return this.dataSources.slice(this.startIndex, this.startIndex + this.shownItems)
		},

		tbodyStyle() {
			const hiddenAfterItems = Math.max(0, this.dataSources.length - this.startIndex - this.shownItems)
			return {
				paddingTop: `${this.startIndex * this.itemHeight}px`,
				paddingBottom: `${hiddenAfterItems * this.itemHeight}px`,
			}
		},
	},

	mounted() {
		const root = this.$el
		const tfoot = this.$refs?.tfoot
		const thead = this.$refs?.thead

		this.resizeObserver = new ResizeObserver(debounce(100, () => {
			this.headerHeight = thead?.clientHeight ?? 0
			this.tableHeight = root?.clientHeight ?? 0
			this.onScroll()
		}))

		this.resizeObserver.observe(root)
		this.resizeObserver.observe(tfoot)
		this.resizeObserver.observe(thead)

		this.$el.addEventListener('scroll', this.onScroll)
	},

	beforeDestroy() {
		if (this.resizeObserver) {
			this.resizeObserver.disconnect()
		}
		this.$el.removeEventListener('scroll', this.onScroll)
	},

	methods: {
		onScroll() {
			// Max 0 to prevent negative index
			this.index = Math.max(0, Math.floor(this.$el.scrollTop / this.itemHeight))
		},
	},
}
</script>

<style lang="scss" scoped>
.mailbox-list {
	--cell-padding: 7px;
	--cell-width: 200px;
	--cell-width-large: 300px;
	--sticky-column-z-index: 1;

	// Necessary for virtual scroll optimized rendering
	display: block;
	overflow: auto;
	height: 100%;
	will-change: scroll-position;

	&__header,
	&__footer {
		position: sticky;
		// Fix sticky positioning in Firefox
		display: block;
	}

	&__header {
		top: 0;
		z-index: calc(var(--sticky-column-z-index) + 1);
	}

	&__footer {
		inset-inline-start: 0;
		bottom: 0;
	}

	&__body {
		display: flex;
		flex-direction: column;
		width: 100%;
	}
}
</style>

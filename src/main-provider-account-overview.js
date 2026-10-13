/**
 * SPDX-FileCopyrightText: 2026 STRATO GmbH
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

import { getRequestToken } from '@nextcloud/auth'
import { generateFilePath } from '@nextcloud/router'
import Vue from 'vue'
import ProviderMailboxAdmin from './components/provider/mailbox/ProviderMailboxAdmin.vue'
import Nextcloud from './mixins/Nextcloud.js'

import '@nextcloud/dialogs/style.css'

__webpack_nonce__ = btoa(getRequestToken())

__webpack_public_path__ = generateFilePath('mail', '', 'js/')

Vue.mixin(Nextcloud)

const View = Vue.extend(ProviderMailboxAdmin)
new View().$mount('#mail-provider-account-overview')

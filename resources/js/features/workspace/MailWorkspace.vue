<script setup lang="ts">
import { computed } from 'vue';
import { usePaneWidths } from './usePaneWidths';
import SyncStatus from '../accounts/SyncStatus.vue';
import ConversationReader from '../messages/ConversationReader.vue';
import MessageList from '../messages/MessageList.vue';
import type { MailboxCounts, MailboxView, ReadChange } from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';
import { statusLabels, statusTone } from '../accounts/statusLabel';

const props = withDefaults(
    defineProps<{
        userName: string;
        readChange?: ReadChange | null;
        accounts?: AccountSummary[];
        section?: 'mail' | 'accounts';
        view?: MailboxView;
        accountId?: number | null;
        counts?: MailboxCounts | null;
        refreshVersion?: number;
        selectedMessageId?: number | null;
    }>(),
    { accounts: () => [], section: 'mail', view: 'all' },
);
defineEmits<{
    signOut: [];
    readChanged: [change: ReadChange];
    openAccount: [id: number];
    accountView: [view: MailboxView];
    mailboxLoaded: [];
    refreshMailbox: [];
    navigate: [section: MailboxView | 'accounts'];
    select: [id: number | null];
}>();

const navigation: { view: MailboxView; label: string }[] = [
    { view: 'all', label: 'All Mail' },
    { view: 'inbox', label: 'Inbox' },
    { view: 'unread', label: 'Unread' },
    { view: 'sent', label: 'Sent' },
    { view: 'archive', label: 'Archive' },
];
const panes = usePaneWidths();
const automaticAccounts = computed(() =>
    props.accounts.filter((account) => account.enabled && account.sync_enabled),
);
const syncingAccounts = computed(
    () => automaticAccounts.value.filter((account) => account.sync_status === 'syncing').length,
);
const selectedAccount = computed(() =>
    props.accounts.find((account) => account.id === props.accountId),
);
</script>

<template>
    <div class="workspace">
        <header class="workspace-header">
            <div class="identity">
                <div class="brand-mark brand-mark-small" aria-hidden="true">M</div>
                <span class="brand-name">MailCenter</span>
            </div>
            <div class="header-actions">
                <span class="user-name">{{ userName }}</span>
                <button class="text-button" type="button" @click="$emit('signOut')">
                    Sign out
                </button>
            </div>
        </header>

        <div
            class="workspace-grid"
            :style="panes.style.value"
            :class="{
                'workspace-grid-accounts': section === 'accounts',
                'is-resizing': panes.dragging.value,
            }"
        >
            <aside class="sidebar" aria-label="Navigation" data-testid="left-pane">
                <div class="sidebar-inner">
                    <p class="section-label">Global workspace</p>
                    <p class="scope-hint">All enabled accounts, together</p>
                    <nav aria-label="Views">
                        <button
                            v-for="item in navigation"
                            :key="item.view"
                            type="button"
                            class="nav-row nav-button"
                            :class="{
                                'nav-row-current':
                                    section === 'mail' && accountId == null && view === item.view,
                            }"
                            :aria-current="
                                section === 'mail' && accountId == null && view === item.view
                                    ? 'page'
                                    : undefined
                            "
                            @click="$emit('navigate', item.view)"
                        >
                            <span class="nav-glyph" aria-hidden="true">◇</span>
                            {{ item.label }}
                            <span
                                v-if="counts?.views[item.view]"
                                class="mailbox-count"
                                :title="`${counts.views[item.view].unread} unread`"
                                >{{ counts.views[item.view].total }}</span
                            >
                        </button>
                    </nav>
                    <div class="sidebar-divider"></div>
                    <p class="section-label">Accounts</p>
                    <div v-if="accounts.length === 0" class="sidebar-hint">
                        No account connected
                    </div>
                    <button
                        type="button"
                        v-for="account in accounts"
                        :key="account.id"
                        class="nav-row nav-button account-nav-row"
                        :class="{
                            'nav-row-current': section === 'mail' && accountId === account.id,
                            'account-disabled': !account.enabled,
                        }"
                        :aria-current="
                            section === 'mail' && accountId === account.id ? 'page' : undefined
                        "
                        @click="$emit('openAccount', account.id)"
                        :title="statusLabels[account.sync_status]"
                    >
                        <span
                            class="status-dot"
                            :class="`tone-${statusTone(account.sync_status)}`"
                            aria-hidden="true"
                        ></span>
                        <span class="account-nav-label"
                            >{{ account.display_name
                            }}<small v-if="!account.enabled">Disabled</small></span
                        >
                        <span
                            v-if="counts?.accounts[account.id]"
                            class="mailbox-count"
                            :title="`${counts.accounts[account.id].unread} unread`"
                            >{{ counts.accounts[account.id].total
                            }}<small>{{ counts.accounts[account.id].unread }} unread</small></span
                        >
                    </button>
                    <button
                        class="nav-row nav-button"
                        :class="{ 'nav-row-current': section === 'accounts' }"
                        type="button"
                        :aria-current="section === 'accounts' ? 'page' : undefined"
                        @click="$emit('navigate', 'accounts')"
                    >
                        Manage accounts
                    </button>
                </div>
                <div class="sidebar-footer">Your mail, one workspace</div>
            </aside>
            <div
                class="pane-divider"
                role="separator"
                tabindex="0"
                aria-orientation="vertical"
                aria-label="Resize sidebar"
                :aria-valuemin="panes.limits('sidebar').min"
                :aria-valuemax="panes.limits('sidebar').max"
                :aria-valuenow="panes.sidebar.value"
                @pointerdown="panes.start($event, 'sidebar')"
                @keydown="panes.keyboard($event, 'sidebar')"
            ></div>

            <section
                v-if="section === 'accounts'"
                class="accounts-pane"
                aria-label="Mail accounts"
                data-testid="accounts-pane"
            >
                <slot name="accounts" />
            </section>

            <section
                v-if="section === 'mail'"
                class="message-list-pane"
                aria-label="Message list"
                data-testid="center-pane"
            >
                <div class="pane-toolbar">
                    <div>
                        <p class="eyebrow">
                            {{ accountId == null ? 'GLOBAL WORKSPACE' : 'ACCOUNT MAILBOX' }}
                        </p>
                        <h1>
                            {{
                                accountId != null
                                    ? accounts.find((account) => account.id === accountId)
                                          ?.display_name || 'Account mailbox'
                                    : navigation.find((item) => item.view === view)?.label
                            }}
                        </h1>
                        <p class="scope-description">
                            {{
                                accountId == null
                                    ? 'Mail across all enabled accounts'
                                    : selectedAccount?.email_address
                            }}
                        </p>
                        <small v-if="selectedAccount?.enabled === false"
                            >Disabled account · retained mail</small
                        >
                    </div>
                </div>
                <nav v-if="accountId != null" class="account-view-tabs" aria-label="Account views">
                    <button
                        v-for="item in navigation"
                        :key="item.view"
                        type="button"
                        :aria-current="view === item.view ? 'page' : undefined"
                        @click="$emit('accountView', item.view)"
                    >
                        {{ item.label }}
                    </button>
                </nav>
                <SyncStatus v-if="selectedAccount" :account="selectedAccount" compact />
                <p v-else class="mailbox-sync-hint">
                    {{
                        automaticAccounts.length
                            ? `${automaticAccounts.length} account(s) sync automatically · new mail appears here`
                            : accounts.length
                              ? 'Automatic sync is paused for all accounts'
                              : 'Connect an account to synchronize mail'
                    }}<span v-if="syncingAccounts"> · {{ syncingAccounts }} syncing…</span>
                </p>
                <button
                    type="button"
                    class="text-button mailbox-refresh"
                    title="Reload messages already synchronized to MailCenter; account sync runs automatically"
                    @click="$emit('refreshMailbox')"
                >
                    Refresh view
                </button>
                <MessageList
                    :read-change="readChange"
                    :view="view"
                    :account-id="accountId"
                    :refresh-version="refreshVersion"
                    @loaded="$emit('mailboxLoaded')"
                    :accounts="accounts"
                    :selected-message-id="selectedMessageId"
                    @select="$emit('select', $event)"
                />
            </section>

            <template v-if="section === 'mail'"
                ><div
                    class="pane-divider"
                    role="separator"
                    tabindex="0"
                    aria-orientation="vertical"
                    aria-label="Resize message list"
                    :aria-valuemin="panes.limits('list').min"
                    :aria-valuemax="panes.limits('list').max"
                    :aria-valuenow="panes.list.value"
                    @pointerdown="panes.start($event, 'list')"
                    @keydown="panes.keyboard($event, 'list')"
                ></div
            ></template>
            <section
                v-if="section === 'mail'"
                class="reader-pane"
                aria-label="Message viewer"
                data-testid="right-pane"
            >
                <ConversationReader
                    @read-changed="$emit('readChanged', $event)"
                    :message-id="selectedMessageId ?? null"
                    :refresh-version="refreshVersion"
                    :accounts="accounts"
                    @close="$emit('select', null)"
                />
            </section>
        </div>
    </div>
</template>

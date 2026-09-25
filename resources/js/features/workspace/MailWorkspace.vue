<script setup lang="ts">
import type { AccountSummary } from '../../api/accounts';
import { statusLabels, statusTone } from '../accounts/statusLabel';

withDefaults(
    defineProps<{
        userName: string;
        accounts?: AccountSummary[];
        section?: 'mail' | 'accounts';
    }>(),
    { accounts: () => [], section: 'mail' },
);
defineEmits<{ signOut: []; navigate: [section: 'mail' | 'accounts'] }>();

const navigation = ['All Mail', 'Inbox', 'Unread', 'Starred', 'Completed'];
const folders = ['Reloads', 'Support', 'Withdrawals', 'Verification', 'Done'];
</script>

<template>
    <div class="workspace">
        <header class="workspace-header">
            <div class="identity">
                <div class="brand-mark brand-mark-small" aria-hidden="true">M</div>
                <span class="brand-name">MailCenter</span>
                <span class="phase-label">FOUNDATION</span>
            </div>
            <div class="header-actions">
                <span class="user-name">{{ userName }}</span>
                <button class="text-button" type="button" @click="$emit('signOut')">
                    Sign out
                </button>
            </div>
        </header>

        <div class="workspace-grid" :class="{ 'workspace-grid-accounts': section === 'accounts' }">
            <aside class="sidebar" aria-label="Navigation" data-testid="left-pane">
                <div class="sidebar-inner">
                    <p class="section-label">Workspace</p>
                    <nav aria-label="Views">
                        <span
                            v-for="(item, index) in navigation"
                            :key="item"
                            class="nav-row"
                            :class="{ 'nav-row-current': index === 0 }"
                            :aria-current="index === 0 ? 'page' : undefined"
                        >
                            <span class="nav-glyph" aria-hidden="true">{{
                                index === 0 ? '▦' : '◇'
                            }}</span>
                            {{ item }}
                        </span>
                    </nav>
                    <div class="sidebar-divider"></div>
                    <p class="section-label">Accounts</p>
                    <div v-if="accounts.length === 0" class="sidebar-hint">
                        No account connected
                    </div>
                    <span
                        v-for="account in accounts"
                        :key="account.id"
                        class="nav-row account-nav-row"
                        :title="statusLabels[account.sync_status]"
                    >
                        <span
                            class="status-dot"
                            :class="`tone-${statusTone(account.sync_status)}`"
                            aria-hidden="true"
                        ></span>
                        {{ account.display_name }}
                    </span>
                    <button
                        class="nav-row nav-button"
                        :class="{ 'nav-row-current': section === 'accounts' }"
                        type="button"
                        @click="$emit('navigate', section === 'accounts' ? 'mail' : 'accounts')"
                    >
                        {{ section === 'accounts' ? '← Back to workspace' : 'Manage accounts' }}
                    </button>
                    <div class="sidebar-divider"></div>
                    <p class="section-label">Folders</p>
                    <div v-for="folder in folders" :key="folder" class="nav-row nav-row-muted">
                        <span class="folder-dot" aria-hidden="true"></span>
                        {{ folder }}
                    </div>
                    <p class="sidebar-caption">Folder names are layout examples.</p>
                </div>
                <div class="sidebar-footer">MailCenter · M2</div>
            </aside>

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
                        <p class="eyebrow">WORKSPACE</p>
                        <h1>All Mail</h1>
                    </div>
                    <span class="placeholder-pill">0 messages</span>
                </div>
                <div class="list-placeholder">
                    <div class="placeholder-icon" aria-hidden="true">✉</div>
                    <h2>Your messages will appear here</h2>
                    <p>The message list is ready for account setup in a later milestone.</p>
                </div>
            </section>

            <section
                v-if="section === 'mail'"
                class="reader-pane"
                aria-label="Message viewer"
                data-testid="right-pane"
            >
                <div class="reader-toolbar">
                    <span>Message viewer</span><span class="toolbar-dots">···</span>
                </div>
                <div class="reader-placeholder">
                    <div class="reader-lines" aria-hidden="true"><i></i><i></i><i></i></div>
                    <h2>A place for the conversation</h2>
                    <p>Selecting a message will open it here when mail features arrive.</p>
                </div>
            </section>
        </div>
    </div>
</template>

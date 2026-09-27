<script setup lang="ts">
import { computed, ref } from 'vue';
import { usePaneWidths } from './usePaneWidths';
import SyncStatus from '../accounts/SyncStatus.vue';
import ConversationReader from '../messages/ConversationReader.vue';
import MessageList from '../messages/MessageList.vue';
import type { MailboxCounts, MailboxView, MoveChange, ReadChange } from '../../api/messages';
import { moveMessageFolder, type ConversationMoveResult } from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';
import type { Folder } from '../../api/folders';
import { createFolder, deleteFolder, renameFolder, reorderFolders } from '../../api/folders';
import { statusLabels, statusTone } from '../accounts/statusLabel';

const props = withDefaults(
    defineProps<{
        userName: string;
        readChange?: ReadChange | null;
        accounts?: AccountSummary[];
        section?: 'mail' | 'accounts';
        view?: MailboxView;
        syncPending?: number | null;
        syncError?: string;
        accountId?: number | null;
        folderId?: number | null;
        folders?: Folder[];
        foldersError?: string;
        counts?: MailboxCounts | null;
        refreshVersion?: number;
        selectedMessageId?: number | null;
    }>(),
    { accounts: () => [], folders: () => [], section: 'mail', view: 'all' },
);
const emit = defineEmits<{
    signOut: [];
    syncNow: [id: number];
    readChanged: [change: ReadChange];
    moved: [change: MoveChange | ConversationMoveResult];
    openAccount: [id: number];
    accountView: [view: MailboxView];
    openFolder: [id: number];
    folderMutated: [];
    folderDeleted: [id: number];
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
const selectedFolder = computed(() => props.folders.find((folder) => folder.id === props.folderId));

const creatingFolder = ref(false);
const newFolderName = ref('');
const folderActionError = ref('');
const editingFolderId = ref<number | null>(null);
const editingName = ref('');

async function submitCreate() {
    const name = newFolderName.value.trim();
    if (!name) return;
    folderActionError.value = '';
    try {
        await createFolder(name);
        newFolderName.value = '';
        creatingFolder.value = false;
        emit('folderMutated');
    } catch (cause) {
        folderActionError.value =
            cause instanceof Error ? cause.message : 'Unable to create folder.';
    }
}
function startRename(folder: Folder) {
    editingFolderId.value = folder.id;
    editingName.value = folder.name;
    folderActionError.value = '';
}
function cancelRename() {
    editingFolderId.value = null;
}
async function submitRename() {
    if (editingFolderId.value === null) return;
    const name = editingName.value.trim();
    if (!name) return;
    try {
        await renameFolder(editingFolderId.value, name);
        editingFolderId.value = null;
        emit('folderMutated');
    } catch (cause) {
        folderActionError.value =
            cause instanceof Error ? cause.message : 'Unable to rename folder.';
    }
}
async function removeFolder(folder: Folder) {
    const total = props.counts?.folders[String(folder.id)]?.total ?? 0;
    const message =
        total > 0
            ? `Delete "${folder.name}"? ${total} message${total === 1 ? '' : 's'} will move back to Inbox. Email is never deleted.`
            : `Delete "${folder.name}"? It is empty.`;
    if (!window.confirm(message)) return;
    folderActionError.value = '';
    try {
        await deleteFolder(folder.id);
        emit('folderDeleted', folder.id);
        emit('folderMutated');
    } catch (cause) {
        folderActionError.value =
            cause instanceof Error ? cause.message : 'Unable to delete folder.';
    }
}
async function moveFolderPosition(folder: Folder, direction: -1 | 1) {
    const ordered = [...props.folders].sort((a, b) => a.position - b.position).map((f) => f.id);
    const index = ordered.indexOf(folder.id);
    const target = index + direction;
    if (target < 0 || target >= ordered.length) return;
    [ordered[index], ordered[target]] = [ordered[target], ordered[index]];
    try {
        await reorderFolders(ordered);
        emit('folderMutated');
    } catch (cause) {
        folderActionError.value =
            cause instanceof Error ? cause.message : 'Unable to reorder folders.';
    }
}
function allowDrop(event: DragEvent) {
    event.preventDefault();
}
function dropOnFolder(event: DragEvent, folder: Folder) {
    event.preventDefault();
    const raw = event.dataTransfer?.getData('application/x-mailcenter-message-id') ?? '';
    const id = Number(raw);
    if (!Number.isInteger(id) || id <= 0) return;
    moveMessageFolder(id, folder.id)
        .then((change) => emit('moved', change))
        .catch(() => {
            folderActionError.value = 'Unable to move message.';
        });
}
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
                                    section === 'mail' &&
                                    accountId == null &&
                                    folderId == null &&
                                    view === item.view,
                            }"
                            :aria-current="
                                section === 'mail' &&
                                accountId == null &&
                                folderId == null &&
                                view === item.view
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
                    <div class="sidebar-divider"></div>
                    <p class="section-label">Folders</p>
                    <p v-if="foldersError" class="form-error" role="alert">{{ foldersError }}</p>
                    <nav aria-label="Folders">
                        <div
                            v-for="folder in folders"
                            :key="folder.id"
                            class="folder-row-wrap"
                            @dragover="allowDrop"
                            @drop="dropOnFolder($event, folder)"
                        >
                            <form
                                v-if="editingFolderId === folder.id"
                                class="folder-edit-form"
                                @submit.prevent="submitRename"
                            >
                                <input
                                    v-model="editingName"
                                    :aria-label="`Rename ${folder.name}`"
                                    @keyup.escape="cancelRename"
                                />
                                <button type="submit" class="small-button">Save</button>
                                <button type="button" class="text-button" @click="cancelRename">
                                    Cancel
                                </button>
                            </form>
                            <template v-else>
                                <button
                                    type="button"
                                    class="nav-row nav-button folder-nav-row"
                                    :class="{
                                        'nav-row-current':
                                            section === 'mail' && folderId === folder.id,
                                    }"
                                    :aria-current="
                                        section === 'mail' && folderId === folder.id
                                            ? 'page'
                                            : undefined
                                    "
                                    @click="$emit('openFolder', folder.id)"
                                >
                                    <span class="nav-glyph" aria-hidden="true">▸</span>
                                    {{ folder.name }}
                                    <span
                                        v-if="counts?.folders[String(folder.id)]"
                                        class="mailbox-count"
                                        :title="`${counts.folders[String(folder.id)].unread} unread`"
                                        >{{ counts.folders[String(folder.id)].total }}</span
                                    >
                                </button>
                                <span class="folder-row-actions">
                                    <button
                                        type="button"
                                        class="icon-button"
                                        title="Move up"
                                        aria-label="Move folder up"
                                        @click="moveFolderPosition(folder, -1)"
                                    >
                                        ↑
                                    </button>
                                    <button
                                        type="button"
                                        class="icon-button"
                                        title="Move down"
                                        aria-label="Move folder down"
                                        @click="moveFolderPosition(folder, 1)"
                                    >
                                        ↓
                                    </button>
                                    <button
                                        type="button"
                                        class="icon-button"
                                        title="Rename folder"
                                        aria-label="Rename folder"
                                        @click="startRename(folder)"
                                    >
                                        ✎
                                    </button>
                                    <button
                                        v-if="folder.system_role === null"
                                        type="button"
                                        class="icon-button"
                                        title="Delete folder"
                                        aria-label="Delete folder"
                                        @click="removeFolder(folder)"
                                    >
                                        ✕
                                    </button>
                                </span>
                            </template>
                        </div>
                    </nav>
                    <form
                        v-if="creatingFolder"
                        class="folder-create-form"
                        @submit.prevent="submitCreate"
                    >
                        <input
                            v-model="newFolderName"
                            placeholder="Folder name"
                            aria-label="New folder name"
                        />
                        <button type="submit" class="small-button">Create</button>
                        <button
                            type="button"
                            class="text-button"
                            @click="
                                creatingFolder = false;
                                newFolderName = '';
                            "
                        >
                            Cancel
                        </button>
                    </form>
                    <button
                        v-else
                        type="button"
                        class="nav-row nav-button"
                        @click="creatingFolder = true"
                    >
                        + New folder
                    </button>
                    <p v-if="folderActionError" class="form-error" role="alert">
                        {{ folderActionError }}
                    </p>
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
                            {{
                                folderId != null
                                    ? 'FOLDER'
                                    : accountId == null
                                      ? 'GLOBAL WORKSPACE'
                                      : 'ACCOUNT MAILBOX'
                            }}
                        </p>
                        <h1>
                            {{
                                folderId != null
                                    ? selectedFolder?.name || 'Folder'
                                    : accountId != null
                                      ? accounts.find((account) => account.id === accountId)
                                            ?.display_name || 'Account mailbox'
                                      : navigation.find((item) => item.view === view)?.label
                            }}
                        </h1>
                        <p class="scope-description">
                            {{
                                folderId != null
                                    ? 'Local organization folder · never a remote mailbox folder'
                                    : accountId == null
                                      ? 'Mail across all enabled accounts'
                                      : selectedAccount?.email_address
                            }}
                        </p>
                        <small v-if="selectedAccount?.enabled === false"
                            >Disabled account · retained mail</small
                        >
                    </div>
                </div>
                <nav
                    v-if="accountId != null && folderId == null"
                    class="account-view-tabs"
                    aria-label="Account views"
                >
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
                <SyncStatus
                    v-if="selectedAccount && folderId == null"
                    :account="selectedAccount"
                    compact
                />
                <p v-else-if="folderId == null" class="mailbox-sync-hint">
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
                <button
                    v-if="selectedAccount?.enabled && selectedAccount.sync_enabled"
                    class="text-button mailbox-refresh"
                    type="button"
                    :disabled="syncPending === selectedAccount.id"
                    @click="$emit('syncNow', selectedAccount.id)"
                >
                    Sync now
                </button>
                <p v-if="syncError" class="form-error" role="alert">{{ syncError }}</p>
                <MessageList
                    :read-change="readChange"
                    :view="view"
                    :account-id="accountId"
                    :folder-id="folderId"
                    :folders="folders"
                    :refresh-version="refreshVersion"
                    @loaded="$emit('mailboxLoaded')"
                    :accounts="accounts"
                    :selected-message-id="selectedMessageId"
                    @select="$emit('select', $event)"
                    @moved="$emit('moved', $event)"
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
                    :folders="folders"
                    @close="$emit('select', null)"
                    @moved="$emit('moved', $event)"
                />
            </section>
        </div>
    </div>
</template>

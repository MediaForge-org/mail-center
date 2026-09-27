<script setup lang="ts">
import { computed, onBeforeUnmount, ref, shallowRef, watch } from 'vue';
import {
    listMessages,
    moveMessageFolder,
    type MailboxView,
    type MessageListItem,
    type MoveChange,
    type ReadChange,
} from '../../api/messages';
import type { AccountSummary } from '../../api/accounts';
import type { Folder } from '../../api/folders';
import FolderMoveMenu from './FolderMoveMenu.vue';

const props = defineProps<{
    view: MailboxView;
    readChange?: ReadChange | null;
    accountId?: number | null;
    folderId?: number | null;
    folders?: Folder[];
    refreshVersion?: number;
    accounts: AccountSummary[];
    selectedMessageId?: number | null;
}>();
const messages = shallowRef<MessageListItem[]>([]);
const cursor = ref<string | null>(null);
const loading = ref(false);
const error = ref(false);
const refreshing = ref(false);
let refreshPending = false;
let refreshFailed = false;
const emit = defineEmits<{ select: [id: number]; loaded: []; moved: [change: MoveChange] }>();
const accountMap = computed(() => new Map(props.accounts.map((account) => [account.id, account])));
const emptyText = computed(() =>
    props.folderId != null
        ? 'This folder is empty.'
        : {
              all: 'No messages yet.',
              inbox: 'Your Inbox is empty.',
              unread: 'No unread messages.',
              sent: 'No sent messages.',
              archive: 'Your Archive is empty.',
          }[props.view],
);
const movingId = ref<number | null>(null);
const moveError = ref('');
async function moveTo(id: number, folderId: number) {
    if (movingId.value !== null) return;
    movingId.value = id;
    moveError.value = '';
    try {
        const change = await moveMessageFolder(id, folderId);
        emit('moved', change);
    } catch {
        moveError.value = 'Unable to move this message. Please try again.';
    } finally {
        movingId.value = null;
    }
}
function dragStart(event: DragEvent, id: number) {
    event.dataTransfer?.setData('application/x-mailcenter-message-id', String(id));
    if (event.dataTransfer) event.dataTransfer.effectAllowed = 'move';
}
// Same ordering as the API: sort_date descending, then id descending.
function isOlder(a: MessageListItem, b: MessageListItem): boolean {
    const da = Date.parse(a.sort_date);
    const db = Date.parse(b.sort_date);
    return da < db || (da === db && a.id < b.id);
}
let controller: AbortController;
let seen = new Set<number>();

const readOverrides = new Map<number, boolean>();
watch(
    () => props.readChange,
    (change) => {
        if (!change) return;
        readOverrides.set(change.id, change.is_read);
        messages.value = messages.value
            .map((m) => (m.id === change.id ? { ...m, is_read: change.is_read } : m))
            .filter((m) => props.view !== 'unread' || !m.is_read);
    },
);
async function loadPage(replace = false) {
    if (loading.value) {
        if (replace) refreshPending = true;
        return;
    }
    replace ||= refreshFailed;
    refreshFailed = false;
    refreshing.value = replace;
    if (replace) readOverrides.clear();
    const active = controller;
    loading.value = true;
    error.value = false;
    try {
        const requestedCursor = replace ? null : cursor.value;
        const firstPage = requestedCursor === null;
        const page = await listMessages(
            props.view,
            requestedCursor,
            active.signal,
            props.accountId,
            props.folderId,
        );
        if (active.signal.aborted) return;
        if (replace) seen = new Set();
        const additions = page.data
            .map((message) =>
                readOverrides.has(message.id)
                    ? { ...message, is_read: readOverrides.get(message.id)! }
                    : message,
            )
            .filter((m) => props.view !== 'unread' || !m.is_read)
            .filter((message) => {
                if (seen.has(message.id)) return false;
                seen.add(message.id);
                return true;
            });
        if (!replace) {
            messages.value = messages.value.concat(additions);
            cursor.value = page.next_cursor;
        } else {
            // Update the newest page in place and keep already-loaded older pages (and scroll position).
            const previous = messages.value;
            const oldest = page.data.at(-1);
            const tail =
                oldest && page.next_cursor !== null && cursor.value !== null
                    ? previous.filter((m) => !seen.has(m.id) && isOlder(m, oldest))
                    : [];
            messages.value = tail.length ? [...additions, ...tail] : additions;
            if (!tail.length) cursor.value = page.next_cursor;
            seen = new Set(messages.value.map((m) => m.id));
        }
        if (firstPage && !replace) emit('loaded');
    } catch {
        if (!active.signal.aborted) {
            error.value = true;
            refreshFailed = replace;
        }
    } finally {
        if (!active.signal.aborted) {
            loading.value = false;
            refreshing.value = false;
            if (refreshPending) {
                refreshPending = false;
                void loadPage(true);
            }
        }
    }
}

watch(
    () => [props.view, props.accountId, props.folderId],
    () => {
        controller?.abort();
        refreshPending = false;
        refreshFailed = false;
        readOverrides.clear();
        controller = new AbortController();
        messages.value = [];
        cursor.value = null;
        seen = new Set();
        loading.value = false;
        void loadPage();
    },
    { immediate: true },
);
// Same-scope invalidations coalesce; never starve an in-flight response or blank visible mail.
watch(
    () => props.refreshVersion,
    () => void loadPage(true),
);
// Also cancel when switching to Accounts or leaving the workspace.
onBeforeUnmount(() => controller.abort());

function dateLabel(value: string): string {
    const date = new Date(value);
    if (Number.isNaN(date.getTime())) return 'Unknown date';
    const now = new Date();
    return date.toDateString() === now.toDateString()
        ? date.toLocaleTimeString(undefined, { hour: 'numeric', minute: '2-digit' })
        : date.toLocaleDateString(undefined, {
              month: 'short',
              day: 'numeric',
              ...(date.getFullYear() !== now.getFullYear() ? { year: 'numeric' as const } : {}),
          });
}
</script>

<template>
    <div class="message-feed" :aria-busy="loading">
        <ul v-if="messages.length" class="message-rows" aria-label="Messages">
            <li v-for="message in messages" :key="message.id" class="message-row-item">
                <button
                    type="button"
                    class="message-row"
                    draggable="true"
                    @dragstart="dragStart($event, message.id)"
                    :class="{
                        'message-unread': !message.is_read,
                        'message-selected': selectedMessageId === message.id,
                    }"
                    :aria-pressed="selectedMessageId === message.id"
                    @click="$emit('select', message.id)"
                >
                    <span class="message-sender" :title="message.from_address">{{
                        message.from_name || message.from_address || 'Unknown sender'
                    }}</span>
                    <span class="message-subject">{{ message.subject || '(No subject)' }}</span>
                    <time
                        class="message-time"
                        :datetime="message.sort_date"
                        :title="new Date(message.sort_date).toLocaleString()"
                        >{{ dateLabel(message.sort_date) }}</time
                    >
                    <span
                        class="message-account"
                        :title="`${accountMap.get(message.mail_account_id)?.display_name || 'Mail account'} · ${accountMap.get(message.mail_account_id)?.email_address || ''}`"
                    >
                        <span class="account-marker" aria-hidden="true"></span
                        >{{
                            accountMap.get(message.mail_account_id)?.short_label ||
                            accountMap.get(message.mail_account_id)?.display_name ||
                            'Mail'
                        }}
                    </span>
                    <span class="message-snippet">{{ message.snippet }}</span>
                    <span class="message-meta">
                        <span v-if="!message.is_read" class="unread-dot" aria-label="Unread"
                            ><span class="sr-only">Unread</span></span
                        >
                        <span
                            v-if="message.has_attachments"
                            aria-label="Has attachments"
                            title="Attachments"
                            >⌁</span
                        >
                        <span v-if="message.is_starred" title="Starred"
                            >★<span class="sr-only">Starred</span></span
                        >
                        <span v-if="message.is_important" title="Important"
                            >!<span class="sr-only">Important</span></span
                        >
                        <span v-if="message.is_done" title="Done"
                            >✓<span class="sr-only">Done</span></span
                        >
                    </span>
                </button>
                <FolderMoveMenu
                    v-if="folders?.length"
                    class="message-row-move"
                    :folders="folders"
                    :current-folder-id="message.folder_id"
                    :disabled="movingId === message.id"
                    label="Move to…"
                    @move="(folderId) => moveTo(message.id, folderId)"
                />
            </li>
        </ul>
        <p v-if="moveError" role="alert" class="form-error">{{ moveError }}</p>
        <div class="message-feed-footer" aria-live="polite">
            <p v-if="loading">
                {{
                    messages.length
                        ? refreshing
                            ? 'Refreshing mailbox…'
                            : 'Loading more…'
                        : 'Loading mailbox…'
                }}
            </p>
            <template v-else-if="error">
                <p role="alert">
                    {{
                        messages.length
                            ? 'Unable to load more messages.'
                            : 'Unable to load this mailbox.'
                    }}
                </p>
                <button class="small-button" type="button" @click="loadPage()">Try again</button>
            </template>
            <p v-else-if="!messages.length">{{ emptyText }}</p>
            <button v-else-if="cursor" class="small-button" type="button" @click="loadPage()">
                Load more
            </button>
            <p v-else>End of messages</p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { listAccounts, type AccountSummary } from '../api/accounts';
import {
    getMailboxCounts,
    type ReadChange,
    type MailboxCounts,
    type MailboxView,
} from '../api/messages';
import { currentUser, logout } from '../api/auth';
import AccountsPanel from '../features/accounts/AccountsPanel.vue';
import MailWorkspace from '../features/workspace/MailWorkspace.vue';

const router = useRouter();
const route = useRoute();
const accounts = ref<AccountSummary[]>([]);
const accountId = computed(() => (route.params.accountId ? Number(route.params.accountId) : null));
const counts = ref<MailboxCounts | null>(null);
const refreshVersion = ref(0);
const readChange = ref<ReadChange | null>(null);
function onReadChanged(change: ReadChange) {
    readChange.value = change;
    if (view.value === 'unread' && !change.is_read) refreshVersion.value++;
    void refreshCounts();
}
let countsController: AbortController | undefined;
async function refreshCounts() {
    countsController?.abort();
    const active = new AbortController();
    countsController = active;
    counts.value = null;
    try {
        const result = await getMailboxCounts(active.signal);
        if (!active.signal.aborted) counts.value = result;
    } catch {
        // Counts are optional; never replace unavailable data with false zeroes.
    }
}
async function accountsChanged() {
    await refreshAccounts();
    refreshVersion.value++;
    void refreshCounts();
}
const section = computed<'mail' | 'accounts'>(() =>
    route.params.view === 'accounts' ? 'accounts' : 'mail',
);
const view = computed<MailboxView>(() =>
    route.params.view === 'inbox' || route.params.view === 'unread' ? route.params.view : 'all',
);
const selectedMessageId = computed(() => {
    const value = route.query.message;
    if (typeof value !== 'string' || !/^[1-9]\d*$/.test(value)) return null;
    const id = Number(value);
    return Number.isSafeInteger(id) ? id : null;
});
function selectMessage(id: number | null) {
    const query = { ...route.query };
    if (id === null) delete query.message;
    else query.message = String(id);
    void router.push({ path: route.path, query });
}
let timer: ReturnType<typeof setTimeout> | undefined;
let accountRequest = 0;
let disposed = false;

async function refreshAccounts() {
    try {
        const request = ++accountRequest;
        const updated = await listAccounts();
        if (request !== accountRequest || disposed) return;
        const synced = updated.some((account) => {
            const previous = accounts.value.find((item) => item.id === account.id);
            return previous && account.last_successful_sync_at !== previous.last_successful_sync_at;
        });
        accounts.value = updated;
        if (synced) {
            refreshVersion.value++;
            void refreshCounts();
        }
    } catch {
        // Keep the last known list; the next poll retries.
    }
}
const name = ref('');
const loading = ref(true);
const error = ref('');

onMounted(async () => {
    try {
        const user = await currentUser();
        if (!user) {
            await router.replace('/login');
            return;
        }
        name.value = user.name;
        await refreshAccounts();
        void refreshCounts();
        schedulePoll();
        document.addEventListener('visibilitychange', onVisibility);
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'Unable to load the workspace.';
    } finally {
        loading.value = false;
    }
});

function schedulePoll() {
    const busy = accounts.value.some(
        (a) => a.enabled && a.sync_enabled && a.sync_status === 'syncing',
    );
    timer = setTimeout(
        async () => {
            if (document.visibilityState !== 'hidden') await refreshAccounts();
            if (!disposed) schedulePoll();
        },
        busy ? 5000 : 30000,
    );
}
function onVisibility() {
    if (document.visibilityState === 'visible') void refreshAccounts();
}
onBeforeUnmount(() => {
    disposed = true;
    accountRequest++;
    document.removeEventListener('visibilitychange', onVisibility);
    clearTimeout(timer);
    countsController?.abort();
});

async function signOut() {
    try {
        await logout();
        await router.replace('/login');
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'Unable to sign out.';
    }
}
</script>

<template>
    <main v-if="loading" class="loading-screen">Opening workspace…</main>
    <main v-else-if="error" class="loading-screen" role="alert">{{ error }}</main>
    <MailWorkspace
        v-else
        :user-name="name"
        :read-change="readChange"
        @read-changed="onReadChanged"
        :accounts="accounts"
        :section="section"
        :view="view"
        :account-id="accountId"
        :counts="counts"
        :refresh-version="refreshVersion"
        @open-account="(id) => router.push(`/mail/account/${id}/all`)"
        @account-view="(target) => router.push(`/mail/account/${accountId}/${target}`)"
        @mailbox-loaded="refreshCounts"
        @refresh-mailbox="refreshVersion++"
        :selected-message-id="selectedMessageId"
        @select="selectMessage"
        @sign-out="signOut"
        @navigate="(target) => router.push(`/mail/${target}`)"
    >
        <template #accounts>
            <AccountsPanel :accounts="accounts" @changed="accountsChanged" />
        </template>
    </MailWorkspace>
</template>

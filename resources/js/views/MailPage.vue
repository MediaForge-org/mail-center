<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { listAccounts, type AccountSummary } from '../api/accounts';
import {
    getMailboxCounts,
    getMailboxVersion,
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
        if (!active.signal.aborted) {
            counts.value = result;
            return true;
        }
    } catch {
        // Counts are optional; never replace unavailable data with false zeroes.
    }
    return false;
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
    ['inbox', 'unread', 'sent', 'archive'].includes(String(route.params.view))
        ? (route.params.view as MailboxView)
        : 'all',
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
        accounts.value = updated;
        return true;
    } catch {
        // Keep the last known list; the next poll retries.
    }
    return false;
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
        await pollChanges();
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

let observedVersion = '0';
let polling = false;
async function pollChanges() {
    if (polling || disposed) return;
    polling = true;
    try {
        // Sample BEFORE loading data. A commit during reload stays visible at the next poll.
        const change = await getMailboxVersion(observedVersion);
        if (disposed) return;
        if (change.invalidate) {
            refreshVersion.value++;
            const results = await Promise.all([refreshAccounts(), refreshCounts()]);
            if (results.every(Boolean)) observedVersion = change.version;
        }
    } catch {
        // Preserve data and retry; an unavailable poll never acknowledges a version.
    } finally {
        polling = false;
    }
}
function schedulePoll() {
    const busy = accounts.value.some(
        (a) => a.enabled && a.sync_enabled && a.sync_status === 'syncing',
    );
    timer = setTimeout(
        async () => {
            if (document.visibilityState !== 'hidden') await pollChanges();
            if (!disposed) schedulePoll();
        },
        busy ? 5000 : 30000,
    );
}
function onVisibility() {
    if (document.visibilityState === 'visible') void pollChanges();
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

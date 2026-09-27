<script setup lang="ts">
import { computed, nextTick, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { syncNow, listAccounts, type AccountSummary, type SyncAccepted } from '../api/accounts';
import {
    getMailboxCounts,
    getMailboxVersion,
    type ReadChange,
    type MailboxCounts,
    type MailboxView,
} from '../api/messages';
import { currentUser, logout } from '../api/auth';
import { listFolders, type Folder } from '../api/folders';
import AccountsPanel from '../features/accounts/AccountsPanel.vue';
import MailWorkspace from '../features/workspace/MailWorkspace.vue';
import { syncPollPolicy } from '../features/workspace/syncPollPolicy';
const syncPolling = syncPollPolicy();
const syncPending = ref<number | null>(null);
const syncError = ref('');
async function requestSync(id: number) {
    syncPending.value = id;
    syncError.value = '';
    try {
        syncAccepted(id, await syncNow(id));
    } catch (cause) {
        syncError.value =
            cause instanceof Error ? cause.message : 'Unable to request synchronization.';
    } finally {
        syncPending.value = null;
    }
}
function syncAccepted(id: number, accepted: SyncAccepted) {
    const account = accounts.value.find((a) => a.id === id);
    if (account)
        account.sync_request = {
            ...accepted,
            completed_generation: account.sync_request?.completed_generation ?? 0,
        };
    syncPolling.accepted(id, accepted.generation);
    clearTimeout(timer);
    schedulePoll();
}

const router = useRouter();
const route = useRoute();
const accounts = ref<AccountSummary[]>([]);
const accountId = computed(() => (route.params.accountId ? Number(route.params.accountId) : null));
const folderId = computed(() => (route.params.folderId ? Number(route.params.folderId) : null));
const folders = ref<Folder[]>([]);
const foldersError = ref('');
async function refreshFolders() {
    try {
        folders.value = await listFolders();
        foldersError.value = '';
    } catch {
        foldersError.value = 'Unable to load folders.';
    }
}
function onFolderMutated() {
    void refreshFolders();
    void refreshCounts();
}
function onMoved() {
    refreshMailbox();
}
const counts = ref<MailboxCounts | null>(null);
const refreshVersion = ref(0);
const readChange = ref<ReadChange | null>(null);
function onReadChanged(change: ReadChange) {
    readChange.value = change;
    if (view.value === 'unread' && !change.is_read) refreshVersion.value++;
    void refreshCounts();
}
// Counts keep their last values while refreshing (never blank the sidebar) and coalesce: one request
// in flight, at most one follow-up for any number of calls made meanwhile.
const lifetime = new AbortController();
let countsInFlight: Promise<boolean> | null = null;
let countsAgain = false;
function refreshCounts(): Promise<boolean> {
    if (countsInFlight) {
        countsAgain = true;
        return countsInFlight;
    }
    countsInFlight = (async () => {
        let ok = false;
        do {
            countsAgain = false;
            try {
                const result = await getMailboxCounts(lifetime.signal);
                if (lifetime.signal.aborted) return false;
                counts.value = result;
                ok = true;
            } catch {
                // Counts are optional; never replace unavailable data with false zeroes.
                ok = false;
            }
        } while (countsAgain && !lifetime.signal.aborted);
        return ok;
    })().finally(() => {
        countsInFlight = null;
    });
    return countsInFlight;
}
function refreshMailbox() {
    refreshVersion.value++;
    void refreshCounts();
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
        void refreshFolders();
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
// A pending sync request means a commit is imminent: poll the one-row endpoint faster, boundedly.
let activeSince = 0;
let polling = false;
async function pollChanges() {
    if (polling || disposed) return;
    polling = true;
    try {
        // Sample BEFORE loading data. A commit during reload stays visible at the next poll.
        const change = await getMailboxVersion(observedVersion);
        if (disposed) return;
        if (change.active) activeSince ||= Date.now();
        else activeSince = 0;
        // Watcher health can change without any mail change (daemon restart): re-read accounts when the
        // server's live lease state disagrees with what this page believes, so a stale "polling" state
        // can never pin the slow fallback cadence.
        const believed = accounts.value.some((a) => a.realtime?.state === 'watching');
        const staleRealtime = change.realtime !== undefined && change.realtime !== believed;
        if (staleRealtime && !change.invalidate) {
            await refreshAccounts();
            clearTimeout(timer);
            schedulePoll();
        }
        if (change.invalidate) {
            refreshVersion.value++;
            // Let the visible list request leave first; accounts/counts/folders follow without delaying rows.
            await nextTick();
            void refreshFolders();
            const results = await Promise.all([refreshAccounts(), refreshCounts()]);
            if (results.every(Boolean)) observedVersion = change.version;
        }
    } catch {
        // Preserve data and retry; an unavailable poll never acknowledges a version.
    } finally {
        polling = false;
    }
}
// A tab that expects automatic sync but is not (yet) watched keeps probing the one-row version
// endpoint every 2 seconds; the full 30s cadence applies only when nothing is expected to sync.
function pollDelay(): number {
    const delay = syncPolling.delay(accounts.value);
    const expectsSync = accounts.value.some((a) => a.enabled && a.sync_enabled);
    if (activeSince && Date.now() - activeSince < 30000) return Math.min(delay, 200);
    return expectsSync ? Math.min(delay, 2000) : delay;
}
function schedulePoll() {
    clearTimeout(timer);
    timer = setTimeout(async () => {
        if (document.visibilityState !== 'hidden') await pollChanges();
        if (!disposed) schedulePoll();
    }, pollDelay());
}
function onVisibility() {
    if (document.visibilityState === 'visible') void pollChanges();
}
onBeforeUnmount(() => {
    disposed = true;
    accountRequest++;
    document.removeEventListener('visibilitychange', onVisibility);
    clearTimeout(timer);
    lifetime.abort();
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
        :sync-pending="syncPending"
        :sync-error="syncError"
        @sync-now="requestSync"
        @read-changed="onReadChanged"
        :accounts="accounts"
        :section="section"
        :view="view"
        :account-id="accountId"
        :folder-id="folderId"
        :folders="folders"
        :folders-error="foldersError"
        :counts="counts"
        :refresh-version="refreshVersion"
        @open-account="(id) => router.push(`/mail/account/${id}/all`)"
        @account-view="(target) => router.push(`/mail/account/${accountId}/${target}`)"
        @open-folder="(id) => router.push(`/mail/folder/${id}`)"
        @folder-mutated="onFolderMutated"
        @folder-deleted="(id) => folderId === id && router.push('/mail/inbox')"
        @moved="onMoved"
        @mailbox-loaded="refreshCounts"
        @refresh-mailbox="refreshMailbox"
        :selected-message-id="selectedMessageId"
        @select="selectMessage"
        @sign-out="signOut"
        @navigate="(target) => router.push(`/mail/${target}`)"
    >
        <template #accounts>
            <AccountsPanel
                :accounts="accounts"
                @changed="accountsChanged"
                @sync-accepted="syncAccepted"
            />
        </template>
    </MailWorkspace>
</template>

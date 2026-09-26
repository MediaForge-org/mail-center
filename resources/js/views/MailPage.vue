<script setup lang="ts">
import { computed, onBeforeUnmount, onMounted, ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { listAccounts, type AccountSummary } from '../api/accounts';
import { getMailboxCounts, type MailboxCounts, type MailboxView } from '../api/messages';
import { currentUser, logout } from '../api/auth';
import AccountsPanel from '../features/accounts/AccountsPanel.vue';
import MailWorkspace from '../features/workspace/MailWorkspace.vue';

const router = useRouter();
const route = useRoute();
const accounts = ref<AccountSummary[]>([]);
const accountId = computed(() => (route.params.accountId ? Number(route.params.accountId) : null));
const counts = ref<MailboxCounts | null>(null);
const refreshVersion = ref(0);
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
let timer: ReturnType<typeof setInterval> | undefined;

async function refreshAccounts() {
    try {
        accounts.value = await listAccounts();
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
        timer = setInterval(() => {
            const busy = accounts.value.some((a) =>
                ['syncing', 'never_synced'].includes(a.sync_status),
            );
            if (busy || section.value === 'accounts') void refreshAccounts();
        }, 5000);
    } catch (cause) {
        error.value = cause instanceof Error ? cause.message : 'Unable to load the workspace.';
    } finally {
        loading.value = false;
    }
});

onBeforeUnmount(() => {
    clearInterval(timer);
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
        :accounts="accounts"
        :section="section"
        :view="view"
        :account-id="accountId"
        :counts="counts"
        :refresh-version="refreshVersion"
        @open-account="(id) => router.push(`/mail/account/${id}`)"
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

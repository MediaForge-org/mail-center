<script setup lang="ts">
import { reactive, ref, watch } from 'vue';
import {
    createAccount,
    removeAccount,
    replacePassword,
    syncNow,
    testConnection,
    updateAccount,
    type AccountSummary,
} from '../../api/accounts';
import { statusLabels, statusTone } from './statusLabel';

defineProps<{ accounts: AccountSummary[] }>();
const emit = defineEmits<{ changed: [] }>();

const blank = () => ({
    display_name: '',
    email_address: '',
    host: '',
    port: 993,
    security: 'tls' as 'tls' | 'starttls',
    username: '',
    password: '',
});
const form = reactive(blank());
const pending = ref(false);
const message = ref('');
const failed = ref(false);
const adding = ref(false);
const testState = ref<'idle' | 'pending' | 'success' | 'failure'>('idle');
const testText = ref('');
watch(
    () => [form.host, form.port, form.security, form.username, form.password],
    () => {
        testState.value = 'idle';
        testText.value = '';
    },
);
const editing = reactive<{ id: number | null; mode: 'password' | 'remove' | null }>({
    id: null,
    mode: null,
});
const secret = reactive({ password: '', current: '' });

function report(text: string, isError = false) {
    message.value = text;
    failed.value = isError;
}

async function run(action: () => Promise<void>, success = '') {
    pending.value = true;
    report('');
    try {
        await action();
        if (success) report(success);
        emit('changed');
    } catch (cause) {
        report(cause instanceof Error ? cause.message : 'Something went wrong.', true);
    } finally {
        pending.value = false;
    }
}

function onSecurity() {
    form.port = form.security === 'tls' ? 993 : 143;
}

async function test() {
    testState.value = 'pending';
    testText.value = 'Testing connection…';
    try {
        const result = await testConnection({
            host: form.host,
            port: form.port,
            security: form.security,
            username: form.username,
            password: form.password,
        });
        testState.value = result.ok ? 'success' : 'failure';
        testText.value = result.message;
    } catch (cause) {
        testState.value = 'failure';
        testText.value = cause instanceof Error ? cause.message : 'The connection test failed.';
    }
}

async function add() {
    await run(async () => {
        await createAccount({ ...form });
        Object.assign(form, blank());
        adding.value = false;
    }, 'Account added. The first synchronization is starting.');
}

function open(id: number, mode: 'password' | 'remove') {
    editing.id = id;
    editing.mode = mode;
    secret.password = '';
    secret.current = '';
}

function close() {
    editing.id = null;
    editing.mode = null;
    secret.password = '';
    secret.current = '';
}

async function submitSecret(account: AccountSummary) {
    const mode = editing.mode;
    await run(
        async () => {
            if (mode === 'password')
                await replacePassword(account.id, secret.password, secret.current);
            else await removeAccount(account.id, secret.current);
            close();
        },
        mode === 'password'
            ? 'Password saved. Synchronization will resume.'
            : 'Account removed. Remote mail was not changed.',
    );
}
</script>

<template>
    <div class="accounts-panel" data-testid="accounts-panel">
        <div class="pane-toolbar">
            <div>
                <p class="eyebrow">SETTINGS</p>
                <h1>Mail accounts</h1>
            </div>
            <button class="small-button" type="button" @click="adding = !adding">
                {{ adding ? 'Cancel' : 'Add account' }}
            </button>
        </div>

        <p v-if="message" class="panel-message" :class="{ 'form-error': failed }" role="status">
            {{ message }}
        </p>

        <form v-if="adding" class="auth-form account-form" @submit.prevent="add">
            <label for="acc-name">Display name</label>
            <input id="acc-name" v-model="form.display_name" maxlength="120" required />
            <label for="acc-email">Email address</label>
            <input id="acc-email" v-model="form.email_address" type="email" required />
            <label for="acc-host">IMAP server</label>
            <input id="acc-host" v-model="form.host" placeholder="imap.example.com" required />
            <label for="acc-security">Security</label>
            <select id="acc-security" v-model="form.security" @change="onSecurity">
                <option value="tls">TLS (port 993)</option>
                <option value="starttls">STARTTLS (port 143)</option>
            </select>
            <label for="acc-port">Port</label>
            <input id="acc-port" v-model.number="form.port" type="number" required />
            <label for="acc-user">Username</label>
            <input id="acc-user" v-model="form.username" autocomplete="off" required />
            <label for="acc-pass">Password</label>
            <input
                id="acc-pass"
                v-model="form.password"
                type="password"
                autocomplete="new-password"
                required
            />
            <p class="muted">
                The certificate is always verified. The password is stored encrypted and is never
                shown again. MailCenter only reads mail; it never moves or deletes it.
            </p>
            <div class="button-row">
                <button
                    class="small-button"
                    type="button"
                    :disabled="pending || testState === 'pending'"
                    @click="test"
                >
                    {{ testState === 'pending' ? 'Testing…' : 'Test connection' }}
                </button>
                <span
                    v-if="testState !== 'idle'"
                    class="test-result"
                    :class="`test-${testState}`"
                    role="status"
                    data-testid="test-result"
                >
                    <span aria-hidden="true">{{
                        testState === 'success' ? '✓' : testState === 'failure' ? '✕' : '…'
                    }}</span>
                    {{ testText }}
                </span>
                <button class="primary-button inline" :disabled="pending" type="submit">
                    Add account
                </button>
            </div>
        </form>

        <p v-if="accounts.length === 0 && !adding" class="sidebar-hint padded">
            No account connected
        </p>

        <ul class="account-list">
            <li v-for="account in accounts" :key="account.id" class="account-card">
                <div class="account-head">
                    <div>
                        <strong>{{ account.display_name }}</strong>
                        <span class="muted">
                            {{ account.email_address }} · {{ account.incoming.host }}:{{
                                account.incoming.port
                            }}
                        </span>
                    </div>
                    <span class="status-pill" :class="`tone-${statusTone(account.sync_status)}`">
                        {{ account.sync_enabled ? statusLabels[account.sync_status] : 'Sync off' }}
                    </span>
                </div>
                <p class="muted">
                    {{ account.synced_message_count }} messages stored
                    <template v-if="account.last_successful_sync_at">
                        · last synced
                        {{ new Date(account.last_successful_sync_at).toLocaleString() }}
                    </template>
                    <template v-if="account.quarantined_message_count > 0">
                        · {{ account.quarantined_message_count }} message(s) could not be stored
                    </template>
                </p>
                <p v-if="account.last_error_message" class="form-error" role="alert">
                    {{ account.last_error_message }}
                </p>
                <label class="seen-mirroring-setting">
                    <input
                        type="checkbox"
                        :checked="account.write_back_seen"
                        :disabled="pending"
                        @change="
                            run(() =>
                                updateAccount(account.id, {
                                    write_back_seen: !account.write_back_seen,
                                }),
                            )
                        "
                    />
                    Mirror read state to IMAP
                </label>
                <p class="muted">
                    Off keeps read changes local. Enabling adopts the next observed server state;
                    later local actions take precedence.
                </p>
                <p v-if="account.seen_writeback_error" class="form-error">
                    Remote read updates need attention. Local read changes remain saved.
                </p>
                <div class="button-row">
                    <button
                        class="small-button"
                        type="button"
                        :disabled="pending || !account.sync_enabled"
                        @click="run(() => syncNow(account.id), 'Synchronization queued.')"
                    >
                        Sync now
                    </button>
                    <button
                        class="small-button"
                        type="button"
                        :disabled="pending"
                        @click="
                            run(() =>
                                updateAccount(account.id, { sync_enabled: !account.sync_enabled }),
                            )
                        "
                    >
                        {{ account.sync_enabled ? 'Pause sync' : 'Resume sync' }}
                    </button>
                    <button
                        class="small-button"
                        type="button"
                        @click="open(account.id, 'password')"
                    >
                        Change password
                    </button>
                    <button
                        class="small-button danger"
                        type="button"
                        @click="open(account.id, 'remove')"
                    >
                        Remove
                    </button>
                </div>
                <form
                    v-if="editing.id === account.id"
                    class="auth-form account-form"
                    @submit.prevent="submitSecret(account)"
                >
                    <template v-if="editing.mode === 'password'">
                        <label :for="`np-${account.id}`">New mailbox password</label>
                        <input
                            :id="`np-${account.id}`"
                            v-model="secret.password"
                            type="password"
                            autocomplete="new-password"
                            required
                        />
                    </template>
                    <p v-else class="muted">
                        This removes the account and its stored password from MailCenter. Mail on
                        the server is not touched.
                    </p>
                    <label :for="`cp-${account.id}`">Your MailCenter password</label>
                    <input
                        :id="`cp-${account.id}`"
                        v-model="secret.current"
                        type="password"
                        autocomplete="current-password"
                        required
                    />
                    <div class="button-row">
                        <button class="small-button" type="button" @click="close">Cancel</button>
                        <button class="primary-button inline" :disabled="pending" type="submit">
                            {{ editing.mode === 'password' ? 'Save password' : 'Remove account' }}
                        </button>
                    </div>
                </form>
            </li>
        </ul>
    </div>
</template>

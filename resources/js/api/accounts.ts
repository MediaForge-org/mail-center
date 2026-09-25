import { request } from './http';

export type SyncStatus =
    'never_synced' | 'syncing' | 'idle' | 'backing_off' | 'auth_failed' | 'error';

export type AccountSummary = {
    id: number;
    display_name: string;
    email_address: string;
    short_label: string;
    incoming: { host: string; port: number; security: 'tls' | 'starttls'; username: string };
    enabled: boolean;
    sync_enabled: boolean;
    sync_status: SyncStatus;
    next_sync_at: string | null;
    last_successful_sync_at: string | null;
    last_error_code: string | null;
    last_error_message: string | null;
    has_password: boolean;
    synced_message_count: number;
    quarantined_message_count: number;
};

export type NewAccount = {
    display_name: string;
    email_address: string;
    host: string;
    port: number;
    security: 'tls' | 'starttls';
    username: string;
    password: string;
};

export class ApiError extends Error {}

/** Turns an error response into a message that never includes submitted values. */
async function fail(response: Response, fallback: string): Promise<never> {
    let message = fallback;
    try {
        const body = (await response.json()) as {
            message?: string;
            errors?: Record<string, string[]>;
        };
        message = Object.values(body.errors ?? {})[0]?.[0] ?? body.message ?? fallback;
    } catch {
        // Keep the generic message.
    }
    if (response.status === 429) message = 'Too many requests. Please wait a moment.';
    throw new ApiError(message);
}

const json = { 'Content-Type': 'application/json' };

export async function listAccounts(): Promise<AccountSummary[]> {
    const response = await request('/api/accounts');
    if (!response.ok) return fail(response, 'Unable to load accounts.');
    return ((await response.json()) as { data: AccountSummary[] }).data;
}

export async function createAccount(account: NewAccount): Promise<AccountSummary> {
    const response = await request('/api/accounts', {
        method: 'POST',
        headers: json,
        body: JSON.stringify(account),
    });
    if (!response.ok) return fail(response, 'Unable to add the account.');
    return ((await response.json()) as { data: AccountSummary }).data;
}

export async function testConnection(
    settings: Omit<NewAccount, 'display_name' | 'email_address'>,
    wait: (ms: number) => Promise<void> = (ms) => new Promise((r) => setTimeout(r, ms)),
): Promise<{ ok: boolean; message: string }> {
    const started = await request('/api/accounts/test-connection', {
        method: 'POST',
        headers: json,
        body: JSON.stringify(settings),
    });
    if (!started.ok) return fail(started, 'Unable to start the connection test.');
    const id = ((await started.json()) as { data: { id: number } }).data.id;
    for (let attempt = 0; attempt < 30; attempt++) {
        await wait(1000);
        const poll = await request(`/api/connection-tests/${id}`);
        if (!poll.ok) return fail(poll, 'Unable to read the connection test.');
        const { data } = (await poll.json()) as {
            data: { status: string; message: string | null };
        };
        if (data.status === 'succeeded') return { ok: true, message: 'Connection succeeded.' };
        if (data.status === 'failed' || data.status === 'expired') {
            return { ok: false, message: data.message ?? 'The connection test failed.' };
        }
    }
    return { ok: false, message: 'The connection test timed out.' };
}

export async function updateAccount(
    id: number,
    changes: Partial<Pick<AccountSummary, 'enabled' | 'sync_enabled' | 'display_name'>>,
): Promise<void> {
    const response = await request(`/api/accounts/${id}`, {
        method: 'PATCH',
        headers: json,
        body: JSON.stringify(changes),
    });
    if (!response.ok) return fail(response, 'Unable to update the account.');
}

export async function syncNow(id: number): Promise<void> {
    const response = await request(`/api/accounts/${id}/sync`, { method: 'POST' });
    if (!response.ok) return fail(response, 'Unable to start synchronization.');
}

export async function replacePassword(
    id: number,
    password: string,
    currentPassword: string,
): Promise<void> {
    const response = await request(`/api/accounts/${id}/credentials`, {
        method: 'PUT',
        headers: json,
        body: JSON.stringify({ password, current_password: currentPassword }),
    });
    if (!response.ok) return fail(response, 'Unable to save the password.');
}

export async function removeAccount(id: number, currentPassword: string): Promise<void> {
    const response = await request(`/api/accounts/${id}`, {
        method: 'DELETE',
        headers: json,
        body: JSON.stringify({ current_password: currentPassword }),
    });
    if (!response.ok) return fail(response, 'Unable to remove the account.');
}

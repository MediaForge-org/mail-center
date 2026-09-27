import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import App from '../../resources/js/App.vue';
import { routes } from '../../resources/js/router';

const wrappers: ReturnType<typeof mount>[] = [];
afterEach(() => {
    wrappers.forEach((w) => w.unmount());
    wrappers.length = 0;
    vi.unstubAllGlobals();
    vi.useRealTimers();
});

const row = (id: number) => ({
    id,
    mail_account_id: 1,
    subject: 'Mail ' + id,
    from_name: 'Sender',
    from_address: 'sender@example.test',
    snippet: 'Preview',
    sort_date: '2026-01-01T00:00:00Z',
    is_read: false,
    to: [],
});

async function open(state: {
    realtime: 'watching' | 'polling';
    serverRealtime: boolean;
    active?: boolean;
}) {
    let version = 1;
    let rows = [3, 2, 1];
    const calls: string[] = [];
    const signals: AbortSignal[] = [];
    const account = () => ({
        id: 1,
        display_name: 'Work',
        email_address: 'me@example.test',
        enabled: true,
        sync_enabled: true,
        sync_status: 'idle',
        sync_interval_seconds: 180,
        last_successful_sync_at: '2026-01-01T00:00:00Z',
        incoming: { host: 'imap.example.test', port: 993, security: 'tls' },
        realtime: { state: state.realtime, folders: ['INBOX'], roles: ['inbox'] },
    });
    const fetchMock = vi.fn((url: string, init?: RequestInit) => {
        calls.push(url);
        if (init?.signal) signals.push(init.signal);
        let body: unknown = { data: [] };
        let delay = 0;
        if (url === '/api/me') body = { id: 1, name: 'Operator' };
        if (url === '/api/accounts') body = { data: [account()] };
        if (url === '/api/mailbox-counts') {
            delay = 200;
            body = {
                views: { inbox: { total: rows.length, unread: rows.length } },
                accounts: { '1': { total: rows.length, unread: 0 } },
            };
        }
        if (url.startsWith('/api/messages?')) {
            delay = 300;
            body = { data: rows.map(row), next_cursor: null };
        }
        if (url.startsWith('/api/changes')) {
            const since = new URL(url, 'http://x').searchParams.get('since');
            body = {
                version: String(version),
                invalidate: since !== String(version),
                realtime: state.serverRealtime,
                active: state.active ?? false,
            };
        }
        return new Promise<Response>((resolve) =>
            setTimeout(() => resolve(new Response(JSON.stringify(body))), delay),
        );
    });
    vi.stubGlobal('fetch', fetchMock);
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/mail/inbox');
    await router.isReady();
    const wrapper = mount(App, { global: { plugins: [router] } });
    wrappers.push(wrapper);
    await vi.advanceTimersByTimeAsync(1500);
    await flushPromises();
    return {
        wrapper,
        calls,
        signals,
        commit: () => {
            version++;
            rows = [rows[0] + 1, ...rows];
        },
    };
}

it('coalesces a burst of committed mailbox versions without blanking rows, counts or aborting requests', async () => {
    vi.useFakeTimers();
    const { wrapper, calls, signals, commit } = await open({
        realtime: 'watching',
        serverRealtime: true,
    });
    expect(wrapper.findAll('.message-row')).toHaveLength(3);
    const before = calls.filter((url) => url.startsWith('/api/messages?')).length;
    const countText = () => wrapper.findAll('.mailbox-count').length;
    const baselineCounts = countText();
    expect(baselineCounts).toBeGreaterThan(0);
    let minRows = 99;
    let minCounts = 99;
    let loadingSeen = false;
    for (let step = 0; step < 40; step++) {
        if (step < 5) commit(); // five commits in quick succession
        await vi.advanceTimersByTimeAsync(100);
        minRows = Math.min(minRows, wrapper.findAll('.message-row').length);
        minCounts = Math.min(minCounts, countText());
        loadingSeen ||= wrapper.text().includes('Loading mailbox');
    }
    await flushPromises();
    expect(minRows).toBeGreaterThanOrEqual(3);
    expect(minCounts).toBe(baselineCounts);
    expect(loadingSeen).toBe(false);
    expect(wrapper.findAll('.message-row')).toHaveLength(8);
    const refreshes = calls.filter((url) => url.startsWith('/api/messages?')).length - before;
    expect(refreshes).toBeGreaterThan(0);
    expect(refreshes).toBeLessThanOrEqual(5);
    expect(signals.some((signal) => signal.aborted)).toBe(false);
});

it('recovers from a stale polling state as soon as the server reports live watcher leases', async () => {
    vi.useFakeTimers();
    const state = { realtime: 'polling' as const, serverRealtime: false };
    const { calls } = await open(state);
    const accountsBefore = calls.filter((url) => url === '/api/accounts').length;
    // Watcher starts after the page loaded: only the cheap version endpoint reveals it.
    state.serverRealtime = true;
    state.realtime = 'watching' as never;
    await vi.advanceTimersByTimeAsync(2500);
    await flushPromises();
    expect(calls.filter((url) => url === '/api/accounts').length).toBeGreaterThan(accountsBefore);
    const polls = calls.filter((url) => url.startsWith('/api/changes')).length;
    await vi.advanceTimersByTimeAsync(2000);
    expect(
        calls.filter((url) => url.startsWith('/api/changes')).length - polls,
    ).toBeGreaterThanOrEqual(3);
});

it('polls faster only while a sync request is pending and returns to the realtime cadence after', async () => {
    vi.useFakeTimers();
    const state = { realtime: 'watching' as const, serverRealtime: true, active: false };
    const { calls } = await open(state);
    const polls = () => calls.filter((url) => url.startsWith('/api/changes')).length;
    let base = polls();
    await vi.advanceTimersByTimeAsync(2000);
    const idleRate = polls() - base;
    expect(idleRate).toBeLessThanOrEqual(5);
    state.active = true;
    await vi.advanceTimersByTimeAsync(700);
    base = polls();
    await vi.advanceTimersByTimeAsync(2000);
    expect(polls() - base).toBeGreaterThanOrEqual(8);
    state.active = false;
    await vi.advanceTimersByTimeAsync(700);
    base = polls();
    await vi.advanceTimersByTimeAsync(2000);
    expect(polls() - base).toBeLessThanOrEqual(5);
});

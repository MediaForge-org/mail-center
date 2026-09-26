import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import {
    createAccount,
    testConnection,
    type AccountSummary,
} from '../../resources/js/api/accounts';
import AccountsPanel from '../../resources/js/features/accounts/AccountsPanel.vue';
import MailWorkspace from '../../resources/js/features/workspace/MailWorkspace.vue';

const account = (over: Partial<AccountSummary> = {}): AccountSummary => ({
    id: 1,
    display_name: 'Work',
    email_address: 'me@example.test',
    short_label: 'WO',
    sync_interval_seconds: 180,
    incoming: { host: 'imap.example.test', port: 993, security: 'tls', username: 'me' },
    enabled: true,
    sync_enabled: true,
    write_back_seen: false,
    seen_writeback_error: null,
    sync_status: 'idle',
    next_sync_at: null,
    last_successful_sync_at: '2026-01-01T10:00:00Z',
    last_error_code: null,
    last_error_message: null,
    has_password: true,
    synced_message_count: 12,
    quarantined_message_count: 0,
    ...over,
});

function reply(status: number, body: unknown = {}) {
    return Promise.resolve(new Response(JSON.stringify(body), { status }));
}

afterEach(() => vi.unstubAllGlobals());

describe('accounts in the workspace shell', () => {
    it('lists accounts with their status in the sidebar and offers account management', async () => {
        const wrapper = mount(MailWorkspace, {
            props: {
                userName: 'Op',
                accounts: [
                    account(),
                    account({ id: 2, display_name: 'Ops', sync_status: 'auth_failed' }),
                ],
            },
        });

        expect(wrapper.text()).toContain('Work');
        expect(wrapper.text()).toContain('Ops');
        expect(wrapper.text()).not.toContain('No account connected');
        await wrapper
            .findAll('button')
            .find((button) => button.text() === 'Manage accounts')!
            .trigger('click');
        expect(wrapper.emitted('navigate')?.[0]).toEqual(['accounts']);
    });

    it('swaps the message panes for the accounts slot without building an inbox', () => {
        const wrapper = mount(MailWorkspace, {
            props: { userName: 'Op', section: 'accounts' },
            slots: { accounts: '<p id="slot">panel</p>' },
        });

        expect(wrapper.find('#slot').exists()).toBe(true);
        expect(wrapper.find('[data-testid="center-pane"]').exists()).toBe(false);
        expect(wrapper.find('[data-testid="right-pane"]').exists()).toBe(false);
    });
});

describe('accounts panel', () => {
    it('shows sync status, stored count, quarantine notice and connection errors', () => {
        const wrapper = mount(AccountsPanel, {
            props: {
                accounts: [
                    account({
                        sync_status: 'auth_failed',
                        last_error_message: 'The server rejected the username or password.',
                        quarantined_message_count: 2,
                    }),
                ],
            },
        });

        expect(wrapper.text()).toContain('Password rejected');
        expect(wrapper.text()).toContain('12 messages stored');
        expect(wrapper.text()).toContain('2 message(s) could not be stored');
        expect(wrapper.find('[role="alert"]').text()).toContain('rejected the username');
    });

    it('triggers a manual sync and toggles synchronization', async () => {
        const fetchMock = vi.fn(() => reply(202));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(AccountsPanel, { props: { accounts: [account()] } });

        const buttons = wrapper.findAll('button');
        await buttons.find((b) => b.text() === 'Sync now')!.trigger('click');
        await flushPromises();
        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Pause sync')!
            .trigger('click');
        await flushPromises();

        const calls = fetchMock.mock.calls as unknown as [string, RequestInit][];
        expect(calls[0][0]).toBe('/api/accounts/1/sync');
        expect(calls[1][0]).toBe('/api/accounts/1');
        expect(calls[1][1].method).toBe('PATCH');
        expect(JSON.parse(calls[1][1].body as string)).toEqual({ sync_enabled: false });
        expect(wrapper.emitted('changed')).toHaveLength(2);
    });

    it('adds an account, clears the password from the form, and never displays it back', async () => {
        const fetchMock = vi.fn(() => reply(201, { data: account() }));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(AccountsPanel, { props: { accounts: [] } });

        await wrapper.find('button.small-button').trigger('click');
        await wrapper.find('#acc-name').setValue('Work');
        await wrapper.find('#acc-email').setValue('me@example.test');
        await wrapper.find('#acc-host').setValue('imap.example.test');
        await wrapper.find('#acc-user').setValue('me');
        await wrapper.find('#acc-pass').setValue('very-secret-password');
        expect((wrapper.find('#acc-pass').element as HTMLInputElement).type).toBe('password');
        await wrapper.find('form').trigger('submit');
        await flushPromises();

        const [url, init] = (fetchMock.mock.calls as unknown as [string, RequestInit][])[0];
        expect(url).toBe('/api/accounts');
        expect(JSON.parse(init.body as string)).toMatchObject({
            host: 'imap.example.test',
            port: 993,
        });
        expect(wrapper.html()).not.toContain('very-secret-password');
        expect(wrapper.emitted('changed')).toHaveLength(1);
    });

    it('requires the current password to remove an account and explains remote mail is untouched', async () => {
        const fetchMock = vi.fn(() => reply(200));
        vi.stubGlobal('fetch', fetchMock);
        const wrapper = mount(AccountsPanel, { props: { accounts: [account()] } });

        await wrapper
            .findAll('button')
            .find((b) => b.text() === 'Remove')!
            .trigger('click');
        expect(wrapper.text()).toContain('Mail on the server is not touched');
        await wrapper.find('#cp-1').setValue('my-login-password');
        await wrapper.findAll('form').at(-1)!.trigger('submit');
        await flushPromises();

        const [url, init] = (fetchMock.mock.calls as unknown as [string, RequestInit][])[0];
        expect(url).toBe('/api/accounts/1');
        expect(init.method).toBe('DELETE');
        expect(JSON.parse(init.body as string)).toEqual({ current_password: 'my-login-password' });
    });

    it('shows server validation errors without echoing submitted secrets', async () => {
        vi.stubGlobal(
            'fetch',
            vi.fn(() =>
                reply(422, {
                    message: 'x',
                    errors: { host: ['The host field format is invalid.'] },
                }),
            ),
        );
        await expect(
            createAccount({
                display_name: 'a',
                email_address: 'a@b.test',
                host: 'bad host',
                port: 993,
                security: 'tls',
                username: 'u',
                password: 'pw-that-must-not-leak',
            }),
        ).rejects.toThrow('The host field format is invalid.');
    });
});

describe('connection test polling', () => {
    it('polls until the queued test finishes and reports sanitized failures', async () => {
        const responses = [
            reply(202, { data: { id: 7, status: 'pending' } }),
            reply(200, { data: { status: 'running', message: null } }),
            reply(200, {
                data: {
                    status: 'failed',
                    message: 'The server rejected the username or password.',
                },
            }),
        ];
        vi.stubGlobal(
            'fetch',
            vi.fn(() => responses.shift()!),
        );

        const result = await testConnection(
            { host: 'h', port: 993, security: 'tls', username: 'u', password: 'p' },
            () => Promise.resolve(),
        );

        expect(result).toEqual({
            ok: false,
            message: 'The server rejected the username or password.',
        });
    });
});

describe('test connection feedback', () => {
    async function openForm(fetchMock: ReturnType<typeof vi.fn>) {
        vi.stubGlobal('fetch', fetchMock);
        vi.useFakeTimers();
        const wrapper = mount(AccountsPanel, { props: { accounts: [] } });
        await wrapper.find('button.small-button').trigger('click');
        await wrapper.find('#acc-host').setValue('imap.example.test');
        await wrapper.find('#acc-user').setValue('me');
        await wrapper.find('#acc-pass').setValue('pw');
        const button = () => wrapper.findAll('button').find((b) => /Test/.test(b.text()))!;
        return { wrapper, button };
    }

    afterEach(() => vi.useRealTimers());

    it('shows pending, then success', async () => {
        const responses = [
            reply(202, { data: { id: 1, status: 'pending' } }),
            reply(200, { data: { status: 'succeeded', message: 'Connection succeeded.' } }),
        ];
        const { wrapper, button } = await openForm(vi.fn(() => responses.shift()!));

        await button().trigger('click');
        expect(wrapper.find('[data-testid="test-result"]').text()).toContain('Testing connection');
        expect(button().text()).toBe('Testing…');
        expect(button().attributes('disabled')).toBeDefined();

        await vi.advanceTimersByTimeAsync(1000);
        await flushPromises();
        const result = wrapper.find('[data-testid="test-result"]');
        expect(result.classes()).toContain('test-success');
        expect(result.text()).toContain('Connection succeeded.');
        expect(button().text()).toBe('Test connection');
    });

    it('shows a failure message and clears it when the settings change', async () => {
        const responses = [
            reply(202, { data: { id: 1, status: 'pending' } }),
            reply(200, {
                data: {
                    status: 'failed',
                    message: 'The server rejected the username or password.',
                },
            }),
        ];
        const { wrapper, button } = await openForm(vi.fn(() => responses.shift()!));

        await button().trigger('click');
        await vi.advanceTimersByTimeAsync(1000);
        await flushPromises();
        const result = wrapper.find('[data-testid="test-result"]');
        expect(result.classes()).toContain('test-failure');
        expect(result.text()).toContain('rejected the username');

        await wrapper.find('#acc-pass').setValue('changed');
        expect(wrapper.find('[data-testid="test-result"]').exists()).toBe(false);
    });

    it('shows a failure when the test cannot be started', async () => {
        const { wrapper, button } = await openForm(
            vi.fn(() => reply(422, { errors: { host: ['The host field format is invalid.'] } })),
        );

        await button().trigger('click');
        await flushPromises();

        expect(wrapper.find('[data-testid="test-result"]').classes()).toContain('test-failure');
        expect(wrapper.text()).toContain('The host field format is invalid.');
    });
});

it('explicitly toggles Seen mirroring without enabling it by default', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValue(
            new Response(JSON.stringify({ data: account({ write_back_seen: true }) })),
        );
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(AccountsPanel, { props: { accounts: [account()] } });
    const toggle = wrapper.find('.seen-mirroring-setting input');
    expect((toggle.element as HTMLInputElement).checked).toBe(false);
    await toggle.setValue(true);
    await flushPromises();
    expect(fetchMock).toHaveBeenCalledWith(
        '/api/accounts/1',
        expect.objectContaining({
            method: 'PATCH',
            body: JSON.stringify({ write_back_seen: true }),
        }),
    );
    expect(wrapper.emitted('changed')).toHaveLength(1);
    wrapper.unmount();
});

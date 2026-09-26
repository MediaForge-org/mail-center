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
});
const counts = {
    views: {
        all: { total: 150, unread: 12 },
        inbox: { total: 90, unread: 9 },
        unread: { total: 11, unread: 11 },
    },
    accounts: { '1': { total: 150, unread: 12 }, '2': { total: 40, unread: 4 } },
};
const message = (id: number, account: number) => ({
    id,
    mail_account_id: account,
    subject: 'Mail ' + id,
    from_name: 'Sender',
    from_address: 'sender@example.test',
    snippet: 'Preview',
    sort_date: '2026-01-01T00:00:00Z',
    is_read: false,
});
async function open(path: string, failCounts = false) {
    const fetchMock = vi.fn((url: string) => {
        let body: unknown = { data: [] };
        if (url === '/api/me') body = { id: 1, name: 'Operator' };
        if (url === '/api/accounts')
            body = {
                data: [
                    {
                        id: 1,
                        display_name: 'Work',
                        enabled: true,
                        sync_status: 'idle',
                        incoming: { host: 'imap.example.test', port: 993, security: 'tls' },
                    },
                    {
                        id: 2,
                        display_name: 'Old mail',
                        enabled: false,
                        sync_status: 'idle',
                        incoming: { host: 'imap.example.test', port: 993, security: 'tls' },
                    },
                ],
            };
        if (url === '/api/mailbox-counts') body = counts;
        if (url.startsWith('/api/messages?'))
            body = {
                data: [
                    message(
                        url.includes('account_id=2') ? 2 : 1,
                        url.includes('account_id=2') ? 2 : 1,
                    ),
                ],
                next_cursor: 'next',
            };
        return Promise.resolve(
            new Response(JSON.stringify(body), {
                status: failCounts && url === '/api/mailbox-counts' ? 500 : 200,
            }),
        );
    });
    vi.stubGlobal('fetch', fetchMock);
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push(path);
    await router.isReady();
    const wrapper = mount(App, { global: { plugins: [router] } });
    wrappers.push(wrapper);
    await flushPromises();
    return { wrapper, router, fetchMock };
}

it('navigates account mail, restores deep links/history and displays authoritative counts', async () => {
    const { wrapper, router, fetchMock } = await open('/mail/account/2');
    expect(wrapper.find('.account-nav-row[aria-current="page"]').text()).toContain('Old mail');
    expect(wrapper.find('.account-disabled').text()).toContain('Disabled');
    expect(wrapper.find('.pane-toolbar').text()).toContain('Disabled account');
    expect(wrapper.findAll('.mailbox-count').map((e) => e.text())).toContain('40' + '4 unread');
    expect(fetchMock).toHaveBeenCalledWith(
        '/api/messages?view=all&limit=50&account_id=2',
        expect.anything(),
    );
    await wrapper.findAll('.account-nav-row')[0].trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/mail/account/1');
    expect(wrapper.find('.account-nav-row[aria-current="page"]').text()).toContain('Work');
    router.back();
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/mail/account/2');
    router.forward();
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/mail/account/1');
    await wrapper.find('.message-feed-footer button').trigger('click');
    await flushPromises();
    expect(fetchMock).toHaveBeenCalledWith(
        '/api/messages?view=all&limit=50&account_id=1&cursor=next',
        expect.anything(),
    );
    await wrapper
        .findAll('.nav-button')
        .find((b) => b.text().includes('All Mail'))!
        .trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/mail/all');
    expect(fetchMock).toHaveBeenCalledWith('/api/messages?view=all&limit=50', expect.anything());
    const before = fetchMock.mock.calls.filter(([url]) => url === '/api/mailbox-counts').length;
    await wrapper.find('.mailbox-refresh').trigger('click');
    await flushPromises();
    expect(
        fetchMock.mock.calls.filter(([url]) => url === '/api/mailbox-counts').length,
    ).toBeGreaterThan(before);
    await wrapper
        .findAll('.nav-button')
        .find((b) => b.text().includes('Manage accounts'))!
        .trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/mail/accounts');
});

it('hides unavailable counts and ignores stale account pages while navigation stays usable', async () => {
    const { wrapper, router, fetchMock } = await open('/mail/account/1', true);
    expect(wrapper.find('.mailbox-count').exists()).toBe(false);
    let finish!: (r: Response) => void;
    fetchMock.mockImplementationOnce(
        () =>
            new Promise<Response>((resolve) => {
                finish = resolve;
            }),
    );
    await wrapper.find('.message-feed-footer button').trigger('click');
    await wrapper.findAll('.account-nav-row')[1].trigger('click');
    await flushPromises();
    finish(new Response(JSON.stringify({ data: [message(99, 1)], next_cursor: 'stale' })));
    await flushPromises();
    expect(router.currentRoute.value.path).toBe('/mail/account/2');
    expect(wrapper.findAll('.message-subject').map((e) => e.text())).toEqual(['Mail 2']);
    expect(wrapper.find('.mailbox-count').exists()).toBe(false);
});

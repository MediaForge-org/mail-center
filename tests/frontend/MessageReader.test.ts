import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import App from '../../resources/js/App.vue';
import { routes } from '../../resources/js/router';
import MessageReader from '../../resources/js/features/messages/MessageReader.vue';

const message = (id = 1) => ({
    id,
    mail_account_id: 1,
    subject: 'Real subject ' + id,
    from_name: 'Alice',
    from_address: 'alice@example.test',
    to: [{ name: 'Bob', address: 'bob@example.test' }],
    cc: [],
    bcc: [],
    reply_to: [],
    date_header: null,
    received_at: '2026-01-01T12:00:00Z',
    sort_date: '2026-01-01T12:00:00Z',
    snippet: 'Preview',
    direction: 'inbound',
    remote_status: 'present',
    is_read: false,
    is_starred: false,
    is_done: false,
    is_important: false,
    has_attachments: true,
    body_status: 'available',
    text_plain: 'Hello\n<img src="https://attacker.test/image">\n<script>alert(1)</script>',
});
const response = (data: unknown, status = 200) =>
    new Response(JSON.stringify({ data }), { status });
const wrappers: ReturnType<typeof mount>[] = [];
afterEach(() => {
    wrappers.forEach((w) => w.unmount());
    wrappers.length = 0;
    vi.unstubAllGlobals();
});

it('renders plain text safely, protects against stale requests and supports close, errors and retry', async () => {
    let finish!: (response: Response) => void;
    const fetchMock = vi
        .fn()
        .mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        )
        .mockResolvedValueOnce(response(message(2)))
        .mockResolvedValueOnce(response({}, 404))
        .mockResolvedValueOnce(response({}, 500))
        .mockResolvedValueOnce(
            response({ ...message(4), body_status: 'unavailable', text_plain: null }),
        );
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(MessageReader, { props: { messageId: 1, accounts: [] } });
    wrappers.push(wrapper);
    expect(wrapper.text()).toContain('Loading message');
    await wrapper.setProps({ messageId: 2 });
    await flushPromises();
    finish(response(message(1)));
    await flushPromises();
    expect(wrapper.text()).toContain('Real subject 2');
    expect(wrapper.text()).not.toContain('Real subject 1');
    expect(wrapper.text()).toContain('Alice');
    expect(wrapper.text()).toContain('bob@example.test');
    expect(wrapper.find('.reader-body').text()).toBe(message().text_plain);
    expect(wrapper.find('img, script, iframe').exists()).toBe(false);
    await wrapper.setProps({ messageId: 3 });
    await flushPromises();
    expect(wrapper.text()).toContain('no longer accessible');
    await wrapper.setProps({ messageId: 4 });
    await flushPromises();
    expect(wrapper.text()).toContain('Unable to load');
    await wrapper.find('.small-button').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('Plain-text content is unavailable');
    await wrapper.find('.text-button').trigger('click');
    expect(wrapper.emitted('close')).toHaveLength(1);
    await wrapper.setProps({ messageId: null });
    expect(wrapper.text()).toContain('Select a message');
});

it('restores URL selection, selects rows without mutations, handles history and clears selection on view navigation', async () => {
    const fetchMock = vi.fn((url: string) => {
        if (url === '/api/me')
            return Promise.resolve(new Response(JSON.stringify({ id: 1, name: 'Operator' })));
        if (url === '/api/accounts')
            return Promise.resolve(
                response([{ id: 1, display_name: 'Work', email_address: 'work@example.test' }]),
            );
        if (url.startsWith('/api/messages?'))
            return Promise.resolve(
                new Response(JSON.stringify({ data: [message(1), message(2)], next_cursor: null })),
            );
        return Promise.resolve(response(message(Number(url.split('/').pop()))));
    });
    vi.stubGlobal('fetch', fetchMock);
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/mail/all?message=1');
    await router.isReady();
    const wrapper = mount(App, { global: { plugins: [router] } });
    wrappers.push(wrapper);
    await flushPromises();
    expect(wrapper.find('.message-detail').text()).toContain('Real subject 1');
    expect(wrapper.find('.message-selected').text()).toContain('Real subject 1');
    expect(wrapper.find('.message-detail .message-account').text()).toBe('Work');
    await wrapper.findAll('.message-row')[1].trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.query.message).toBe('2');
    expect(wrapper.find('.message-detail').text()).toContain('Real subject 2');
    expect(wrapper.findAll('.message-row')).toHaveLength(2);
    router.back();
    await flushPromises();
    expect(wrapper.find('.message-detail').text()).toContain('Real subject 1');
    router.forward();
    await flushPromises();
    expect(wrapper.find('.message-detail').text()).toContain('Real subject 2');
    await wrapper.find('.reader-toolbar button').trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.query.message).toBeUndefined();
    expect(wrapper.text()).toContain('Select a message');
    await wrapper.find('.message-row').trigger('click');
    await flushPromises();
    await wrapper
        .findAll('.nav-button')
        .find((b) => b.text().includes('Inbox'))!
        .trigger('click');
    await flushPromises();
    expect(router.currentRoute.value.fullPath).toBe('/mail/inbox');
    expect(wrapper.find('.message-selected').exists()).toBe(false);
    expect(
        fetchMock.mock.calls.filter(([url]) => url.startsWith('/api/messages?view=all')),
    ).toHaveLength(1);
    for (const call of fetchMock.mock.calls)
        expect((call as unknown as [string, RequestInit])[1]?.method ?? 'GET').toBe('GET');
});

it('uses only a same-origin sandboxed iframe for HTML and keeps plain text available', async () => {
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(
            response({ ...message(1), html_available: true, remote_content_count: 2 }),
        )
        .mockResolvedValueOnce(
            response({ ...message(2), html_available: true, remote_content_count: 0 }),
        )
        .mockResolvedValueOnce(
            response({ ...message(3), html_available: false, remote_content_count: 0 }),
        );
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(MessageReader, { props: { messageId: 1, accounts: [] } });
    wrappers.push(wrapper);
    await flushPromises();
    const frame = wrapper.find('iframe');
    expect(frame.attributes('src')).toBe('/api/messages/1/render');
    expect(frame.attributes('sandbox')).toBe(
        'allow-same-origin allow-popups allow-popups-to-escape-sandbox',
    );
    expect(frame.attributes('referrerpolicy')).toBe('no-referrer');
    expect(frame.attributes('srcdoc')).toBeUndefined();
    expect(wrapper.text()).toContain('2 remote images blocked');
    await wrapper.find('.reader-format button').trigger('click');
    expect(wrapper.find('iframe').exists()).toBe(false);
    expect(wrapper.find('.reader-body').text()).toBe(message().text_plain);
    await wrapper.setProps({ messageId: 2 });
    await flushPromises();
    expect(wrapper.find('iframe').attributes('src')).toBe('/api/messages/2/render');
    await wrapper.setProps({ messageId: 3 });
    await flushPromises();
    expect(wrapper.find('iframe').exists()).toBe(false);
    expect(wrapper.find('.reader-body').exists()).toBe(true);
});

it('shows compact attachment downloads for the selected message and clears stale files', async () => {
    const filename = '非常に長いrésumé-'.repeat(20) + '.pdf';
    const attachments = [
        {
            id: 7,
            filename,
            content_type: 'application/pdf',
            size_bytes: 2048,
            inline: false,
            downloadable: true,
        },
        {
            id: 8,
            filename,
            content_type: 'application/octet-stream',
            size_bytes: 0,
            inline: false,
            downloadable: true,
        },
    ];
    let finish!: (response: Response) => void;
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response({ ...message(1), attachments }))
        .mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        )
        .mockResolvedValueOnce(response({ ...message(3), attachments: [] }));
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(MessageReader, { props: { messageId: 1, accounts: [] } });
    wrappers.push(wrapper);
    await flushPromises();
    expect(wrapper.findAll('.reader-attachments li')).toHaveLength(2);
    expect(wrapper.find('.attachment-name').text()).toBe(filename);
    expect(wrapper.find('.attachment-info').text()).toContain('2 KB');
    expect(wrapper.findAll('.attachment-info')[1].text()).toContain('0 B');
    expect(wrapper.findAll('.reader-attachments a').map((a) => a.attributes('href'))).toEqual([
        '/api/messages/1/attachments/7',
        '/api/messages/1/attachments/8',
    ]);
    await wrapper.setProps({ messageId: 2 });
    expect(wrapper.find('.reader-attachments').exists()).toBe(false);
    await wrapper.setProps({ messageId: 3 });
    await flushPromises();
    finish(response({ ...message(2), attachments }));
    await flushPromises();
    expect(wrapper.find('.reader-attachments').exists()).toBe(false);
    expect(wrapper.text()).toContain('Real subject 3');
});

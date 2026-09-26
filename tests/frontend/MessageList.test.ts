import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import MessageList from '../../resources/js/features/messages/MessageList.vue';
import type { MessageListItem } from '../../resources/js/api/messages';
import type { AccountSummary } from '../../resources/js/api/accounts';

const message = (id: number): MessageListItem => ({
    id,
    mail_account_id: 1,
    from_name: 'Alice',
    from_address: 'alice@example.test',
    subject: `Subject ${id}`,
    snippet: 'A useful preview',
    sort_date: '2026-01-01T12:00:00Z',
    received_at: null,
    to: [],
    is_read: false,
    is_starred: true,
    is_important: true,
    is_done: true,
    has_attachments: true,
    direction: 'inbound',
});
const response = (data: MessageListItem[], next_cursor: string | null = null, status = 200) =>
    new Response(JSON.stringify({ data, next_cursor }), { status });
const wrappers: ReturnType<typeof mount>[] = [];
afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    vi.unstubAllGlobals();
});
function setup(fetchMock: ReturnType<typeof vi.fn>) {
    vi.stubGlobal('fetch', fetchMock);
    const wrapper = mount(MessageList, {
        props: {
            view: 'all',
            accounts: [
                {
                    id: 1,
                    display_name: 'Work',
                    email_address: 'work@example.test',
                } as AccountSummary,
            ],
        },
    });
    wrappers.push(wrapper);
    return wrapper;
}

it('renders safe message metadata and read state with account identity and local selection', async () => {
    const long = {
        ...message(1),
        subject: '<script>unsafe</script>' + 'Long subject '.repeat(100),
    };
    const fetchMock = vi.fn().mockResolvedValue(response([long, { ...message(2), is_read: true }]));
    const wrapper = setup(fetchMock);
    await flushPromises();
    const rows = wrapper.findAll('.message-row');
    expect(rows).toHaveLength(2);
    expect(rows[0].text()).toContain('Alice');
    expect(rows[0].text()).toContain('A useful preview');
    expect(rows[0].find('.message-subject').text()).toBe(long.subject.trim());
    expect(rows[0].find('script').exists()).toBe(false);
    expect(rows[0].classes()).toContain('message-unread');
    expect(rows[1].classes()).not.toContain('message-unread');
    expect(rows[0].find('.message-account').text()).toBe('Work');
    expect(rows[0].find('[aria-label="Has attachments"]').exists()).toBe(true);
    for (const flag of ['Starred', 'Important', 'Done']) expect(rows[0].text()).toContain(flag);
    expect(rows[0].find('time').attributes('datetime')).toBe(long.sort_date);
    await rows[0].trigger('click');
    expect(wrapper.emitted('select')).toEqual([[1]]);
    await wrapper.setProps({ selectedMessageId: 1 });
    expect(rows[0].attributes('aria-pressed')).toBe('true');
    expect(fetchMock).toHaveBeenCalledTimes(1);
    expect(wrapper.text()).toContain('End of messages');
});

it('shows initial loading, failure with retry, and contextual empty states', async () => {
    let finish!: (value: Response) => void;
    const fetchMock = vi
        .fn()
        .mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        )
        .mockImplementation(() => Promise.resolve(response([])));
    const wrapper = setup(fetchMock);
    expect(wrapper.text()).toContain('Loading mailbox');
    finish(response([], null, 500));
    await flushPromises();
    expect(wrapper.find('[role="alert"]').text()).toContain('Unable to load this mailbox');
    await wrapper.find('button').trigger('click');
    await flushPromises();
    expect(wrapper.text()).toContain('No messages yet');
    await wrapper.setProps({ view: 'inbox' });
    await flushPromises();
    expect(wrapper.text()).toContain('Your Inbox is empty');
    await wrapper.setProps({ view: 'unread' });
    await flushPromises();
    expect(wrapper.text()).toContain('No unread messages');
});

it('loads the signed cursor, keeps rows during failure, retries and appends without duplicates', async () => {
    let finish!: (value: Response) => void;
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response([message(3)], 'cursor+/='))
        .mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        )
        .mockResolvedValueOnce(response([message(3), message(2), message(1)]));
    const wrapper = setup(fetchMock);
    await flushPromises();
    await wrapper.find('.message-feed-footer button').trigger('click');
    expect(wrapper.findAll('.message-row')).toHaveLength(1);
    expect(wrapper.text()).toContain('Loading more');
    expect(fetchMock.mock.calls[1][0]).toBe(
        '/api/messages?view=all&limit=50&cursor=cursor%2B%2F%3D',
    );
    finish(response([], null, 500));
    await flushPromises();
    expect(wrapper.findAll('.message-row')).toHaveLength(1);
    await wrapper.find('.message-feed-footer button').trigger('click');
    await flushPromises();
    expect(wrapper.findAll('.message-subject').map((row) => row.text())).toEqual([
        'Subject 3',
        'Subject 2',
        'Subject 1',
    ]);
    expect(fetchMock.mock.calls[2][0]).toBe(fetchMock.mock.calls[1][0]);
    expect(wrapper.text()).toContain('End of messages');
    expect(wrapper.find('.message-feed-footer button').exists()).toBe(false);
});

it('resets messages and cursor on view changes and ignores a stale next page', async () => {
    let finish!: (value: Response) => void;
    const fetchMock = vi
        .fn()
        .mockResolvedValueOnce(response([message(1)], 'old-cursor'))
        .mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        )
        .mockResolvedValueOnce(response([message(9)]));
    const wrapper = setup(fetchMock);
    await flushPromises();
    await wrapper.find('.message-feed-footer button').trigger('click');
    await wrapper.setProps({ view: 'inbox' });
    await flushPromises();
    expect(fetchMock.mock.calls[2][0]).toBe('/api/messages?view=inbox&limit=50');
    expect(fetchMock.mock.calls[1][1].signal.aborted).toBe(true);
    finish(response([message(2)], 'another-old-cursor'));
    await flushPromises();
    expect(wrapper.findAll('.message-subject').map((row) => row.text())).toEqual(['Subject 9']);
    expect(wrapper.text()).toContain('End of messages');
});

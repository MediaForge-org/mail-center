import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, expect, it, vi } from 'vitest';
import ConversationReader from '../../resources/js/features/messages/ConversationReader.vue';
import { getConversation } from '../../resources/js/api/messages';
vi.mock('../../resources/js/api/messages', () => ({ getConversation: vi.fn() }));
afterEach(() => vi.resetAllMocks());
it('shows chronological members, expands the selected message using the shared reader and blocks stale membership', async () => {
    let finish!: (value: Awaited<ReturnType<typeof getConversation>>) => void;
    vi.mocked(getConversation).mockImplementationOnce(
        () =>
            new Promise((resolve) => {
                finish = resolve;
            }),
    );
    const wrapper = mount(ConversationReader, {
        props: { messageId: 1, accounts: [] },
        global: {
            stubs: {
                MessageReader: {
                    props: ['messageId'],
                    template: '<div class="shared-reader">Body {{ messageId }}</div>',
                },
            },
        },
    });
    vi.mocked(getConversation).mockResolvedValueOnce({
        data: [
            {
                id: 2,
                subject: 'Parent',
                from_name: 'A',
                from_address: '',
                sort_date: '2026-01-01',
                is_read: false,
                remote_status: 'removed',
            },
            {
                id: 3,
                subject: 'Child',
                from_name: 'B',
                from_address: '',
                sort_date: '2026-01-02',
                is_read: false,
                remote_status: 'present',
            },
        ],
        next_cursor: null,
    });
    await wrapper.setProps({ messageId: 3 });
    await flushPromises();
    finish({
        data: [
            {
                id: 1,
                subject: 'Stale',
                from_name: '',
                from_address: '',
                sort_date: '2026-01-01',
                is_read: false,
                remote_status: 'present',
            },
        ],
        next_cursor: null,
    });
    await flushPromises();
    expect(wrapper.text()).not.toContain('Stale');
    expect(wrapper.findAll('.conversation-summary').map((item) => item.text())).toEqual([
        expect.stringContaining('Parent'),
        expect.stringContaining('Selected message'),
    ]);
    expect(wrapper.findAll('.shared-reader')).toHaveLength(1);
    expect(wrapper.find('.conversation-selected').text()).toContain('Body 3');
    await wrapper.find('.conversation-summary').trigger('click');
    expect(wrapper.findAll('.shared-reader')).toHaveLength(2);
    expect(wrapper.text()).toContain('Removed remotely');
    wrapper.unmount();
});

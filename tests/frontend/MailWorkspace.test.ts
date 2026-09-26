import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MailWorkspace from '../../resources/js/features/workspace/MailWorkspace.vue';

describe('M1 mail workspace', () => {
    it('renders the three structural panes without fabricated messages', () => {
        const wrapper = mount(MailWorkspace, {
            props: { userName: 'Example Operator' },
            global: { stubs: { MessageList: { template: '<p>Loading mailbox…</p>' } } },
        });

        expect(wrapper.find('[data-testid="left-pane"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="center-pane"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="right-pane"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No account connected');
        expect(wrapper.text()).toContain('Loading mailbox');
        expect(wrapper.text()).toContain('Example Operator');
    });
});

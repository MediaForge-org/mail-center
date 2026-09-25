import { mount } from '@vue/test-utils';
import { describe, expect, it } from 'vitest';
import MailWorkspace from '../../resources/js/features/workspace/MailWorkspace.vue';

describe('M1 mail workspace', () => {
    it('renders the three structural panes without fabricated messages', () => {
        const wrapper = mount(MailWorkspace, { props: { userName: 'Example Operator' } });

        expect(wrapper.find('[data-testid="left-pane"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="center-pane"]').exists()).toBe(true);
        expect(wrapper.find('[data-testid="right-pane"]').exists()).toBe(true);
        expect(wrapper.text()).toContain('No account connected');
        expect(wrapper.text()).toContain('Your messages will appear here');
        expect(wrapper.text()).toContain('Example Operator');
    });
});

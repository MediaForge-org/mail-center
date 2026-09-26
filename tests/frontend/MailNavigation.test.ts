import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, describe, expect, it, vi } from 'vitest';
import { createMemoryHistory, createRouter } from 'vue-router';
import App from '../../resources/js/App.vue';
import { routes } from '../../resources/js/router';

const wrappers: ReturnType<typeof mount>[] = [];
afterEach(() => {
    wrappers.forEach((wrapper) => wrapper.unmount());
    wrappers.length = 0;
    vi.unstubAllGlobals();
});

async function open(path: string, failView = '') {
    const fetchMock = vi.fn((url: string) => {
        const body =
            url === '/api/me'
                ? { id: 1, name: 'Operator', email: 'op@example.test' }
                : { data: [], next_cursor: null };
        return Promise.resolve(
            new Response(JSON.stringify(body), {
                status: failView && url.includes(`view=${failView}`) ? 500 : 200,
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
    const click = async (label: string) => {
        await wrapper
            .findAll('button')
            .find((button) => button.text().endsWith(label))!
            .trigger('click');
        await flushPromises();
    };
    return { wrapper, router, fetchMock, click };
}

describe('mailbox navigation', () => {
    it('defaults to All Mail and switches views and accounts without a reload', async () => {
        const { wrapper, router, click, fetchMock } = await open('/mail');
        expect(router.currentRoute.value.path).toBe('/mail/all');
        expect(wrapper.find('[aria-current="page"]').text()).toContain('All Mail');
        await click('Inbox');
        expect(router.currentRoute.value.path).toBe('/mail/inbox');
        expect(wrapper.find('[aria-current="page"]').text()).toContain('Inbox');
        await click('Unread');
        expect(router.currentRoute.value.path).toBe('/mail/unread');
        expect(wrapper.find('[aria-current="page"]').text()).toContain('Unread');
        for (const view of ['all', 'inbox', 'unread'])
            expect(fetchMock).toHaveBeenCalledWith(
                `/api/messages?view=${view}&limit=50`,
                expect.anything(),
            );
        await click('Manage accounts');
        expect(router.currentRoute.value.path).toBe('/mail/accounts');
        expect(wrapper.find('[data-testid="accounts-pane"]').exists()).toBe(true);
        expect(wrapper.find('[aria-current="page"]').text()).toBe('Manage accounts');
        router.back();
        await flushPromises();
        expect(router.currentRoute.value.path).toBe('/mail/unread');
        router.forward();
        await flushPromises();
        expect(router.currentRoute.value.path).toBe('/mail/accounts');
    });

    it.each(['all', 'inbox', 'unread', 'accounts'])('restores a deep link to %s', async (view) => {
        const { router, wrapper } = await open(`/mail/${view}`);
        expect(router.currentRoute.value.path).toBe(`/mail/${view}`);
        expect(wrapper.find('[aria-current="page"]').exists()).toBe(true);
    });

    it('redirects unknown routes and hides unsupported views', async () => {
        const { router, wrapper } = await open('/mail/unknown/nested');
        expect(router.currentRoute.value.path).toBe('/mail/all');
        for (const label of [
            'Starred',
            'Important',
            'Completed',
            'Reloads',
            'Support',
            'Withdrawals',
            'Verification',
            'Done',
        ])
            expect(wrapper.text()).not.toContain(label);
        expect(wrapper.text()).not.toContain('0 messages');
    });

    it('ignores a stale response after changing mailbox views', async () => {
        const { wrapper, click, fetchMock } = await open('/mail/all');
        let finish: (response: Response) => void = () => {};
        fetchMock.mockImplementationOnce(
            () =>
                new Promise<Response>((resolve) => {
                    finish = resolve;
                }),
        );
        await click('Inbox');
        expect(wrapper.text()).toContain('Loading mailbox');
        await click('Unread');
        finish(new Response(JSON.stringify({ data: [{ id: 1 }], next_cursor: null })));
        await flushPromises();
        expect(wrapper.find('[aria-current="page"]').text()).toContain('Unread');
        expect(wrapper.text()).toContain('No unread messages');
    });

    it('keeps navigation usable after a mailbox API failure', async () => {
        const { wrapper, click, router } = await open('/mail/inbox', 'inbox');
        expect(wrapper.find('[role="alert"]').text()).toContain('Unable to load this mailbox');
        await click('Unread');
        expect(router.currentRoute.value.path).toBe('/mail/unread');
        expect(wrapper.find('[role="alert"]').exists()).toBe(false);
        expect(wrapper.text()).toContain('No unread messages');
    });
});

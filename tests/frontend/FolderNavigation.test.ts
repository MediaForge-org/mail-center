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

type Folder = { id: number; name: string; system_role: string | null; position: number };
type Msg = { id: number; folder_id: number; subject: string };

function seed() {
    const folders: Folder[] = [
        { id: 1, name: 'Inbox', system_role: 'inbox', position: 0 },
        { id: 2, name: 'Sent', system_role: 'sent', position: 1 },
        { id: 3, name: 'Archive', system_role: 'archive', position: 2 },
        { id: 4, name: 'Reloads', system_role: null, position: 3 },
    ];
    const messages: Msg[] = [{ id: 100, folder_id: 1, subject: 'Hello world' }];
    let nextFolderId = 5;
    return { folders, messages, nextFolderId: () => nextFolderId++ };
}

function toRow(m: Msg) {
    return {
        id: m.id,
        mail_account_id: 1,
        folder_id: m.folder_id,
        subject: m.subject,
        from_name: 'Sender',
        from_address: 'sender@example.test',
        to: [],
        snippet: 'Preview',
        sort_date: '2026-01-01T00:00:00Z',
        received_at: null,
        is_read: true,
        is_starred: false,
        is_important: false,
        is_done: false,
        has_attachments: false,
        direction: 'inbound',
    };
}

async function open() {
    const state = seed();
    const fetchMock = vi.fn((url: string, init?: RequestInit) => {
        const method = init?.method || 'GET';
        if (url === '/api/me')
            return Promise.resolve(new Response(JSON.stringify({ id: 1, name: 'Operator' })));
        if (url === '/api/accounts')
            return Promise.resolve(
                new Response(
                    JSON.stringify({
                        data: [
                            {
                                id: 1,
                                display_name: 'Work',
                                email_address: 'work@example.test',
                                enabled: true,
                                sync_enabled: true,
                                sync_status: 'idle',
                                incoming: { host: 'imap.example.test', port: 993, security: 'tls' },
                            },
                        ],
                    }),
                ),
            );
        if (url.startsWith('/api/changes'))
            return Promise.resolve(
                new Response(JSON.stringify({ version: '1', invalidate: false })),
            );
        if (url === '/api/mailbox-counts') {
            const folderCounts: Record<string, { total: number; unread: number }> = {};
            for (const folder of state.folders) {
                const count = state.messages.filter((m) => m.folder_id === folder.id).length;
                folderCounts[String(folder.id)] = { total: count, unread: 0 };
            }
            return Promise.resolve(
                new Response(
                    JSON.stringify({
                        views: {
                            all: { total: state.messages.length, unread: 0 },
                            inbox: { total: 0, unread: 0 },
                            unread: { total: 0, unread: 0 },
                        },
                        accounts: { '1': { total: state.messages.length, unread: 0 } },
                        folders: folderCounts,
                    }),
                ),
            );
        }
        if (url === '/api/folders' && method === 'GET')
            return Promise.resolve(new Response(JSON.stringify({ data: state.folders })));
        if (url === '/api/folders' && method === 'POST') {
            const body = JSON.parse(init?.body as string);
            const folder: Folder = {
                id: state.nextFolderId(),
                name: body.name,
                system_role: null,
                position: state.folders.length,
            };
            state.folders.push(folder);
            return Promise.resolve(new Response(JSON.stringify({ data: folder }), { status: 201 }));
        }
        const renameMatch = url.match(/^\/api\/folders\/(\d+)$/);
        if (renameMatch && method === 'PATCH') {
            const folder = state.folders.find((f) => f.id === Number(renameMatch[1]))!;
            folder.name = JSON.parse(init?.body as string).name;
            return Promise.resolve(new Response(JSON.stringify({ data: folder })));
        }
        if (renameMatch && method === 'DELETE') {
            const id = Number(renameMatch[1]);
            const movedCount = state.messages.filter((m) => m.folder_id === id).length;
            state.messages.forEach((m) => {
                if (m.folder_id === id) m.folder_id = 1;
            });
            state.folders = state.folders.filter((f) => f.id !== id);
            return Promise.resolve(
                new Response(
                    JSON.stringify({ data: { id, moved_count: movedCount, inbox_folder_id: 1 } }),
                ),
            );
        }
        const moveMatch = url.match(/^\/api\/messages\/(\d+)\/folder$/);
        if (moveMatch && method === 'PATCH') {
            const message = state.messages.find((m) => m.id === Number(moveMatch[1]))!;
            message.folder_id = JSON.parse(init?.body as string).folder_id;
            return Promise.resolve(
                new Response(
                    JSON.stringify({ data: { id: message.id, folder_id: message.folder_id } }),
                ),
            );
        }
        if (url.startsWith('/api/messages?')) {
            const parsed = new URL(url, 'http://x');
            const folderId = parsed.searchParams.get('folder_id');
            const view = parsed.searchParams.get('view');
            const rows = state.messages.filter((m) => {
                if (folderId) return m.folder_id === Number(folderId);
                if (view === 'inbox') return m.folder_id === 1;
                return true;
            });
            return Promise.resolve(
                new Response(JSON.stringify({ data: rows.map(toRow), next_cursor: null })),
            );
        }
        return Promise.resolve(new Response(JSON.stringify({ data: [] })));
    });
    vi.stubGlobal('fetch', fetchMock);
    const router = createRouter({ history: createMemoryHistory(), routes });
    await router.push('/mail/all');
    await router.isReady();
    const wrapper = mount(App, { global: { plugins: [router] } });
    wrappers.push(wrapper);
    await flushPromises();
    return { wrapper, router, fetchMock, state };
}

describe('local folder organization', () => {
    it('lists seeded folders in the sidebar with system folders undeletable', async () => {
        const { wrapper } = await open();
        const names = wrapper.findAll('.folder-nav-row').map((n) => n.text());
        expect(names.some((n) => n.includes('Inbox'))).toBe(true);
        expect(names.some((n) => n.includes('Reloads'))).toBe(true);
        // Only the custom folder row has a delete button.
        const rows = wrapper.findAll('.folder-row-wrap');
        const inboxRow = rows.find((r) => r.text().includes('Inbox'))!;
        const customRow = rows.find((r) => r.text().includes('Reloads'))!;
        expect(inboxRow.find('[title="Delete folder"]').exists()).toBe(false);
        expect(customRow.find('[title="Delete folder"]').exists()).toBe(true);
    });

    it('creates, renames, opens and deletes a custom folder, returning its mail to Inbox', async () => {
        const { wrapper, router } = await open();
        await wrapper.find('.sidebar-inner button:not(.folder-nav-row)').trigger('click'); // opens create form via + New folder
        const createButton = wrapper.findAll('button').find((b) => b.text() === '+ New folder')!;
        await createButton.trigger('click');
        await wrapper.find('.folder-create-form input').setValue('Test Folder');
        await wrapper.find('.folder-create-form').trigger('submit');
        await flushPromises();
        expect(wrapper.text()).toContain('Test Folder');

        const rows = () => wrapper.findAll('.folder-row-wrap');
        const created = () => rows().find((r) => r.text().includes('Test Folder'))!;
        await created().find('[title="Rename folder"]').trigger('click');
        await wrapper.find('.folder-edit-form input').setValue('Test Renamed');
        await wrapper.find('.folder-edit-form').trigger('submit');
        await flushPromises();
        expect(wrapper.text()).toContain('Test Renamed');
        expect(wrapper.text()).not.toContain('Test Folder');

        const renamed = () => rows().find((r) => r.text().includes('Test Renamed'))!;
        await renamed().find('.folder-nav-row').trigger('click');
        await flushPromises();
        expect(router.currentRoute.value.path).toMatch(/^\/mail\/folder\/\d+$/);
        expect(wrapper.text()).toContain('This folder is empty.');

        vi.stubGlobal(
            'confirm',
            vi.fn(() => true),
        );
        await renamed().find('[title="Delete folder"]').trigger('click');
        await flushPromises();
        expect(wrapper.text()).not.toContain('Test Renamed');
        expect(router.currentRoute.value.path).toBe('/mail/inbox');
    });

    it('moves a message into a folder from the row menu, updates counts, and leaves All Mail unaffected', async () => {
        const { wrapper, state } = await open();
        expect(wrapper.findAll('.message-row')).toHaveLength(1);
        const allMailCountBefore = wrapper
            .findAll('.nav-button')
            .find((b) => b.text().includes('All Mail'))!
            .text();

        const select = wrapper.find('select.message-row-move');
        await select.setValue('4'); // Reloads folder id
        await flushPromises();

        expect(state.messages[0].folder_id).toBe(4);
        const allMailCountAfter = wrapper
            .findAll('.nav-button')
            .find((b) => b.text().includes('All Mail'))!
            .text();
        expect(allMailCountAfter).toBe(allMailCountBefore); // All Mail total is unchanged by a move
        const reloadsRow = wrapper
            .findAll('.folder-row-wrap')
            .find((r) => r.text().includes('Reloads'))!;
        expect(reloadsRow.text()).toMatch(/Reloads.*1/s);
    });
});

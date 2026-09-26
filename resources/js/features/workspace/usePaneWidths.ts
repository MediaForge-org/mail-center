import { computed, onBeforeUnmount, onMounted, ref } from 'vue';

const storageKey = 'mailcenter.pane-widths.v1';
export function usePaneWidths() {
    const viewport = ref(1280);
    const sidebar = ref(224);
    const list = ref(480);
    const dragging = ref(false);
    const limits = (pane: 'sidebar' | 'list') => ({
        min: pane === 'sidebar' ? 180 : 320,
        max:
            pane === 'sidebar'
                ? Math.min(360, viewport.value - 692)
                : Math.min(1100, viewport.value - sidebar.value - 372),
    });
    function clamp() {
        const s = limits('sidebar');
        sidebar.value = Math.max(s.min, Math.min(s.max, sidebar.value));
        const l = limits('list');
        list.value = Math.max(l.min, Math.min(l.max, list.value));
    }
    function save() {
        try {
            localStorage.setItem(
                storageKey,
                JSON.stringify({ sidebar: sidebar.value, list: list.value }),
            );
        } catch {
            /* Private browsing can disable storage. */
        }
    }
    function resize() {
        viewport.value = Math.max(900, window.innerWidth);
        clamp();
    }
    let stop: (() => void) | undefined;
    function start(event: PointerEvent, pane: 'sidebar' | 'list') {
        if (event.button !== 0) return;
        event.preventDefault();
        stop?.();
        const origin = event.clientX;
        const width = pane === 'sidebar' ? sidebar.value : list.value;
        dragging.value = true;
        const move = (e: PointerEvent) => {
            if (e.pointerId !== event.pointerId) return;
            if (pane === 'sidebar') sidebar.value = width + e.clientX - origin;
            else list.value = width + e.clientX - origin;
            clamp();
        };
        stop = () => {
            window.removeEventListener('pointermove', move);
            window.removeEventListener('pointerup', end);
            window.removeEventListener('pointercancel', end);
            dragging.value = false;
            save();
        };
        const end = (e: PointerEvent) => {
            if (e.pointerId === event.pointerId) stop?.();
        };
        window.addEventListener('pointermove', move);
        window.addEventListener('pointerup', end);
        window.addEventListener('pointercancel', end);
    }
    function keyboard(event: KeyboardEvent, pane: 'sidebar' | 'list') {
        if (!['ArrowLeft', 'ArrowRight', 'Home', 'End'].includes(event.key)) return;
        event.preventDefault();
        const target = pane === 'sidebar' ? sidebar : list;
        const bounds = limits(pane);
        target.value =
            event.key === 'Home'
                ? bounds.min
                : event.key === 'End'
                  ? bounds.max
                  : target.value +
                    (event.key === 'ArrowLeft' ? -1 : 1) * (event.shiftKey ? 60 : 20);
        clamp();
        save();
    }
    onMounted(() => {
        viewport.value = Math.max(900, window.innerWidth);
        list.value = Math.min(900, Math.round(viewport.value * 0.38));
        try {
            const stored = JSON.parse(localStorage.getItem(storageKey) || 'null');
            if (stored && Number.isFinite(stored.sidebar) && Number.isFinite(stored.list)) {
                sidebar.value = stored.sidebar;
                list.value = stored.list;
            }
        } catch {
            /* Ignore invalid preferences. */
        }
        clamp();
        window.addEventListener('resize', resize);
    });
    onBeforeUnmount(() => {
        stop?.();
        window.removeEventListener('resize', resize);
    });
    return {
        sidebar,
        list,
        dragging,
        limits,
        start,
        keyboard,
        style: computed(() => ({
            '--sidebar-width': sidebar.value + 'px',
            '--list-width': list.value + 'px',
        })),
    };
}

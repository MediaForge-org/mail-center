<script setup lang="ts">
import { ref } from 'vue';
import type { Folder } from '../../api/folders';

const props = defineProps<{
    folders: Folder[];
    currentFolderId?: number | null;
    label: string;
    disabled?: boolean;
}>();
const emit = defineEmits<{ move: [folderId: number] }>();
const selected = ref('');
function apply() {
    const id = Number(selected.value);
    selected.value = '';
    if (Number.isInteger(id) && id > 0) emit('move', id);
}
</script>

<template>
    <select
        class="folder-move-menu"
        :aria-label="label"
        :disabled="disabled || folders.length === 0"
        v-model="selected"
        @change="apply"
    >
        <option value="" disabled>{{ label }}</option>
        <option
            v-for="folder in folders"
            :key="folder.id"
            :value="folder.id"
            :disabled="folder.id === props.currentFolderId"
        >
            {{ folder.name }}
        </option>
    </select>
</template>

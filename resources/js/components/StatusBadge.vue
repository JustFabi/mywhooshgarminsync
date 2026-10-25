<script setup lang="ts">
import { computed } from 'vue';

const props = defineProps<{
    status: 'pending' | 'synced' | 'failed' | string;
}>();

const classes = computed(() =>
    ({
        pending: 'bg-sky-500/10 text-sky-700 ring-sky-500/30 dark:text-sky-300',
        synced: 'bg-emerald-500/10 text-emerald-700 ring-emerald-500/30 dark:text-emerald-300',
        failed: 'bg-red-500/10 text-red-700 ring-red-500/30 dark:text-red-300',
    })[props.status] ?? 'bg-muted text-muted-foreground ring-border',
);
</script>

<template>
    <span
        class="inline-flex items-center gap-1 rounded-full px-2.5 py-0.5 text-xs font-medium capitalize ring-1"
        :class="classes"
    >
        {{ status }}
    </span>
</template>

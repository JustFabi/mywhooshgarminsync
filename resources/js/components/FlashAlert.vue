<script setup lang="ts">
import { Button } from '@/components/ui/button';
import { usePage } from '@inertiajs/vue3';
import { CheckCircle2, Info, X, XCircle } from 'lucide-vue-next';
import { computed, ref, watch } from 'vue';

const page = usePage<SharedData>();

const flash = computed(() => page.props.flash);

const items = ref<{ kind: string; text: string; id: number }[]>([]);
let nextId = 0;

watch(
    flash,
    (value) => {
        if (value?.success) {
            push('success', value.success);
        }

        if (value?.error) {
            push('error', value.error);
        }
    },
    { immediate: true },
);

function push(kind: string, text: string) {
    const id = nextId++;
    items.value.push({ kind, text, id });
    setTimeout(() => dismiss(id), 6000);
}

function dismiss(id: number) {
    items.value = items.value.filter((x) => x.id !== id);
}
</script>

<template>
    <div class="space-y-2" v-if="items.length">
        <div
            v-for="item in items"
            :key="item.id"
            class="flex items-start gap-3 rounded-lg border px-3 py-2 text-sm shadow-sm"
            :class="{
                'border-emerald-500/40 bg-emerald-500/10 text-emerald-800 dark:text-emerald-200': item.kind === 'success',
                'border-red-500/40 bg-red-500/10 text-red-800 dark:text-red-200': item.kind === 'error',
                'border-sky-500/40 bg-sky-500/10 text-sky-800 dark:text-sky-200': item.kind === 'info',
            }"
        >
            <component
                :is="item.kind === 'success' ? CheckCircle2 : item.kind === 'error' ? XCircle : Info"
                class="mt-0.5 size-4 shrink-0"
            />

            <span class="flex-1">{{ item.text }}</span>

            <Button variant="ghost" size="sm" class="size-6 p-0" @click="dismiss(item.id)">
                <X class="size-3.5" />
            </Button>
        </div>
    </div>
</template>

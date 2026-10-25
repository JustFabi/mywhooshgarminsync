<script setup lang="ts">
import FlashAlert from '@/components/FlashAlert.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, DeviceSummary } from '@/types';
import { Head, router, useForm } from '@inertiajs/vue3';
import { Check, Search, Watch } from 'lucide-vue-next';
import { computed, ref } from 'vue';

interface Props {
    devices: DeviceSummary[];
    selected: number | null;
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Watches', href: '/watches' }];

const query = ref('');

/** Optional brand filter chips. */
const familyFilter = ref<string | null>(null);

const families = computed(() => {
    const names = props.devices.map((d) => d.name);
    const set = new Set(names.map((n) => n.split(' ').slice(0, 2).join(' ')));
    return Array.from(set).sort();
});

const filtered = computed(() =>
    props.devices.filter(
        (d) =>
            d.name.toLowerCase().includes(query.value.toLowerCase()) &&
            (familyFilter.value === null || d.name.startsWith(familyFilter.value)),
    ),
);

function select(device: DeviceSummary) {
    const form = useForm({ device_id: device.id });
    form.post(route('watches.select'), {
        preserveState: true,
        preserveScroll: true,
    });
}
</script>

<template>
    <Head title="Watches" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <FlashAlert />

            <div class="flex flex-wrap items-center justify-between gap-3">
                <div>
                    <h2 class="text-lg font-semibold">Spoof watch</h2>
                    <p class="text-sm text-muted-foreground">
                        Garmin Connect sees the recording device. Picking a real Garmin watch that
                        records indoor cycling unlocks Garmin's training metrics for the ride.
                        Default: Garmin Fenix 6S Pro (product 3288) — the same watch the original
                        Go tool always spoofed.
                    </p>
                </div>

                <div class="relative">
                    <Search class="absolute left-2.5 top-2.5 size-4 text-muted-foreground" />
                    <Input v-model="query" placeholder="Filter…" class="w-56 pl-8" />
                </div>
            </div>

            <div class="flex flex-wrap gap-1.5">
                <button
                    class="rounded-full border px-3 py-1 text-xs transition"
                    :class="familyFilter === null ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                    @click="familyFilter = null"
                >
                    All ({{ props.devices.length }})
                </button>
                <button
                    v-for="family in families"
                    :key="family"
                    class="rounded-full border px-3 py-1 text-xs transition"
                    :class="familyFilter === family ? 'bg-primary text-primary-foreground' : 'hover:bg-accent'"
                    @click="familyFilter = family"
                >
                    {{ family }}
                </button>
            </div>

            <div class="grid gap-3 md:grid-cols-2 lg:grid-cols-3">
                <button
                    v-for="device in filtered"
                    :key="device.id"
                    class="relative flex items-start gap-3 rounded-xl border p-4 text-left transition hover:border-ring hover:bg-accent/50"
                    :class="props.selected === device.id ? 'border-ring ring-ring ring-1' : ''"
                    type="button"
                    @click="select(device)"
                >
                    <Watch
                        class="mt-0.5 size-4 shrink-0"
                        :class="props.selected === device.id ? 'text-primary' : 'text-muted-foreground'"
                    />

                    <span class="min-w-0">
                        <span class="block truncate text-sm font-medium">{{ device.name }}</span>
                        <span class="text-xs text-muted-foreground">product {{ device.product_id }}</span>
                    </span>

                    <Check
                        v-if="props.selected === device.id"
                        class="absolute right-3 top-3 size-4 text-primary"
                    />
                </button>
            </div>

            <p class="text-xs text-muted-foreground">
                Selection applies to every sync you trigger afterwards. Already-synced rides keep
                the watch they were synced with; use the per-ride "fix" action to rewrite its FIT.
            </p>
        </div>
    </AppLayout>
</template>

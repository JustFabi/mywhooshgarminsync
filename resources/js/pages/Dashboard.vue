<script setup lang="ts">
import FlashAlert from '@/components/FlashAlert.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ActivitySummary, BreadcrumbItem, ConnectionInfo, DeviceSummary } from '@/types';
import { Head, Link } from '@inertiajs/vue3';
import { fullDate, km } from '@/lib/format';
import { ArrowRight, CircleOff, Inbox, Link2, Watch } from 'lucide-vue-next';

interface Props {
    stats: { total: number; pending: number; synced: number; failed: number };
    mywhoosh: ConnectionInfo | null;
    garmin: ConnectionInfo | null;
    watch: DeviceSummary | null;
    recent: ActivitySummary[];
}

const props = defineProps<Props>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Dashboard', href: '/dashboard' }];
</script>

<template>
    <Head title="Dashboard" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
            <FlashAlert />

            <div class="grid auto-rows-min gap-4 md:grid-cols-4">
                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Activities</CardDescription>
                        <CardTitle class="text-3xl tabular-nums">{{ props.stats.total }}</CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-xs text-muted-foreground">imported from MyWhoosh</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Synced</CardDescription>
                        <CardTitle class="text-3xl tabular-nums text-emerald-600 dark:text-emerald-400">
                            {{ props.stats.synced }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-xs text-muted-foreground">on Garmin Connect</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Pending</CardDescription>
                        <CardTitle class="text-3xl tabular-nums text-sky-600 dark:text-sky-400">
                            {{ props.stats.pending }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-xs text-muted-foreground">waiting for upload</p>
                    </CardContent>
                </Card>

                <Card>
                    <CardHeader class="pb-2">
                        <CardDescription>Failed</CardDescription>
                        <CardTitle class="text-3xl tabular-nums text-red-600 dark:text-red-400">
                            {{ props.stats.failed }}
                        </CardTitle>
                    </CardHeader>
                    <CardContent>
                        <p class="text-xs text-muted-foreground">need attention</p>
                    </CardContent>
                </Card>
            </div>

            <div class="grid gap-4 md:grid-cols-3">
                <Card class="flex flex-col">
                    <CardHeader>
                        <CardDescription>MyWhoosh</CardDescription>
                        <CardTitle class="flex items-center gap-2">
                            <component
                                class="size-4 shrink-0"
                                :class="props.mywhoosh ? 'text-emerald-500' : 'text-muted-foreground'"
                                :is="props.mywhoosh ? Link2 : CircleOff"
                            />
                            <span class="truncate">{{ props.mywhoosh ? props.mywhoosh.email : 'Not connected' }}</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex-1 text-xs text-muted-foreground">
                        <span v-if="props.mywhoosh">
                            Session {{ props.mywhoosh.remembered ? 'auto-renews (password remembered)' : 'without stored password' }}
                        </span>
                        <span v-else>Connect to import rides</span>
                    </CardContent>
                    <CardContent class="flex gap-2">
                        <Button as-child size="sm">
                            <Link :href="route('connections.index')">{{ props.mywhoosh ? 'Open' : 'Connect' }}</Link>
                        </Button>
                        <Link
                            :href="route('activities.fetch')"
                            method="post"
                            :data="{ days: 14 }"
                            as="button"
                            class="!inline-flex h-8 items-center justify-center gap-1.5 rounded-md border border-border bg-secondary px-2.5 text-xs font-medium text-secondary-foreground hover:bg-secondary/90"
                            preserve-scroll
                        >
                            Refresh 14d
                        </Link>
                    </CardContent>
                </Card>

                <Card class="flex flex-col">
                    <CardHeader>
                        <CardDescription>Garmin Connect</CardDescription>
                        <CardTitle class="flex items-center gap-2">
                            <component
                                class="size-4 shrink-0"
                                :class="props.garmin ? 'text-emerald-500' : 'text-muted-foreground'"
                                :is="props.garmin ? Link2 : CircleOff"
                            />
                            <span class="truncate">{{ props.garmin ? props.garmin.email : 'Not connected' }}</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex-1 text-xs text-muted-foreground">
                        <span v-if="props.garmin">
                            OAuth tokens {{ props.garmin.remembered ? 'auto-maintain' : 'will need reconnect when they expire' }}
                        </span>
                        <span v-else>Connect to upload rides</span>
                    </CardContent>
                    <CardContent>
                        <Button as-child size="sm">
                            <Link :href="route('connections.index')">{{ props.garmin ? 'Open' : 'Connect' }}</Link>
                        </Button>
                    </CardContent>
                </Card>

                <Card class="flex flex-col">
                    <CardHeader>
                        <CardDescription>Spoof watch</CardDescription>
                        <CardTitle class="flex items-center gap-2">
                            <Watch class="size-4 shrink-0 text-muted-foreground" />
                            <span class="truncate">{{ props.watch ? props.watch.name : 'No watch selected' }}</span>
                        </CardTitle>
                    </CardHeader>
                    <CardContent class="flex-1 text-xs text-muted-foreground">
                        <span v-if="props.watch">product {{ props.watch.product_id }} — synced rides pretend to be recorded by this device</span>
                        <span v-else>Choose the Garmin watch MyWhoosh rides should look like</span>
                    </CardContent>
                    <CardContent>
                        <Button as-child size="sm" variant="secondary">
                            <Link :href="route('watches.index')">Choose <ArrowRight class="size-3.5" /></Link>
                        </Button>
                    </CardContent>
                </Card>
            </div>

            <Card class="flex flex-1 flex-col">
                <CardHeader class="flex-row items-center justify-between space-y-0">
                    <div class="space-y-1">
                        <CardTitle>Recent activities</CardTitle>
                        <CardDescription>The newest five rides</CardDescription>
                    </div>
                    <Button as-child size="sm" variant="ghost">
                        <Link :href="route('activities.index')">All activities <ArrowRight class="size-3.5" /></Link>
                    </Button>
                </CardHeader>
                <CardContent class="flex-1">
                    <div v-if="props.recent.length" class="divide-y">
                        <div
                            v-for="item in props.recent"
                            :key="item.id"
                            class="flex flex-wrap items-center justify-between gap-2 py-2 text-sm"
                        >
                            <div class="min-w-0">
                                <div class="truncate font-medium">{{ item.title }}</div>
                                <div class="text-xs text-muted-foreground">
                                    {{ fullDate(item.start_at) }}
                                    <span v-if="item.distance_km"> · {{ km(item.distance_km) }}</span>
                                    <span v-if="item.device"> · {{ item.device }}</span>
                                </div>
                            </div>
                            <StatusBadge :status="item.status" />
                        </div>
                    </div>

                    <div v-else class="flex flex-col items-center gap-3 rounded-xl border border-dashed p-10 text-center">
                        <Inbox class="size-10 text-muted-foreground/50" />
                        <p class="text-sm text-muted-foreground">No activities yet — connect MyWhoosh and import your rides.</p>
                    </div>
                </CardContent>
            </Card>
        </div>
    </AppLayout>
</template>

<script setup lang="ts">
import FlashAlert from '@/components/FlashAlert.vue';
import StatusBadge from '@/components/StatusBadge.vue';
import { Button } from '@/components/ui/button';
import {
  Dialog,
  DialogContent,
  DialogDescription,
  DialogHeader,
  DialogTitle,
} from '@/components/ui/dialog';
import {
  DropdownMenu,
  DropdownMenuContent,
  DropdownMenuItem,
  DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import {
  Card,
  CardContent,
} from '@/components/ui/card';
import { Input } from '@/components/ui/input';
import AppLayout from '@/layouts/AppLayout.vue';
import type { ActivitySummary, SyncResult } from '@/types';
import { bpm, fullDate, km, watts, wkg } from '@/lib/format';
import { Head, router, useForm, usePage } from '@inertiajs/vue3';
import {
  ChevronDown,
  Download,
  FileCode2,
  Loader2,
  MoreHorizontal,
  RefreshCw,
  Trash2,
  Upload,
} from 'lucide-vue-next';
import { computed, reactive, ref, watch } from 'vue';

interface Props {
  activities: {
    data: ActivitySummary[];
    has_more: boolean;
    next_page: number | null;
    prev_page: number | null;
  };
  watch: { id: number; name: string; product_id: number } | null;
}

const props = defineProps<Props>();

const breadcrumbs = [{ title: 'Activities', href: '/activities' }];

const page = usePage();

// ---------------------------------------------------------------
// Server rows mirrored locally so per-ride actions can update in place.
// ---------------------------------------------------------------
const rows = reactive<ActivitySummary[]>([...props.activities.data]);
watch(
  () => props.activities.data,
  (data) => {
    rows.splice(0, rows.length, ...data);
  },
);

// ---------------------------------------------------------------
// Fetch from MyWhoosh
// ---------------------------------------------------------------
const fetchForm = useForm({ days: 30 });
const fetchUrl = route('activities.fetch');

// ---------------------------------------------------------------
// Sync (one by one so a long list never exceeds the PHP timeout)
// ---------------------------------------------------------------
const sessions = ref<Record<number, SyncResult>>({});
const running = ref(false);
const progress = ref({ done: 0, total: 0 });
const reportOpen = ref(false);

const pending = computed(() => rows.filter((a) => a.status !== 'synced'));

async function syncOne(id: number): Promise<SyncResult | null> {
  const res = await fetch(route('activities.sync', id), {
    method: 'POST',
    headers: {
      'X-CSRF-TOKEN': String(page.props.csrf_token ?? ''),
      Accept: 'application/json',
    },
  });

  if (res.status === 419) {
    window.location.reload();
    return null;
  }

  const json = (await res.json()) as SyncResult;
  sessions.value[id] = json;
  apply(id, json);
  return json;
}

function apply(id: number, result: SyncResult) {
  const row = rows.find((r) => r.id === id);
  if (!row) return;

  row.status = result.status;
  row.error_message = result.error;
  row.synced_at = result.synced_at;
  row.device = result.device;
  row.has_file = true;
}

async function syncOneWidget(id: number) {
  running.value = true;
  progress.value = { done: 0, total: 1 };
  await syncOne(id);
  progress.value.done = 1;
  running.value = false;
  reportOpen.value = true;
}

async function syncAllPending() {
  if (running.value) return;

  const targets = rows.filter((a) => a.status !== 'synced');
  if (!targets.length) return;

  running.value = true;
  progress.value = { done: 0, total: targets.length };
  reportOpen.value = true;

  let failures = 0;
  for (const activity of targets) {
    const result = await syncOne(activity.id);
    if (result && result.status === 'failed') failures++;
    progress.value.done++;
  }

  running.value = false;
}
</script>

<template>
  <Head title="Activities" />

  <AppLayout :breadcrumbs="breadcrumbs">
    <div class="flex h-full flex-1 flex-col gap-4 rounded-xl p-4">
      <FlashAlert />

      <!-- toolbar -->
      <div class="flex flex-wrap items-end justify-between gap-3">
        <div class="flex items-end gap-2">
          <form @submit.prevent="fetchForm.post(fetchUrl)" class="flex items-end gap-2">
            <div class="w-40">
              <label class="mb-1 block text-xs text-muted-foreground">Import last</label>
              <Input
                v-model="fetchForm.days"
                type="number"
                min="1"
                max="365"
                class="h-9"
              />
            </div>
            <Button type="submit" size="sm" variant="secondary" :disabled="fetchForm.processing">
              <RefreshCw class="size-3.5" :class="{ 'animate-spin': fetchForm.processing }" />
              Fetch from MyWhoosh
            </Button>
          </form>

          <Button size="sm" :disabled="running || !pending.length" @click="syncAllPending()">
            <Loader2 v-if="running" class="size-3.5 animate-spin" />
            <Upload v-else class="size-3.5" />
            Sync {{ pending.length ? pending.length + ' pending' : 'all' }}
          </Button>
        </div>

        <div class="text-right text-xs text-muted-foreground">
          <p>Spoof watch: <span class="font-medium text-foreground">{{ props.watch?.name ?? 'unknown' }}</span></p>
          <a :href="route('watches.index')" class="underline underline-offset-4 hover:text-foreground">change</a>
        </div>
      </div>

      <!-- progress line -->
      <p v-if="running" class="text-sm text-muted-foreground">
        Syncing ride {{ progress.done + 1 }} of {{ progress.total }}… don't close the page.
      </p>

      <!-- table -->
      <Card class="flex-1">
        <CardContent class="p-0">
          <div class="overflow-x-auto">
            <table class="w-full text-sm">
              <thead>
                <tr class="border-b text-left text-xs text-muted-foreground">
                  <th class="whitespace-nowrap px-4 py-3 font-medium">Start</th>
                  <th class="px-4 py-3 font-medium">Activity</th>
                  <th class="whitespace-nowrap px-4 py-3 text-right font-medium">Distance</th>
                  <th class="whitespace-nowrap px-4 py-3 text-right font-medium">Duration</th>
                  <th class="whitespace-nowrap px-4 py-3 text-right font-medium">Power</th>
                  <th class="whitespace-nowrap px-4 py-3 text-right font-medium">HR</th>
                  <th class="whitespace-nowrap px-4 py-3 font-medium">Status</th>
                  <th class="px-4 py-3 font-medium">Watch</th>
                  <th class="whitespace-nowrap px-4 py-3 text-right font-medium">Actions</th>
                </tr>
              </thead>

              <tbody class="divide-y">
                <tr v-if="running && progress.total" class="bg-muted/40">
                  <td :colspan="9" class="px-4 py-2 text-xs text-muted-foreground">
                    {{ progress.done }} / {{ progress.total }} synced in this run
                  </td>
                </tr>

                <tr v-for="row in rows" :key="row.id" class="group">
                  <td class="whitespace-nowrap px-4 py-2 text-xs">
                    {{ fullDate(row.start_at) }}
                  </td>
                  <td class="max-w-64 px-4 py-2">
                    <p class="truncate font-medium" :title="row.title">{{ row.title }}</p>
                    <p v-if="row.route_name" class="truncate text-xs text-muted-foreground" :title="row.route_name">
                      {{ row.route_name }}
                    </p>
                  </td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ km(row.distance_km) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ row.duration ?? '—' }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ watts(row.watt) }}</td>
                  <td class="whitespace-nowrap px-4 py-2 text-right tabular-nums">{{ bpm(row.heartrate) }}</td>
                  <td class="whitespace-nowrap px-4 py-2">
                    <div class="flex items-center gap-2">
                      <StatusBadge :status="row.status" />
                      <span
                        v-if="sessions[row.id]"
                        class="cursor-pointer text-xs underline underline-offset-2 text-muted-foreground hover:text-foreground"
                        @click="reportOpen = true"
                      >
                        report
                      </span>
                    </div>
                    <p v-if="row.status === 'failed' && row.error_message" class="mt-0.5 truncate text-xs text-red-600/80 dark:text-red-400/80" :title="row.error_message">
                      {{ row.error_message }}
                    </p>
                  </td>
                  <td class="max-w-40 whitespace-nowrap px-4 py-2 text-xs">
                    <span class="truncate">{{ row.device ?? '—' }}</span>
                  </td>
                  <td class="whitespace-nowrap px-2 py-2 text-right">
                    <div class="flex items-center justify-end gap-1">
                      <Button
                        size="sm"
                        variant="ghost"
                        class="h-7 px-2"
                        :disabled="running"
                        @click="syncOneWidget(row.id)"
                      >
                        <Upload class="size-3.5" />
                        {{ row.status === 'synced' ? 'Re-sync' : 'Sync' }}
                      </Button>

                      <DropdownMenu>
                        <DropdownMenuTrigger as-child>
                          <Button size="sm" variant="ghost" class="size-7 p-0">
                            <MoreHorizontal class="size-4" />
                          </Button>
                        </DropdownMenuTrigger>
                        <DropdownMenuContent align="end" class="w-44">
                          <DropdownMenuItem
                            class="cursor-pointer"
                            @click="window.location = route('activities.download', row.id)"
                          >
                            <Download class="mr-2 size-4" /> Download fixed FIT
                          </DropdownMenuItem>
                          <DropdownMenuItem
                            class="cursor-pointer text-red-600 focus:text-red-600 dark:text-red-400"
                            @click="() => router.delete(route('activities.destroy', row.id))"
                          >
                            <Trash2 class="mr-2 size-4" /> Remove from list
                          </DropdownMenuItem>
                        </DropdownMenuContent>
                      </DropdownMenu>
                    </div>
                  </td>
                </tr>

                <tr v-if="!rows.length">
                  <td colspan="9" class="px-4 py-16 text-center text-sm text-muted-foreground">
                    <FileCode2 class="mx-auto mb-3 size-8 opacity-40" />
                    Nothing here yet — fetch your MyWhoosh rides above.
                  </td>
                </tr>
              </tbody>
            </table>
          </div>
        </CardContent>

        <!-- pagination -->
        <div
          v-if="props.activities.has_more || props.activities.prev_page"
          class="flex items-center justify-between border-t px-4 py-3 text-xs text-muted-foreground"
        >
          <Button
            size="sm"
            variant="ghost"
            :disabled="!props.activities.prev_page"
            @click="router.visit(route('activities.index', { page: props.activities.prev_page ?? 1 }))"
          >
            <ChevronDown class="size-3.5 rotate-180" /> Newer
          </Button>
          <Button
            size="sm"
            variant="ghost"
            :disabled="!props.activities.has_more"
            @click="router.visit(route('activities.index', { page: props.activities.next_page }))"
          >
            Older <ChevronDown class="size-3.5" />
          </Button>
        </div>
      </Card>
    </div>

    <!-- sync report dialog -->
    <Dialog v-model:open="reportOpen">
      <DialogContent class="max-h-[80vh] overflow-y-auto sm:max-w-xl">
        <DialogHeader>
          <DialogTitle>Sync report</DialogTitle>
          <DialogDescription v-if="running">
            {{ progress.done }} / {{ progress.total }} finished…
          </DialogDescription>
        </DialogHeader>

        <div class="space-y-3 text-sm">
          <div
            v-for="(result, id) in sessions"
            :key="id"
            class="rounded-lg border p-3"
          >
            <div class="mb-1 flex items-center justify-between">
              <StatusBadge :status="result.status" />
              <span v-if="result.message" class="text-xs text-muted-foreground">{{ result.message }}</span>
            </div>
            <div v-for="(line, i) in result.log" :key="i" class="font-mono text-xs text-muted-foreground">
              {{ line }}
            </div>
          </div>

          <p v-if="!Object.keys(sessions).length" class="text-muted-foreground">No syncs in this session yet.</p>
        </div>
      </DialogContent>
    </Dialog>
  </AppLayout>
</template>

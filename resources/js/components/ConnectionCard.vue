<script setup lang="ts">
import type { ConnectionInfo } from '@/types';
import { useForm } from '@inertiajs/vue3';
import { watch } from 'vue';

const props = defineProps<{
    kind: 'mywhoosh' | 'garmin';
    connection: ConnectionInfo | null;
    description: string;
}>();

const routes = {
    mywhoosh: {
        connect: 'connections.mywhoosh.connect',
        disconnect: 'connections.mywhoosh.disconnect',
    },
    garmin: {
        connect: 'connections.garmin.connect',
        disconnect: 'connections.garmin.disconnect',
    },
};

const form = useForm({
    email: props.connection?.email ?? '',
    password: '',
    remember: false,
});

// Keep the email in sync when the connection state changes (re-login flows).
watch(
    () => props.connection?.email,
    (email) => {
        if (email) {
            form.email = email;
        }
    },
);

function submit(mode: 'connect' | 'disconnect') {
    const url = route(routes[props.kind][mode]);
    const method = mode === 'connect' ? 'post' : 'delete';

    form.submit(method, url, {
        onSuccess: () => {
            form.password = '';
            form.email = props.connection?.email ?? '';
        },
    });
}
</script>

<template>
    <div class="rounded-xl border bg-card p-6 shadow-sm">
        <div class="mb-4 flex items-center justify-between gap-4">
            <div>
                <h3 class="flex items-center gap-2 text-lg font-semibold">
                    <span
                        class="inline-block size-2.5 rounded-full"
                        :class="connection ? 'bg-emerald-500' : 'bg-muted-foreground/50'"
                    />
                    {{ kind === 'mywhoosh' ? 'MyWhoosh' : 'Garmin Connect' }}
                </h3>
                <p class="mt-1 text-sm text-muted-foreground">{{ description }}</p>
            </div>

            <div v-if="connection" class="text-right">
                <p class="text-sm font-medium">{{ connection.email }}</p>
                <p class="text-xs text-muted-foreground">
                    <template v-if="connection.remembered">password remembered (auto re-login)</template>
                    <template v-else>password not stored</template>
                    <template
                        v-if="kind === 'garmin' && typeof connection.expires_at === 'string'"
                    >
                        · OAuth2 valid until {{ new Date(connection.expires_at).toLocaleString() }}
                    </template>
                </p>
            </div>
        </div>

        <form
            class="flex flex-wrap items-center gap-2"
            @submit.prevent="submit('connect')"
        >
            <input
                v-model="form.email"
                class="h-9 rounded-md border border-input bg-background px-3 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                type="email"
                required
                :placeholder="kind === 'mywhoosh' ? 'MyWhoosh email' : 'Garmin email'"
                autocomplete="email"
            />
            <input
                v-model="form.password"
                class="h-9 w-44 rounded-md border border-input bg-background px-3 text-sm placeholder:text-muted-foreground focus-visible:outline-none focus-visible:ring-2 focus-visible:ring-ring"
                type="password"
                :required="!connection"
                placeholder="password"
                autocomplete="current-password"
            />

            <button
                type="submit"
                class="inline-flex h-9 items-center rounded-md bg-primary px-4 text-sm font-medium text-primary-foreground shadow hover:bg-primary/90 disabled:opacity-50"
                :disabled="form.processing"
            >
                {{ connection ? 'Update session' : 'Connect' }}
            </button>

            <button
                v-if="connection"
                type="button"
                class="inline-flex h-9 items-center rounded-md border border-border bg-background px-4 text-sm font-medium text-foreground hover:bg-accent disabled:opacity-50"
                :disabled="form.processing"
                @click="submit('disconnect')"
            >
                Disconnect
            </button>

            <label class="flex select-none items-center gap-2 text-xs text-muted-foreground">
                <input
                    v-model="form.remember"
                    type="checkbox"
                    class="size-4 rounded border-input text-primary focus:ring-ring"
                />
                remember password
                <span v-if="!connection" class="hidden md:inline">(encrypted, stored on this server only)</span>
            </label>
        </form>

        <p v-if="connection" class="mt-3 text-xs text-muted-foreground">
            Connected since {{ new Date(connection.connected_at ?? '').toLocaleString() }} — update the
            session whenever the password changed.
        </p>
    </div>
</template>

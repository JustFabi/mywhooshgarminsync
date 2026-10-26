<script setup lang="ts">
import ConnectionCard from '@/components/ConnectionCard.vue';
import FlashAlert from '@/components/FlashAlert.vue';
import AppLayout from '@/layouts/AppLayout.vue';
import type { BreadcrumbItem, ConnectionInfo } from '@/types';
import { Head } from '@inertiajs/vue3';

defineProps<{
    mywhoosh: ConnectionInfo | null;
    garmin: ConnectionInfo | null;
}>();

const breadcrumbs: BreadcrumbItem[] = [{ title: 'Connections', href: '/connections' }];
</script>

<template>
    <Head title="Connections" />

    <AppLayout :breadcrumbs="breadcrumbs">
        <div class="mx-auto flex h-full max-w-3xl flex-1 flex-col gap-4 rounded-xl p-4">
            <FlashAlert />

            <ConnectionCard
                kind="mywhoosh"
                :connection="mywhoosh"
                description="The indoor ride source. Activities are read with the same API the MyWhoosh Android app uses."
            />

            <ConnectionCard
                kind="garmin"
                :connection="garmin"
                description="The upload target. The app performs the Garmin SSO login an Android client would perform and keeps DI OAuth2 tokens."
            />

            <div class="rounded-xl border border-dashed p-4 text-xs text-muted-foreground">
                Credentials are used only to establish the sessions: passwords are stored encrypted
                (Laravel application key) and only when you tick "remember password". Tokens stay encrypted at rest.
                MFA (2FA) is not supported by the SSO flow — disable it if login fails with an MFA error.
            </div>
        </div>
    </AppLayout>
</template>

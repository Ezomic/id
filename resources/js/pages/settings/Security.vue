<script setup lang="ts">
import { Head } from '@inertiajs/vue3';
import type { Props as ConnectedAppsProps } from '@/components/ConnectedApps.vue';
import ConnectedApps from '@/components/ConnectedApps.vue';
import type { Props as ManagePasskeysProps } from '@/components/ManagePasskeys.vue';
import ManagePasskeys from '@/components/ManagePasskeys.vue';
import type { Props as ManageRecoveryCodesProps } from '@/components/ManageRecoveryCodes.vue';
import ManageRecoveryCodes from '@/components/ManageRecoveryCodes.vue';
import { edit } from '@/routes/security';

const props = defineProps<
    ManagePasskeysProps & ManageRecoveryCodesProps & ConnectedAppsProps
>();

defineOptions({
    layout: {
        breadcrumbs: [
            {
                title: 'Security settings',
                href: edit(),
            },
        ],
    },
});
</script>

<template>
    <Head title="Security settings" />

    <h1 class="sr-only">Security settings</h1>

    <div class="space-y-10">
        <ManagePasskeys
            :canManagePasskeys="props.canManagePasskeys"
            :passkeys="props.passkeys"
        />

        <ManageRecoveryCodes
            :newRecoveryCodes="props.newRecoveryCodes"
            :recoveryCodesUnsaved="props.recoveryCodesUnsaved"
            :unusedRecoveryCodes="props.unusedRecoveryCodes"
        />

        <ConnectedApps :connections="props.connections" />
    </div>
</template>

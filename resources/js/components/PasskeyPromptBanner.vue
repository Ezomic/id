<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { edit } from '@/routes/security';
import { dismiss } from '@/routes/security/passkey-prompt';

const page = usePage();

// Nothing has ever asked, which is why production has zero passkeys and one
// account whose only way in is an emailed code.
const needsPasskey = computed(() => page.props.auth.needsPasskey);

function snooze() {
    router.delete(dismiss().url, { preserveScroll: true });
}
</script>

<template>
    <div
        v-if="needsPasskey"
        class="border-b border-border bg-muted/40 px-4 py-2.5 text-sm"
    >
        <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-2">
            <span class="font-medium">Add a passkey.</span>
            <span class="text-muted-foreground">
                Right now an emailed code is the only way into your account.
            </span>
            <Link
                :href="edit()"
                class="font-medium underline underline-offset-4"
            >
                Add one
            </Link>
            <button
                type="button"
                class="ml-auto text-xs text-muted-foreground underline-offset-4 hover:underline"
                @click="snooze"
            >
                Not now
            </button>
        </div>
    </div>
</template>

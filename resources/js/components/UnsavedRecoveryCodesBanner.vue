<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';
import { edit } from '@/routes/security';

const page = usePage();

// Shown wherever the user happens to be, because security settings is not a
// page anyone opens unprompted, and codes nobody has saved are the exact
// lockout recovery codes exist to prevent.
const unsaved = computed(() => page.props.auth.unsavedRecoveryCodes);
</script>

<template>
    <div
        v-if="unsaved"
        class="border-b border-destructive/30 bg-destructive/10 px-4 py-2.5 text-sm"
    >
        <div class="mx-auto flex max-w-5xl flex-wrap items-center gap-x-2">
            <span class="font-medium">
                You have recovery codes you haven't saved.
            </span>
            <span class="text-muted-foreground">
                Without them, losing access to your email locks you out of every
                app.
            </span>
            <Link
                :href="edit()"
                class="font-medium underline underline-offset-4"
            >
                Sort this out
            </Link>
        </div>
    </div>
</template>

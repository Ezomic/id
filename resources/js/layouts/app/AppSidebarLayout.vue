<script setup lang="ts">
import AppContent from '@/components/AppContent.vue';
import AppShell from '@/components/AppShell.vue';
import AppSidebar from '@/components/AppSidebar.vue';
import AppSidebarHeader from '@/components/AppSidebarHeader.vue';
import PasskeyPromptBanner from '@/components/PasskeyPromptBanner.vue';
import { Toaster } from '@/components/ui/sonner';
import UnsavedRecoveryCodesBanner from '@/components/UnsavedRecoveryCodesBanner.vue';
import type { BreadcrumbItem } from '@/types';

type Props = {
    breadcrumbs?: BreadcrumbItem[];
};

withDefaults(defineProps<Props>(), {
    breadcrumbs: () => [],
});
</script>

<template>
    <AppShell variant="sidebar">
        <AppSidebar />
        <AppContent variant="sidebar" class="overflow-x-hidden">
            <AppSidebarHeader :breadcrumbs="breadcrumbs" />
            <UnsavedRecoveryCodesBanner />
            <PasskeyPromptBanner />
            <slot />
        </AppContent>
        <Toaster />
    </AppShell>
</template>

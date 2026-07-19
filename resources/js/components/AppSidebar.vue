<script setup lang="ts">
import { Link, usePage } from '@inertiajs/vue3';
import { Boxes, LayoutGrid, Users } from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavMain from '@/components/NavMain.vue';
import NavUser from '@/components/NavUser.vue';
import {
    Sidebar,
    SidebarContent,
    SidebarFooter,
    SidebarHeader,
    SidebarMenu,
    SidebarMenuButton,
    SidebarMenuItem,
} from '@/components/ui/sidebar';
import { dashboard } from '@/routes';
import { index as adminApplications } from '@/routes/admin/applications';
import { index as adminUsers } from '@/routes/admin/users';
import type { NavItem } from '@/types';

const page = usePage();
const isAdmin = computed(() => page.props.auth.user?.is_admin === true);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Portal',
        href: dashboard(),
        icon: LayoutGrid,
    },
    ...(isAdmin.value
        ? [
              {
                  title: 'Applications',
                  href: adminApplications(),
                  icon: Boxes,
              },
              {
                  title: 'Users',
                  href: adminUsers(),
                  icon: Users,
              },
          ]
        : []),
]);
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboard()">
                            <AppLogo />
                        </Link>
                    </SidebarMenuButton>
                </SidebarMenuItem>
            </SidebarMenu>
        </SidebarHeader>

        <SidebarContent>
            <NavMain :items="mainNavItems" />
        </SidebarContent>

        <SidebarFooter>
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

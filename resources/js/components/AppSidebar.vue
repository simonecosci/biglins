<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    Building2,
    FolderGit2,
    Globe,
    LayoutGrid,
    Package,
    Receipt,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import AppLogo from '@/components/AppLogo.vue';
import NavFooter from '@/components/NavFooter.vue';
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
import { index as companiesIndex } from '@/routes/companies';
import { index as countriesIndex } from '@/routes/countries';
import { index as customersIndex } from '@/routes/customers';
import { index as invoicesIndex } from '@/routes/invoices';
import { index as productsIndex } from '@/routes/products';
import type { NavItem } from '@/types';

const dashboardUrl = computed(() => dashboard().url);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: 'Dashboard',
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    {
        title: 'Customers',
        href: customersIndex().url,
        icon: Users,
    },
    {
        title: 'Companies',
        href: companiesIndex().url,
        icon: Building2,
    },
    {
        title: 'Invoices',
        href: invoicesIndex().url,
        icon: Receipt,
    },
    {
        title: 'Products',
        href: productsIndex().url,
        icon: Package,
    },
    {
        title: 'Countries',
        href: countriesIndex().url,
        icon: Globe,
    },
]);

const footerNavItems: NavItem[] = [
    {
        title: 'Repository',
        href: 'https://github.com/simonecosci/biglins',
        icon: FolderGit2,
    },
    {
        title: 'Documentation',
        href: 'https://github.com/simonecosci/biglins/wiki',
        icon: BookOpen,
    },
];
</script>

<template>
    <Sidebar collapsible="icon" variant="inset">
        <SidebarHeader>
            <SidebarMenu>
                <SidebarMenuItem>
                    <SidebarMenuButton size="lg" as-child>
                        <Link :href="dashboardUrl">
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
            <NavFooter :items="footerNavItems" />
            <NavUser />
        </SidebarFooter>
    </Sidebar>
    <slot />
</template>

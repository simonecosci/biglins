<script setup lang="ts">
import { Link } from '@inertiajs/vue3';
import {
    BookOpen,
    Building2,
    FileSignature,
    FolderGit2,
    Globe,
    LayoutGrid,
    Package,
    Receipt,
    StickyNote,
    Users,
} from '@lucide/vue';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
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
import { index as estimationsIndex } from '@/routes/estimations';
import { index as invoicesIndex } from '@/routes/invoices';
import { index as notesIndex } from '@/routes/notes';
import { index as productsIndex } from '@/routes/products';
import type { NavItem } from '@/types';

const { t } = useI18n();

const dashboardUrl = computed(() => dashboard().url);

const mainNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.dashboard'),
        href: dashboardUrl.value,
        icon: LayoutGrid,
    },
    {
        title: t('nav.estimations'),
        href: estimationsIndex().url,
        icon: FileSignature,
    },
    {
        title: t('nav.invoices'),
        href: invoicesIndex().url,
        icon: Receipt,
    },
    {
        title: t('nav.customers'),
        href: customersIndex().url,
        icon: Users,
    },
    {
        title: t('nav.products'),
        href: productsIndex().url,
        icon: Package,
    },
    {
        title: t('nav.notes'),
        href: notesIndex().url,
        icon: StickyNote,
    },
    {
        title: t('nav.countries'),
        href: countriesIndex().url,
        icon: Globe,
    },
    {
        title: t('nav.companies'),
        href: companiesIndex().url,
        icon: Building2,
    },
]);

const footerNavItems = computed<NavItem[]>(() => [
    {
        title: t('nav.repository'),
        href: 'https://github.com/simonecosci/biglins',
        icon: FolderGit2,
    },
    {
        title: t('nav.documentation'),
        href: 'https://github.com/simonecosci/biglins/wiki',
        icon: BookOpen,
    },
]);
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

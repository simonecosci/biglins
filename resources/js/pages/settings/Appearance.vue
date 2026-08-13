<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import AppearanceTabs from '@/components/AppearanceTabs.vue';
import Heading from '@/components/Heading.vue';
import { edit } from '@/routes/appearance';
import type { BreadcrumbItem } from '@/types';

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        {
            title: t('settings.appearance.title'),
            href: edit(),
        },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('settings.appearance.title')" />

    <h1 class="sr-only">{{ t('settings.appearance.title') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.appearance.title')"
            :description="t('settings.appearance.description')"
        />
        <AppearanceTabs />
    </div>
</template>

<script setup lang="ts">
import { Head, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SubscriptionsWidget from '@/components/dashboard/SubscriptionsWidget.vue';
import { dashboard } from '@/routes';
import type { BreadcrumbItem } from '@/types';

type SubscriptionStatus = 'expired' | 'expiring_soon' | 'upcoming';

type SubscriptionRow = {
    id: string;
    description: string;
    price: number;
    quantity: number;
    expiration_date: string;
    urgency: SubscriptionStatus;
};

type SubscriptionGroup = {
    invoice_id: string;
    invoice_number: string;
    customer_name: string | null;
    status: SubscriptionStatus;
    total: number;
    rows: SubscriptionRow[];
};

const props = defineProps<{
    subscriptions: {
        expiredCount: number;
        expiringSoonCount: number;
        groups: SubscriptionGroup[];
    };
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        {
            title: t('dashboard.title'),
            href: dashboard(),
        },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('dashboard.title')" />

    <div
        class="flex h-full flex-1 flex-col gap-4 overflow-x-auto rounded-xl p-4"
    >
        <SubscriptionsWidget
            :expired-count="props.subscriptions.expiredCount"
            :expiring-soon-count="props.subscriptions.expiringSoonCount"
            :groups="props.subscriptions.groups"
        />
    </div>
</template>

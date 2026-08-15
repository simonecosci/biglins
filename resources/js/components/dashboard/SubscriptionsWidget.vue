<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import SubscriptionController from '@/actions/App/Http/Controllers/SubscriptionController';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { confirmDialog } from '@/lib/confirmDialog';

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
    expiredCount: number;
    expiringSoonCount: number;
    groups: SubscriptionGroup[];
}>();

const { t } = useI18n();

const badgeClasses: Record<SubscriptionStatus, string> = {
    expired: 'border-transparent bg-red-600 text-white',
    expiring_soon: 'border-transparent bg-orange-500 text-white',
    upcoming: 'border-transparent bg-green-600 text-white',
};

function formatDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

function renewGroup(invoiceId: string): void {
    router.post(SubscriptionController.renew(invoiceId).url);
}

async function cancelGroup(invoiceId: string): Promise<void> {
    if (await confirmDialog(t('dashboard.subscriptions.confirmCancelGroup'))) {
        router.post(SubscriptionController.cancelGroup(invoiceId).url);
    }
}

async function cancelRow(rowId: string): Promise<void> {
    if (await confirmDialog(t('dashboard.subscriptions.confirmCancelRow'))) {
        router.post(SubscriptionController.cancelRow(rowId).url);
    }
}
</script>

<template>
    <div class="space-y-4">
        <div class="grid grid-cols-2 gap-4">
            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        {{ t('dashboard.subscriptions.expiredLabel') }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-bold text-red-600">
                        {{ props.expiredCount }}
                    </p>
                </CardContent>
            </Card>
            <Card>
                <CardHeader>
                    <CardTitle
                        class="text-sm font-medium text-muted-foreground"
                    >
                        {{ t('dashboard.subscriptions.expiringSoonLabel') }}
                    </CardTitle>
                </CardHeader>
                <CardContent>
                    <p class="text-3xl font-bold text-orange-500">
                        {{ props.expiringSoonCount }}
                    </p>
                </CardContent>
            </Card>
        </div>

        <p
            v-if="props.groups.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ t('dashboard.subscriptions.empty') }}
        </p>

        <Card v-for="group in props.groups" :key="group.invoice_id">
            <CardHeader class="flex flex-row items-center justify-between">
                <div>
                    <CardTitle>{{ group.customer_name ?? '—' }}</CardTitle>
                    <p class="text-sm text-muted-foreground">
                        {{ group.invoice_number }}
                    </p>
                </div>
                <Badge :class="badgeClasses[group.status]">
                    {{ t(`dashboard.subscriptions.status.${group.status}`) }}
                </Badge>
            </CardHeader>
            <CardContent class="space-y-3">
                <div
                    v-for="row in group.rows"
                    :key="row.id"
                    class="flex items-center justify-between gap-2 text-sm"
                >
                    <span>{{ row.description }}</span>
                    <span class="text-muted-foreground">{{
                        formatDate(row.expiration_date)
                    }}</span>
                    <span>{{ row.price.toFixed(2) }}</span>
                    <Button
                        variant="ghost"
                        size="sm"
                        @click="cancelRow(row.id)"
                    >
                        {{ t('dashboard.subscriptions.cancelRow') }}
                    </Button>
                </div>

                <div class="flex items-center justify-between border-t pt-3">
                    <span class="font-medium">
                        {{
                            t('dashboard.subscriptions.total', {
                                amount: group.total.toFixed(2),
                            })
                        }}
                    </span>
                    <div class="flex gap-2">
                        <Button
                            variant="outline"
                            size="sm"
                            @click="cancelGroup(group.invoice_id)"
                        >
                            {{ t('dashboard.subscriptions.cancelGroup') }}
                        </Button>
                        <Button size="sm" @click="renewGroup(group.invoice_id)">
                            {{ t('dashboard.subscriptions.renewGroup') }}
                        </Button>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>

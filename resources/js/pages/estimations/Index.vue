<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/estimations';
import type { BreadcrumbItem } from '@/types';

type EstimationStatus = 'pending' | 'accepted' | 'rejected';

type Estimation = {
    id: string;
    number: string;
    estimation_date: string;
    status: EstimationStatus;
    total: string | number;
    customer: { id: string; name: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('estimations.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const props = defineProps<{
    estimations: {
        data: Estimation[];
        links: PaginationLink[];
    };
    filters: {
        search: string;
    };
}>();

const search = ref(props.filters.search);

function onSearch(): void {
    router.get(
        index().url,
        { search: search.value },
        { preserveState: true, replace: true },
    );
}

function formatTotal(total: string | number): string {
    return Number(total).toFixed(2);
}

function formatDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

function statusBadgeVariant(
    status: EstimationStatus,
): 'default' | 'secondary' | 'destructive' {
    if (status === 'accepted') {
        return 'default';
    }

    if (status === 'rejected') {
        return 'destructive';
    }

    return 'secondary';
}
</script>

<template>
    <Head :title="t('estimations.index.title')" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                :title="t('estimations.index.title')"
                :description="t('estimations.index.description')"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    {{ t('estimations.index.newButton') }}
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input
                v-model="search"
                :placeholder="t('estimations.index.searchPlaceholder')"
            />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.number') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.date') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.customer') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.status') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('estimations.index.columns.total') }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="estimation in estimations.data"
                        :key="estimation.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ estimation.number }}</td>
                        <td class="px-4 py-2">
                            {{ formatDate(estimation.estimation_date) }}
                        </td>
                        <td class="px-4 py-2">
                            {{ estimation.customer?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <Badge
                                :variant="statusBadgeVariant(estimation.status)"
                            >
                                {{
                                    t(`estimations.status.${estimation.status}`)
                                }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">
                            {{ formatTotal(estimation.total) }}
                        </td>
                        <td class="space-x-1 px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('common.actions.edit')"
                            >
                                <Link :href="edit(estimation.id)">
                                    <Pencil />
                                </Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="estimations.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            {{ t('estimations.index.empty') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="estimations.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in estimations.links"
                :key="i"
                :href="link.url ?? ''"
                :class="[
                    'rounded-md px-3 py-1 text-sm',
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent',
                    !link.url && 'pointer-events-none opacity-50',
                ]"
            >
                <span v-html="link.label" />
            </Link>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Copy, Eye, FileText, Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import Heading from '@/components/Heading.vue';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/invoices';
import type { BreadcrumbItem } from '@/types';

type Invoice = {
    id: string;
    number: string;
    invoice_date: string;
    paid: boolean;
    total: string | number;
    type: string;
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
        { title: t('invoices.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const props = defineProps<{
    invoices: {
        data: Invoice[];
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

function typeLabel(type: string): string {
    return t(`invoices.type.${type}`);
}

function formatDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}
</script>

<template>
    <Head :title="t('invoices.index.title')" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                :title="t('invoices.index.title')"
                :description="t('invoices.index.description')"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    {{ t('invoices.index.newButton') }}
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input
                v-model="search"
                :placeholder="t('invoices.index.searchPlaceholder')"
            />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ t('invoices.index.columns.number') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('invoices.index.columns.date') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('invoices.index.columns.customer') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('invoices.index.columns.type') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('invoices.index.columns.total') }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="invoice in invoices.data"
                        :key="invoice.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ invoice.number }}</td>
                        <td class="px-4 py-2">
                            {{ formatDate(invoice.invoice_date) }}
                        </td>
                        <td class="px-4 py-2">
                            {{ invoice.customer?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-2">
                            <div class="flex items-center gap-1">
                                <Badge
                                    :variant="
                                        invoice.type === 'credit_note'
                                            ? 'secondary'
                                            : 'outline'
                                    "
                                >
                                    {{ typeLabel(invoice.type) }}
                                </Badge>
                                <Badge
                                    :variant="
                                        invoice.paid ? 'default' : 'secondary'
                                    "
                                >
                                    {{
                                        invoice.paid
                                            ? t('invoices.index.paid')
                                            : t('invoices.index.unpaid')
                                    }}
                                </Badge>
                            </div>
                        </td>
                        <td class="px-4 py-2">
                            {{ formatTotal(invoice.total) }}
                        </td>
                        <td class="space-x-1 px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('invoices.index.preview')"
                            >
                                <a
                                    :href="
                                        InvoiceController.preview(invoice.id)
                                            .url
                                    "
                                    target="_blank"
                                    rel="noopener"
                                >
                                    <Eye />
                                </a>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('invoices.index.pdf')"
                            >
                                <a
                                    :href="
                                        InvoiceController.pdf(invoice.id).url
                                    "
                                >
                                    <FileText />
                                </a>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('common.actions.edit')"
                            >
                                <Link :href="edit(invoice.id)">
                                    <Pencil />
                                </Link>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('invoices.index.duplicate')"
                            >
                                <Link
                                    :href="
                                        create({
                                            query: { duplicate: invoice.id },
                                        })
                                    "
                                >
                                    <Copy />
                                </Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="invoices.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            {{ t('invoices.index.empty') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="invoices.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in invoices.links"
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

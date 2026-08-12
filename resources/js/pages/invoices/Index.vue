<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Copy, Eye, FileText, Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
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
    customer: { id: string; name: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

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

function formatDate(date: string): string {
    const [year, month, day] = date.split('-');

    return `${day}/${month}/${year}`;
}

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Invoices', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="Invoices" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading title="Invoices" description="Manage your invoices" />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    New invoice
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input
                v-model="search"
                placeholder="Search by number or customer..."
            />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Number</th>
                        <th class="px-4 py-2 font-medium">Date</th>
                        <th class="px-4 py-2 font-medium">Customer</th>
                        <th class="px-4 py-2 font-medium">Paid</th>
                        <th class="px-4 py-2 font-medium">Total</th>
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
                            <Badge
                                :variant="
                                    invoice.paid ? 'default' : 'secondary'
                                "
                            >
                                {{ invoice.paid ? 'Paid' : 'Unpaid' }}
                            </Badge>
                        </td>
                        <td class="px-4 py-2">
                            {{ formatTotal(invoice.total) }}
                        </td>
                        <td class="space-x-1 px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                title="Preview"
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
                                title="PDF"
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
                                title="Edit"
                            >
                                <Link :href="edit(invoice.id)">
                                    <Pencil />
                                </Link>
                            </Button>
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                title="Duplica"
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
                            No invoices found.
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

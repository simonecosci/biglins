<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/invoices';
import type { BreadcrumbItem } from '@/types';

type Customer = {
    id: string;
    name: string;
};

type Company = {
    id: string;
    name: string;
};

type Product = {
    id: string;
    code: string | null;
    description: string;
    price: number;
};

type InvoiceRowForm = {
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
};

const props = defineProps<{
    customers: Customer[];
    companies: Company[];
    products: Product[];
    defaultCompanyId: string | null;
    nextNumber: string;
    duplicate: {
        customer_id: string;
        company_id: string;
        note: string | null;
        language: string;
        rows: InvoiceRowForm[];
    } | null;
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Invoices', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});

const form = useForm({
    number: props.nextNumber,
    invoice_date: new Date().toISOString().slice(0, 10),
    paid: false,
    customer_id: props.duplicate?.customer_id ?? '',
    company_id: props.duplicate?.company_id ?? props.defaultCompanyId ?? '',
    note: props.duplicate?.note ?? '',
    language: props.duplicate?.language ?? 'es',
    rows: props.duplicate?.rows ?? [
        { description: '', quantity: 1, price: 0, vat_rate: 0 },
    ],
});

const selectedProductIds = ref<(string | undefined)[]>(
    form.rows.map(() => undefined),
);

function addRow(): void {
    form.rows.push({ description: '', quantity: 1, price: 0, vat_rate: 0 });
    selectedProductIds.value.push(undefined);
}

function removeRow(index: number): void {
    form.rows.splice(index, 1);
    selectedProductIds.value.splice(index, 1);
}

function applyProduct(index: number, productId: unknown): void {
    selectedProductIds.value[index] = productId as string | undefined;

    const product = props.products.find((p) => p.id === productId);

    if (!product) {
        return;
    }

    form.rows[index].description = product.description;
    form.rows[index].price = product.price;
}

const total = computed(() =>
    form.rows.reduce((sum, row) => {
        const lineTotal = row.price * row.quantity;

        return sum + lineTotal + (lineTotal * row.vat_rate) / 100;
    }, 0),
);

function submit(): void {
    form.post(InvoiceController.store().url);
}
</script>

<template>
    <Head title="New invoice" />

    <div class="flex max-w-2xl flex-col space-y-6">
        <Heading title="New invoice" description="Create a new invoice" />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="number">Number</Label>
                    <Input
                        id="number"
                        v-model="form.number"
                        placeholder="2026-0001"
                    />
                    <InputError :message="form.errors.number" />
                </div>
                <div class="grid gap-2">
                    <Label for="invoice_date">Date</Label>
                    <Input
                        id="invoice_date"
                        v-model="form.invoice_date"
                        type="date"
                    />
                    <InputError :message="form.errors.invoice_date" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="customer_id">Customer</Label>
                <Select v-model="form.customer_id">
                    <SelectTrigger id="customer_id" class="w-full">
                        <SelectValue placeholder="Select a customer" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="customer in customers"
                            :key="customer.id"
                            :value="customer.id"
                        >
                            {{ customer.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.customer_id" />
            </div>

            <div class="grid gap-2">
                <Label for="company_id">Issuing company</Label>
                <Select v-model="form.company_id">
                    <SelectTrigger id="company_id" class="w-full">
                        <SelectValue placeholder="Select a company" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="company in companies"
                            :key="company.id"
                            :value="company.id"
                        >
                            {{ company.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.company_id" />
            </div>

            <div class="grid gap-2">
                <Label for="language">Language</Label>
                <Select v-model="form.language">
                    <SelectTrigger id="language" class="w-full">
                        <SelectValue placeholder="Select a language" />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="it">Italiano</SelectItem>
                        <SelectItem value="en">English</SelectItem>
                        <SelectItem value="es">Español</SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.language" />
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="paid" v-model="form.paid" />
                <Label for="paid">Paid</Label>
            </div>

            <div class="grid gap-2">
                <Label for="note">Note</Label>
                <textarea
                    id="note"
                    v-model="form.note"
                    rows="3"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    placeholder="Optional note"
                />
                <InputError :message="form.errors.note" />
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <Label>Rows</Label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addRow"
                    >
                        <Plus />
                        Add row
                    </Button>
                </div>
                <InputError :message="form.errors.rows" />

                <div
                    class="grid grid-cols-[12rem_1fr_6rem_8rem_6rem_2.5rem] gap-2 text-sm text-muted-foreground"
                >
                    <span>Product</span>
                    <span>Description</span>
                    <span>Quantity</span>
                    <span>Price</span>
                    <span>VAT (%)</span>
                    <span></span>
                </div>

                <div
                    v-for="(row, i) in form.rows"
                    :key="i"
                    class="grid grid-cols-[12rem_1fr_6rem_8rem_6rem_2.5rem] items-start gap-2"
                >
                    <Select
                        :model-value="selectedProductIds[i]"
                        @update:model-value="(value) => applyProduct(i, value)"
                    >
                        <SelectTrigger class="w-full">
                            <SelectValue placeholder="From catalog" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem
                                v-for="product in products"
                                :key="product.id"
                                :value="product.id"
                            >
                                {{
                                    product.code
                                        ? `${product.code} — ${product.description}`
                                        : product.description
                                }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <div class="grid gap-1">
                        <Input
                            v-model="row.description"
                            placeholder="Description"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.description`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.quantity"
                            type="number"
                            step="0.01"
                            min="0.01"
                            placeholder="Quantity"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.quantity`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.price"
                            type="number"
                            step="0.01"
                            min="0"
                            placeholder="Price"
                        />
                        <InputError :message="form.errors[`rows.${i}.price`]" />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.vat_rate"
                            type="number"
                            step="0.01"
                            min="0"
                            max="100"
                            placeholder="VAT %"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.vat_rate`]"
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="form.rows.length === 1"
                        @click="removeRow(i)"
                    >
                        <Trash2 />
                    </Button>
                </div>

                <p class="text-right text-sm text-muted-foreground">
                    Total: {{ total.toFixed(2) }}
                </p>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="form.processing" type="submit">Save</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    Cancel
                </Link>
            </div>
        </form>
    </div>
</template>

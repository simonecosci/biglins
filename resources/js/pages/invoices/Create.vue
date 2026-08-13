<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import ProductPicker from '@/components/ProductPicker.vue';
import type { PickedProduct } from '@/components/ProductPicker.vue';
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

type InvoiceRowForm = {
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
};

const props = defineProps<{
    customers: Customer[];
    nextNumber: string;
    duplicate: {
        customer_id: string;
        note: string | null;
        language: string;
        rows: InvoiceRowForm[];
    } | null;
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('invoices.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    number: props.nextNumber,
    invoice_date: new Date().toISOString().slice(0, 10),
    paid: false,
    customer_id: props.duplicate?.customer_id ?? '',
    note: props.duplicate?.note ?? '',
    language: props.duplicate?.language ?? 'es',
    rows: props.duplicate?.rows ?? [
        { description: '', quantity: 1, price: 0, vat_rate: 0 },
    ],
});

const selectedProducts = ref<(PickedProduct | undefined)[]>(
    form.rows.map(() => undefined),
);

function addRow(): void {
    form.rows.push({ description: '', quantity: 1, price: 0, vat_rate: 0 });
    selectedProducts.value.push(undefined);
}

function removeRow(index: number): void {
    form.rows.splice(index, 1);
    selectedProducts.value.splice(index, 1);
}

function productLabel(product: PickedProduct | undefined): string | null {
    if (!product) {
        return null;
    }

    return product.code
        ? `${product.code} — ${product.description}`
        : product.description;
}

function applyProduct(index: number, product: PickedProduct): void {
    selectedProducts.value[index] = product;
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
    <Head :title="t('invoices.create.title')" />

    <div class="flex max-w-2xl flex-col space-y-6">
        <Heading
            :title="t('invoices.create.title')"
            :description="t('invoices.create.description')"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="number">{{
                        t('invoices.create.number')
                    }}</Label>
                    <Input
                        id="number"
                        v-model="form.number"
                        placeholder="2026-0001"
                    />
                    <InputError :message="form.errors.number" />
                </div>
                <div class="grid gap-2">
                    <Label for="invoice_date">{{
                        t('invoices.create.date')
                    }}</Label>
                    <Input
                        id="invoice_date"
                        v-model="form.invoice_date"
                        type="date"
                    />
                    <InputError :message="form.errors.invoice_date" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="customer_id">{{
                    t('invoices.create.customer')
                }}</Label>
                <Select v-model="form.customer_id">
                    <SelectTrigger id="customer_id" class="w-full">
                        <SelectValue
                            :placeholder="t('invoices.create.selectCustomer')"
                        />
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
                <Label for="language">{{
                    t('invoices.create.language')
                }}</Label>
                <Select v-model="form.language">
                    <SelectTrigger id="language" class="w-full">
                        <SelectValue
                            :placeholder="t('invoices.create.selectLanguage')"
                        />
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
                <Label for="paid">{{ t('invoices.create.paid') }}</Label>
            </div>

            <div class="grid gap-2">
                <Label for="note">{{ t('invoices.create.note') }}</Label>
                <textarea
                    id="note"
                    v-model="form.note"
                    rows="3"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    :placeholder="t('invoices.create.notePlaceholder')"
                />
                <InputError :message="form.errors.note" />
            </div>

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <Label>{{ t('invoices.create.rows') }}</Label>
                    <Button
                        type="button"
                        variant="outline"
                        size="sm"
                        @click="addRow"
                    >
                        <Plus />
                        {{ t('common.actions.addRow') }}
                    </Button>
                </div>
                <InputError :message="form.errors.rows" />

                <div
                    class="grid grid-cols-[2.5rem_1fr_6rem_8rem_6rem_2.5rem] gap-2 text-sm text-muted-foreground"
                >
                    <span></span>
                    <span>{{ t('invoices.create.rowDescription') }}</span>
                    <span>{{ t('invoices.create.rowQuantity') }}</span>
                    <span>{{ t('invoices.create.rowPrice') }}</span>
                    <span>{{ t('invoices.create.rowVat') }}</span>
                    <span></span>
                </div>

                <div
                    v-for="(row, i) in form.rows"
                    :key="i"
                    class="grid grid-cols-[2.5rem_1fr_6rem_8rem_6rem_2.5rem] items-start gap-2"
                >
                    <ProductPicker
                        :selected-label="productLabel(selectedProducts[i])"
                        @select="(product) => applyProduct(i, product)"
                    />
                    <div class="grid gap-1">
                        <Input
                            v-model="row.description"
                            :placeholder="t('invoices.create.rowDescription')"
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
                            :placeholder="t('invoices.create.rowQuantity')"
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
                            :placeholder="t('invoices.create.rowPrice')"
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
                            :placeholder="
                                t('invoices.create.rowVatPlaceholder')
                            "
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
                    {{
                        t('invoices.create.total', { amount: total.toFixed(2) })
                    }}
                </p>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="form.processing" type="submit">{{
                    t('common.actions.save')
                }}</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    {{ t('common.actions.cancel') }}
                </Link>
            </div>
        </form>
    </div>
</template>

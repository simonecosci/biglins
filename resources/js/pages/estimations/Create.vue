<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Plus, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EstimationController from '@/actions/App/Http/Controllers/EstimationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import MarkdownField from '@/components/MarkdownField.vue';
import ProductPicker from '@/components/ProductPicker.vue';
import type { PickedProduct } from '@/components/ProductPicker.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { confirmDialog } from '@/lib/confirmDialog';
import { index } from '@/routes/estimations';
import type { BreadcrumbItem } from '@/types';

type Customer = {
    id: string;
    name: string;
};

type EstimationRowForm = {
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    note: string | null;
};

const props = defineProps<{
    customers: Customer[];
    nextNumber: string;
    duplicate: {
        customer_id?: string;
        body: string | null;
        language: string;
        rows: EstimationRowForm[];
    } | null;
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('estimations.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    number: props.nextNumber,
    customer_id: props.duplicate?.customer_id ?? '',
    estimation_date: new Date().toISOString().slice(0, 10),
    expiration_date: new Date(Date.now() + 30 * 24 * 60 * 60 * 1000)
        .toISOString()
        .slice(0, 10),
    language: props.duplicate?.language ?? 'es',
    body: props.duplicate?.body ?? '',
    rows: props.duplicate?.rows ?? [
        { description: '', quantity: 1, price: 0, vat_rate: 0, note: null },
    ],
});

const selectedProducts = ref<(PickedProduct | undefined)[]>(
    form.rows.map(() => undefined),
);

function addRow(): void {
    form.rows.push({
        description: '',
        quantity: 1,
        price: 0,
        vat_rate: 0,
        note: null,
    });
    selectedProducts.value.push(undefined);
}

async function removeRow(index: number): Promise<void> {
    if (!(await confirmDialog(t('estimations.create.confirmRemoveRow')))) {
        return;
    }

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
    form.post(EstimationController.store().url);
}
</script>

<template>
    <Head :title="t('estimations.create.title')" />

    <div class="flex max-w-5xl flex-col space-y-6">
        <Heading
            :title="t('estimations.create.title')"
            :description="t('estimations.create.description')"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
                <div class="grid gap-2">
                    <Label for="number">{{
                        t('estimations.create.number')
                    }}</Label>
                    <Input
                        id="number"
                        v-model="form.number"
                        placeholder="2026-0001"
                    />
                    <InputError :message="form.errors.number" />
                </div>
                <div class="grid gap-2">
                    <Label for="estimation_date">{{
                        t('estimations.create.date')
                    }}</Label>
                    <Input
                        id="estimation_date"
                        v-model="form.estimation_date"
                        type="date"
                    />
                    <InputError :message="form.errors.estimation_date" />
                </div>
                <div class="grid gap-2">
                    <Label for="expiration_date">{{
                        t('estimations.create.expirationDate')
                    }}</Label>
                    <Input
                        id="expiration_date"
                        v-model="form.expiration_date"
                        type="date"
                    />
                    <InputError :message="form.errors.expiration_date" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="customer_id">{{
                        t('estimations.create.customer')
                    }}</Label>
                    <Select v-model="form.customer_id">
                        <SelectTrigger id="customer_id" class="w-full">
                            <SelectValue
                                :placeholder="
                                    t('estimations.create.selectCustomer')
                                "
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
                        t('estimations.create.language')
                    }}</Label>
                    <Select v-model="form.language">
                        <SelectTrigger id="language" class="w-full">
                            <SelectValue
                                :placeholder="
                                    t('estimations.create.selectLanguage')
                                "
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
            </div>

            <MarkdownField
                v-model="form.body"
                :label="t('estimations.create.body')"
                :placeholder="t('estimations.create.bodyPlaceholder')"
                :error="form.errors.body"
            />

            <div class="space-y-3">
                <div class="flex items-center justify-between">
                    <Label>{{ t('estimations.create.rows') }}</Label>
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
                    class="grid grid-cols-[2.5rem_1fr_6rem_8rem_6rem_1fr_2.5rem] gap-2 text-sm text-muted-foreground"
                >
                    <span></span>
                    <span>{{ t('estimations.create.rowDescription') }}</span>
                    <span>{{ t('estimations.create.rowQuantity') }}</span>
                    <span>{{ t('estimations.create.rowPrice') }}</span>
                    <span>{{ t('estimations.create.rowVat') }}</span>
                    <span>{{ t('estimations.create.rowNote') }}</span>
                    <span></span>
                </div>

                <div
                    v-for="(row, i) in form.rows"
                    :key="i"
                    class="grid grid-cols-[2.5rem_1fr_6rem_8rem_6rem_1fr_2.5rem] items-start gap-2"
                >
                    <ProductPicker
                        :selected-label="productLabel(selectedProducts[i])"
                        @select="(product) => applyProduct(i, product)"
                    />
                    <div class="grid gap-1">
                        <Input
                            v-model="row.description"
                            :placeholder="
                                t('estimations.create.rowDescription')
                            "
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
                            :placeholder="t('estimations.create.rowQuantity')"
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
                            :placeholder="t('estimations.create.rowPrice')"
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
                                t('estimations.create.rowVatPlaceholder')
                            "
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.vat_rate`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            :model-value="row.note ?? ''"
                            :placeholder="t('estimations.create.rowNote')"
                            @update:model-value="
                                (value) => (row.note = String(value) || null)
                            "
                        />
                        <InputError :message="form.errors[`rows.${i}.note`]" />
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
                        t('estimations.create.total', {
                            amount: total.toFixed(2),
                        })
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

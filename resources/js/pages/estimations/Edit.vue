<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/vue3';
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
import { index } from '@/routes/estimations';
import type { BreadcrumbItem } from '@/types';

type Customer = {
    id: string;
    name: string;
};

type EstimationStatus = 'pending' | 'accepted' | 'rejected';

type EstimationRow = {
    id: string;
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    note: string | null;
};

type Estimation = {
    id: string;
    number: string;
    customer_id: string;
    estimation_date: string;
    expiration_date: string;
    language: string;
    body: string | null;
    status: EstimationStatus;
    invoice_id: string | null;
    rows: EstimationRow[];
};

type EstimationRowForm = {
    id?: string;
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    note: string | null;
};

const props = defineProps<{
    estimation: Estimation;
    customers: Customer[];
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('estimations.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    estimation_date: props.estimation.estimation_date,
    expiration_date: props.estimation.expiration_date,
    language: props.estimation.language,
    status: props.estimation.status,
    body: props.estimation.body ?? '',
    rows: props.estimation.rows.map((row) => ({
        id: row.id,
        description: row.description,
        quantity: row.quantity,
        price: row.price,
        vat_rate: row.vat_rate,
        note: row.note,
    })) as EstimationRowForm[],
});

const isConverted = computed(() => props.estimation.invoice_id !== null);

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

function removeRow(index: number): void {
    if (!confirm(t('estimations.create.confirmRemoveRow'))) {
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
    form.put(EstimationController.update(props.estimation.id).url);
}

function onDelete(): void {
    if (confirm(t('estimations.edit.confirmDelete'))) {
        router.delete(EstimationController.destroy(props.estimation.id).url);
    }
}
</script>

<template>
    <Head :title="t('estimations.edit.title')" />

    <div class="flex max-w-5xl flex-col space-y-6">
        <Heading
            :title="t('estimations.edit.title')"
            :description="
                t('estimations.edit.description', { number: estimation.number })
            "
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid grid-cols-3 gap-4">
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
                <div class="grid gap-2">
                    <Label for="status">{{
                        t('estimations.edit.status')
                    }}</Label>
                    <Select v-model="form.status">
                        <SelectTrigger id="status" class="w-full">
                            <SelectValue />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="pending">{{
                                t('estimations.status.pending')
                            }}</SelectItem>
                            <SelectItem value="accepted">{{
                                t('estimations.status.accepted')
                            }}</SelectItem>
                            <SelectItem value="rejected">{{
                                t('estimations.status.rejected')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.status" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="language">{{
                    t('estimations.create.language')
                }}</Label>
                <Select v-model="form.language">
                    <SelectTrigger id="language" class="w-full max-w-xs">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem value="it">Italiano</SelectItem>
                        <SelectItem value="en">English</SelectItem>
                        <SelectItem value="es">Español</SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.language" />
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
                    :key="row.id ?? `new-${i}`"
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
                <Button
                    :disabled="form.processing || isConverted"
                    type="submit"
                    >{{ t('common.actions.save') }}</Button
                >
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    {{ t('common.actions.cancel') }}
                </Link>
            </div>
        </form>

        <div class="border-t pt-6">
            <Button
                variant="destructive"
                type="button"
                :disabled="isConverted"
                @click="onDelete"
            >
                {{ t('estimations.edit.deleteButton') }}
            </Button>
        </div>
    </div>
</template>

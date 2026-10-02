<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { Eye, FileText, Plus, Send, Trash2 } from '@lucide/vue';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceController from '@/actions/App/Http/Controllers/InvoiceController';
import InvoiceSubmissionController from '@/actions/App/Http/Controllers/InvoiceSubmissionController';
import AlertError from '@/components/AlertError.vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import InvoiceSubmissionPanel from '@/components/InvoiceSubmissionPanel.vue';
import type { Submission } from '@/components/InvoiceSubmissionPanel.vue';
import NotePicker from '@/components/NotePicker.vue';
import type { PickedNote } from '@/components/NotePicker.vue';
import ProductPicker from '@/components/ProductPicker.vue';
import type { PickedProduct } from '@/components/ProductPicker.vue';
import SendEmailDialog from '@/components/SendEmailDialog.vue';
import { Badge } from '@/components/ui/badge';
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
import VatExemptionSelect from '@/components/VatExemptionSelect.vue';
import { confirmDialog } from '@/lib/confirmDialog';
import { invoiceStatusVariant } from '@/lib/invoiceStatus';
import type { InvoiceStatus } from '@/lib/invoiceStatus';
import { addDurationToDate } from '@/lib/productDuration';
import { index } from '@/routes/invoices';
import type { BreadcrumbItem } from '@/types';

type Customer = {
    id: string;
    name: string;
    email: string | null;
};

type SubscriptionStatus = 'active' | 'cancelled';

type InvoiceRow = {
    id: string;
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    vat_exemption_code: string | null;
    expiration_date: string | null;
    subscription_status: SubscriptionStatus;
};

type Invoice = {
    id: string;
    number: string | null;
    status: InvoiceStatus;
    issued_at: string | null;
    invoice_date: string;
    paid: boolean;
    customer_id: string;
    company_id: string;
    note: string | null;
    language: string;
    type: string;
    sent_at: string | null;
    sent_to: string | null;
    rows: InvoiceRow[];
};

type InvoiceRowForm = {
    id?: string;
    description: string;
    quantity: number;
    price: number;
    vat_rate: number;
    vat_exemption_code: string | null;
    expiration_date: string | null;
    subscription_status?: SubscriptionStatus;
};

const props = defineProps<{
    invoice: Invoice;
    isLocked: boolean;
    customers: Customer[];
    vatExemptionCodes: string[];
    submissions: Submission[];
    requiresSubmission: boolean;
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('invoices.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const displayNumber = computed(
    () => props.invoice.number ?? t('invoices.status.draft'),
);

const form = useForm({
    invoice_date: props.invoice.invoice_date,
    paid: props.invoice.paid,
    customer_id: props.invoice.customer_id,
    note: props.invoice.note ?? '',
    language: props.invoice.language,
    type: props.invoice.type,
    rows: props.invoice.rows.map((row) => ({
        id: row.id,
        description: row.description,
        quantity: row.quantity,
        price: row.price,
        vat_rate: row.vat_rate,
        vat_exemption_code: row.vat_exemption_code,
        expiration_date: row.expiration_date,
        subscription_status: row.subscription_status,
    })) as InvoiceRowForm[],
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
        vat_exemption_code: null,
        expiration_date: null,
    });
    selectedProducts.value.push(undefined);
}

async function removeRow(index: number): Promise<void> {
    if (!(await confirmDialog(t('invoices.create.confirmRemoveRow')))) {
        return;
    }

    form.rows.splice(index, 1);
    selectedProducts.value.splice(index, 1);
}

function toggleSubscription(
    row: InvoiceRowForm,
    checked: boolean | 'indeterminate',
): void {
    row.expiration_date = checked === true ? (row.expiration_date ?? '') : null;
}

function toggleSubscriptionActive(
    row: InvoiceRowForm,
    checked: boolean | 'indeterminate',
): void {
    row.subscription_status = checked === true ? 'active' : 'cancelled';
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

    if (product.duration !== null) {
        form.rows[index].expiration_date = addDurationToDate(
            form.invoice_date,
            product.duration,
        );
    }
}

function appendNote(note: PickedNote): void {
    form.note = form.note ? `${form.note}\n${note.content}` : note.content;
}

const total = computed(() =>
    form.rows.reduce((sum, row) => {
        const lineTotal = row.price * row.quantity;

        return sum + lineTotal + (lineTotal * row.vat_rate) / 100;
    }, 0),
);

function submit(): void {
    form.rows.forEach((row) => {
        row.vat_exemption_code =
            row.vat_rate === 0 ? row.vat_exemption_code : null;
        row.expiration_date ||= null;
    });
    form.put(InvoiceController.update(props.invoice.id).url);
}

const issueForm = useForm({});

async function onIssue(): Promise<void> {
    if (await confirmDialog(t('invoices.edit.confirmIssue'))) {
        issueForm.post(
            InvoiceSubmissionController.issue(props.invoice.id).url,
            {
                preserveScroll: true,
            },
        );
    }
}

async function onDelete(): Promise<void> {
    if (await confirmDialog(t('invoices.edit.confirmDelete'))) {
        router.delete(InvoiceController.destroy(props.invoice.id).url);
    }
}

const customerEmail = computed(
    () =>
        props.customers.find(
            (customer) => customer.id === props.invoice.customer_id,
        )?.email ?? null,
);

const lastSent = computed(() => {
    if (!props.invoice.sent_at) {
        return null;
    }

    return t('sendEmailDialog.lastSent', {
        date: new Date(props.invoice.sent_at).toLocaleString(),
        email: props.invoice.sent_to,
    });
});
</script>

<template>
    <Head :title="t('invoices.edit.title')" />

    <div class="flex max-w-5xl flex-col space-y-6">
        <Heading
            :title="t('invoices.edit.title')"
            :description="
                t('invoices.edit.description', { number: displayNumber })
            "
        />

        <div class="flex items-center gap-1">
            <Badge :variant="invoiceStatusVariant(invoice.status)">
                {{ t(`invoices.status.${invoice.status}`) }}
            </Badge>
            <Button
                as-child
                variant="ghost"
                size="icon-sm"
                :title="t('invoices.index.preview')"
            >
                <a
                    :href="InvoiceController.preview(invoice.id).url"
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
                <a :href="InvoiceController.pdf(invoice.id).url">
                    <FileText />
                </a>
            </Button>
            <Button
                v-if="invoice.status === 'draft'"
                type="button"
                size="sm"
                class="ml-2"
                :disabled="issueForm.processing"
                @click="onIssue"
            >
                <Send />
                {{
                    t(
                        requiresSubmission
                            ? 'invoices.edit.issueAndSubmitButton'
                            : 'invoices.edit.issueButton',
                    )
                }}
            </Button>
            <SendEmailDialog
                :send-url="InvoiceController.send(invoice.id).url"
                :default-to="customerEmail"
                :default-subject="
                    t('sendEmailDialog.invoiceDefaultSubject', {
                        number: displayNumber,
                    })
                "
                :default-message="
                    t('sendEmailDialog.invoiceDefaultMessage', {
                        number: displayNumber,
                    })
                "
            />
        </div>

        <InvoiceSubmissionPanel
            :invoice-id="invoice.id"
            :invoice-status="invoice.status"
            :submissions="submissions"
        />

        <p v-if="lastSent" class="text-sm text-muted-foreground">
            {{ lastSent }}
        </p>

        <AlertError
            v-if="Object.keys(issueForm.errors).length"
            :errors="Object.values(issueForm.errors)"
        />

        <p v-if="isLocked" class="text-sm text-muted-foreground">
            {{ t('invoices.edit.lockedNotice') }}
        </p>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-4">
                <div class="grid gap-2">
                    <Label for="invoice_date">{{
                        t('invoices.create.date')
                    }}</Label>
                    <Input
                        id="invoice_date"
                        v-model="form.invoice_date"
                        type="date"
                        :disabled="isLocked"
                    />
                    <InputError :message="form.errors.invoice_date" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="customer_id">{{
                        t('invoices.create.customer')
                    }}</Label>
                    <Select v-model="form.customer_id" :disabled="isLocked">
                        <SelectTrigger id="customer_id" class="w-full">
                            <SelectValue
                                :placeholder="
                                    t('invoices.create.selectCustomer')
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
                        t('invoices.create.language')
                    }}</Label>
                    <Select v-model="form.language" :disabled="isLocked">
                        <SelectTrigger id="language" class="w-full">
                            <SelectValue
                                :placeholder="
                                    t('invoices.create.selectLanguage')
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

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="type">{{ t('invoices.create.type') }}</Label>
                    <Select v-model="form.type" :disabled="isLocked">
                        <SelectTrigger id="type" class="w-full">
                            <SelectValue
                                :placeholder="t('invoices.create.selectType')"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="invoice">
                                {{ t('invoices.type.invoice') }}
                            </SelectItem>
                            <SelectItem value="credit_note">
                                {{ t('invoices.type.credit_note') }}
                            </SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="form.errors.type" />
                </div>

                <div class="flex items-center gap-2 self-end pb-2">
                    <Checkbox id="paid" v-model="form.paid" />
                    <Label for="paid">{{ t('invoices.create.paid') }}</Label>
                </div>
            </div>

            <div class="grid gap-2">
                <div class="flex items-center justify-between">
                    <Label for="note">{{ t('invoices.create.note') }}</Label>
                    <NotePicker @select="appendNote" />
                </div>
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
                        :disabled="isLocked"
                        @click="addRow"
                    >
                        <Plus />
                        {{ t('common.actions.addRow') }}
                    </Button>
                </div>
                <InputError :message="form.errors.rows" />

                <div
                    class="grid grid-cols-[2.5rem_1fr_6rem_8rem_6rem_5rem_8rem_5rem_2.5rem] gap-2 text-sm text-muted-foreground"
                >
                    <span></span>
                    <span>{{ t('invoices.create.rowDescription') }}</span>
                    <span>{{ t('invoices.create.rowQuantity') }}</span>
                    <span>{{ t('invoices.create.rowPrice') }}</span>
                    <span>{{ t('invoices.create.rowVat') }}</span>
                    <span>{{ t('invoices.create.rowIsSubscription') }}</span>
                    <span>{{ t('invoices.create.rowExpirationDate') }}</span>
                    <span>{{
                        t('invoices.create.rowSubscriptionActive')
                    }}</span>
                    <span></span>
                </div>

                <div
                    v-for="(row, i) in form.rows"
                    :key="row.id ?? `new-${i}`"
                    class="grid grid-cols-[2.5rem_1fr_6rem_8rem_6rem_5rem_8rem_5rem_2.5rem] items-start gap-2"
                >
                    <ProductPicker
                        :disabled="isLocked"
                        :selected-label="productLabel(selectedProducts[i])"
                        @select="(product) => applyProduct(i, product)"
                    />
                    <div class="grid gap-1">
                        <Input
                            v-model="row.description"
                            :disabled="isLocked"
                            class="md:text-base"
                            :placeholder="t('invoices.create.rowDescription')"
                        />
                        <InputError
                            :message="form.errors[`rows.${i}.description`]"
                        />
                    </div>
                    <div class="grid gap-1">
                        <Input
                            v-model.number="row.quantity"
                            :disabled="isLocked"
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
                            :disabled="isLocked"
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
                            :disabled="isLocked"
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
                        <VatExemptionSelect
                            v-if="
                                row.vat_rate === 0 && vatExemptionCodes.length
                            "
                            v-model="row.vat_exemption_code"
                            :disabled="isLocked"
                            :codes="vatExemptionCodes"
                        />
                        <InputError
                            :message="
                                form.errors[`rows.${i}.vat_exemption_code`]
                            "
                        />
                    </div>
                    <div class="flex items-center justify-center pt-2">
                        <Checkbox
                            :model-value="row.expiration_date !== null"
                            :disabled="isLocked"
                            :aria-label="t('invoices.create.rowIsSubscription')"
                            @update:model-value="
                                (checked) => toggleSubscription(row, checked)
                            "
                        />
                    </div>
                    <div class="grid gap-1">
                        <template v-if="row.expiration_date !== null">
                            <Input
                                :model-value="row.expiration_date ?? undefined"
                                type="date"
                                :disabled="isLocked"
                                @update:model-value="
                                    (value) =>
                                        (row.expiration_date = value
                                            ? String(value)
                                            : null)
                                "
                            />
                            <InputError
                                :message="
                                    form.errors[`rows.${i}.expiration_date`]
                                "
                            />
                        </template>
                    </div>
                    <div class="flex items-center justify-center pt-2">
                        <Checkbox
                            v-if="row.id && row.expiration_date !== null"
                            :model-value="
                                row.subscription_status !== 'cancelled'
                            "
                            :disabled="isLocked"
                            :aria-label="
                                t('invoices.create.rowSubscriptionActive')
                            "
                            @update:model-value="
                                (checked) =>
                                    toggleSubscriptionActive(row, checked)
                            "
                        />
                    </div>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon"
                        :disabled="isLocked || form.rows.length === 1"
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

        <div v-if="!isLocked" class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                {{ t('invoices.edit.deleteButton') }}
            </Button>
        </div>
    </div>
</template>

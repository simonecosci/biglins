<script setup lang="ts">
import { Head, Link, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CompanyController from '@/actions/App/Http/Controllers/CompanyController';
import EInvoicingIntegrationController from '@/actions/App/Http/Controllers/EInvoicingIntegrationController';
import FiscalDetailsFields from '@/components/FiscalDetailsFields.vue';
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
import { confirmDialog } from '@/lib/confirmDialog';
import { fiscalFieldsFor } from '@/lib/fiscalFields';
import { index } from '@/routes/companies';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
    iso_code: string | null;
};

type Company = {
    id: string;
    name: string;
    vat_number: string | null;
    tax_code: string | null;
    address: string | null;
    zip: string | null;
    city: string | null;
    province: string | null;
    country_id: string | null;
    email: string | null;
    phone: string | null;
    iban: string | null;
    logo: string | null;
    is_default: boolean;
    fiscal_details: Record<string, string> | null;
};

const props = defineProps<{
    company: Company;
    countries: Country[];
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('companies.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    name: props.company.name,
    vat_number: props.company.vat_number ?? '',
    tax_code: props.company.tax_code ?? '',
    address: props.company.address ?? '',
    zip: props.company.zip ?? '',
    city: props.company.city ?? '',
    province: props.company.province ?? '',
    country_id: props.company.country_id ?? '',
    fiscal_details: (props.company.fiscal_details ?? {}) as Record<
        string,
        string
    >,
    email: props.company.email ?? '',
    phone: props.company.phone ?? '',
    iban: props.company.iban ?? '',
    is_default: props.company.is_default,
    logo: null as File | null,
    remove_logo: false,
});

const countryIso = computed(
    () =>
        props.countries.find((c) => c.id === form.country_id)?.iso_code ?? null,
);

const hasFiscalFields = computed(
    () => fiscalFieldsFor(countryIso.value, 'company').length > 0,
);

watch(countryIso, () => {
    form.fiscal_details = {};
});

function onLogoChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.logo = target.files?.[0] ?? null;

    if (form.logo) {
        form.remove_logo = false;
    }
}

/**
 * Laravel/Symfony only parses multipart bodies on POST requests, so a genuine
 * PUT carrying the logo file would arrive with an empty input bag. Spoof the
 * method instead: the wire request is a POST with `_method=put`.
 */
function submit(): void {
    form.transform((data) => ({
        ...data,
        fiscal_details: hasFiscalFields.value ? data.fiscal_details : {},
        _method: 'put',
    })).post(CompanyController.update(props.company.id).url);
}

async function onDelete(): Promise<void> {
    if (await confirmDialog(t('companies.edit.confirmDelete'))) {
        router.delete(CompanyController.destroy(props.company.id).url);
    }
}
</script>

<template>
    <Head :title="t('companies.edit.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('companies.edit.title')"
            :description="
                t('companies.edit.description', { name: company.name })
            "
        />

        <Button as-child variant="outline" class="self-start">
            <Link :href="EInvoicingIntegrationController.edit(company.id).url">
                {{ t('companies.eInvoicing.title') }}
            </Link>
        </Button>

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">{{ t('common.fields.name') }}</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    autofocus
                    :placeholder="t('companies.create.namePlaceholder')"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="vat_number">{{
                    t('companies.create.vatNumber')
                }}</Label>
                <Input
                    id="vat_number"
                    v-model="form.vat_number"
                    :placeholder="t('companies.create.vatNumberPlaceholder')"
                />
                <InputError :message="form.errors.vat_number" />
            </div>

            <div class="grid gap-2">
                <Label for="tax_code">{{
                    t('companies.create.taxCode')
                }}</Label>
                <Input
                    id="tax_code"
                    v-model="form.tax_code"
                    :placeholder="t('companies.create.taxCodePlaceholder')"
                />
                <InputError :message="form.errors.tax_code" />
            </div>

            <div class="grid gap-2">
                <Label for="address">{{ t('common.fields.address') }}</Label>
                <Input
                    id="address"
                    v-model="form.address"
                    :placeholder="t('companies.create.addressPlaceholder')"
                />
                <InputError :message="form.errors.address" />
            </div>

            <div class="grid grid-cols-3 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">{{ t('common.fields.zip') }}</Label>
                    <Input
                        id="zip"
                        v-model="form.zip"
                        :placeholder="t('companies.create.zipPlaceholder')"
                    />
                    <InputError :message="form.errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">{{ t('common.fields.city') }}</Label>
                    <Input
                        id="city"
                        v-model="form.city"
                        :placeholder="t('companies.create.cityPlaceholder')"
                    />
                    <InputError :message="form.errors.city" />
                </div>
                <div class="grid gap-2">
                    <Label for="province">{{
                        t('companies.create.province')
                    }}</Label>
                    <Input
                        id="province"
                        v-model="form.province"
                        :placeholder="t('companies.create.provincePlaceholder')"
                    />
                    <InputError :message="form.errors.province" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="country_id">{{ t('common.fields.country') }}</Label>
                <Select v-model="form.country_id">
                    <SelectTrigger id="country_id" class="w-full">
                        <SelectValue
                            :placeholder="t('common.fields.selectCountry')"
                        />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="country in countries"
                            :key="country.id"
                            :value="country.id"
                        >
                            {{ country.name }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.country_id" />
            </div>

            <FiscalDetailsFields
                v-model="form.fiscal_details"
                :iso-code="countryIso"
                kind="company"
                :errors="form.errors"
            />

            <div class="grid gap-2">
                <Label for="email">{{ t('common.fields.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    v-model="form.email"
                    :placeholder="t('companies.create.emailPlaceholder')"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">{{ t('common.fields.phone') }}</Label>
                    <Input
                        id="phone"
                        v-model="form.phone"
                        :placeholder="t('companies.create.phonePlaceholder')"
                    />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="iban">{{ t('companies.create.iban') }}</Label>
                    <Input
                        id="iban"
                        v-model="form.iban"
                        :placeholder="t('companies.create.ibanPlaceholder')"
                    />
                    <InputError :message="form.errors.iban" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="logo">{{ t('companies.create.logo') }}</Label>
                <img
                    v-if="company.logo && !form.remove_logo"
                    :src="CompanyController.logo(company.id).url"
                    :alt="t('companies.edit.currentLogoAlt')"
                    class="h-16 w-auto rounded border object-contain p-1"
                />
                <input
                    id="logo"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-xs file:mr-3 file:rounded-sm file:border-0 file:bg-transparent file:text-sm file:font-medium dark:bg-input/30"
                    @change="onLogoChange"
                />
                <InputError :message="form.errors.logo" />
                <div v-if="company.logo" class="flex items-center gap-2">
                    <Checkbox id="remove_logo" v-model="form.remove_logo" />
                    <Label for="remove_logo">{{
                        t('companies.edit.removeLogo')
                    }}</Label>
                </div>
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="is_default" v-model="form.is_default" />
                <Label for="is_default">{{
                    t('companies.create.defaultCompany')
                }}</Label>
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

        <div class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                {{ t('companies.edit.deleteButton') }}
            </Button>
        </div>
    </div>
</template>

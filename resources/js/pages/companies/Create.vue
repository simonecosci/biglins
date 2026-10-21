<script setup lang="ts">
import { Head, Link, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import CompanyController from '@/actions/App/Http/Controllers/CompanyController';
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
import { fiscalFieldsFor } from '@/lib/fiscalFields';
import { index } from '@/routes/companies';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
    iso_code: string | null;
};

const props = defineProps<{
    countries: Country[];
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('companies.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    name: '',
    vat_number: '',
    tax_code: '',
    address: '',
    zip: '',
    city: '',
    province: '',
    country_id: '',
    fiscal_details: {} as Record<string, string>,
    email: '',
    phone: '',
    iban: '',
    is_default: false,
    logo: null as File | null,
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
}

function submit(): void {
    form.transform((data) => ({
        ...data,
        fiscal_details: hasFiscalFields.value ? data.fiscal_details : {},
    })).post(CompanyController.store().url);
}
</script>

<template>
    <Head :title="t('companies.create.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('companies.create.title')"
            :description="t('companies.create.description')"
        />

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
                <input
                    id="logo"
                    type="file"
                    accept="image/png,image/jpeg,image/webp"
                    class="w-full rounded-md border border-input bg-transparent px-3 py-1.5 text-sm shadow-xs file:mr-3 file:rounded-sm file:border-0 file:bg-transparent file:text-sm file:font-medium dark:bg-input/30"
                    @change="onLogoChange"
                />
                <InputError :message="form.errors.logo" />
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
    </div>
</template>

<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CustomerController from '@/actions/App/Http/Controllers/CustomerController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
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
import { index } from '@/routes/customers';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

type Customer = {
    id: string;
    name: string;
    address: string | null;
    zip: string | null;
    city: string | null;
    country_id: string | null;
    state: string | null;
    email: string | null;
    web: string | null;
    phone: string | null;
    nif: string | null;
};

const props = defineProps<{
    customer: Customer;
    countries: Country[];
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('customers.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

function onDelete(): void {
    if (confirm(t('customers.edit.confirmDelete'))) {
        router.delete(CustomerController.destroy(props.customer.id).url);
    }
}
</script>

<template>
    <Head :title="t('customers.edit.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('customers.edit.title')"
            :description="t('customers.edit.description', { name: customer.name })"
        />

        <Form
            v-bind="CustomerController.update.form(customer.id)"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ t('common.fields.name') }}</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="customer.name"
                    required
                    autofocus
                    :placeholder="t('customers.create.namePlaceholder')"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="address">{{ t('common.fields.address') }}</Label>
                <Input
                    id="address"
                    name="address"
                    :default-value="customer.address ?? undefined"
                    :placeholder="t('customers.create.addressPlaceholder')"
                />
                <InputError :message="errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">{{ t('common.fields.zip') }}</Label>
                    <Input
                        id="zip"
                        name="zip"
                        :default-value="customer.zip ?? undefined"
                        :placeholder="t('customers.create.zipPlaceholder')"
                    />
                    <InputError :message="errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">{{ t('common.fields.city') }}</Label>
                    <Input
                        id="city"
                        name="city"
                        :default-value="customer.city ?? undefined"
                        :placeholder="t('customers.create.cityPlaceholder')"
                    />
                    <InputError :message="errors.city" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="country_id">{{ t('common.fields.country') }}</Label>
                    <Select
                        name="country_id"
                        :default-value="customer.country_id ?? undefined"
                    >
                        <SelectTrigger id="country_id" class="w-full">
                            <SelectValue :placeholder="t('common.fields.selectCountry')" />
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
                    <InputError :message="errors.country_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="state">{{ t('customers.create.stateProvince') }}</Label>
                    <Input
                        id="state"
                        name="state"
                        :default-value="customer.state ?? undefined"
                        :placeholder="t('customers.create.stateProvincePlaceholder')"
                    />
                    <InputError :message="errors.state" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="email">{{ t('common.fields.email') }}</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    :default-value="customer.email ?? undefined"
                    :placeholder="t('customers.create.emailPlaceholder')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="web">{{ t('customers.create.website') }}</Label>
                <Input
                    id="web"
                    name="web"
                    :default-value="customer.web ?? undefined"
                    placeholder="https://example.com"
                />
                <InputError :message="errors.web" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">{{ t('common.fields.phone') }}</Label>
                    <Input
                        id="phone"
                        name="phone"
                        :default-value="customer.phone ?? undefined"
                        :placeholder="t('customers.create.phonePlaceholder')"
                    />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="nif">{{ t('customers.create.taxId') }}</Label>
                    <Input
                        id="nif"
                        name="nif"
                        :default-value="customer.nif ?? undefined"
                        :placeholder="t('customers.create.taxIdPlaceholder')"
                    />
                    <InputError :message="errors.nif" />
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="processing" type="submit">{{ t('common.actions.save') }}</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    {{ t('common.actions.cancel') }}
                </Link>
            </div>
        </Form>

        <div class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                {{ t('customers.edit.deleteButton') }}
            </Button>
        </div>
    </div>
</template>

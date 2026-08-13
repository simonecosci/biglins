<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
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

defineProps<{
    countries: Country[];
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('customers.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('customers.create.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('customers.create.title')"
            :description="t('customers.create.description')"
        />

        <Form
            v-bind="CustomerController.store.form()"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ t('common.fields.name') }}</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autofocus
                    :placeholder="t('customers.create.namePlaceholder')"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="address">{{ t('common.fields.address') }}</Label>
                <Input id="address" name="address" :placeholder="t('customers.create.addressPlaceholder')" />
                <InputError :message="errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">{{ t('common.fields.zip') }}</Label>
                    <Input id="zip" name="zip" :placeholder="t('customers.create.zipPlaceholder')" />
                    <InputError :message="errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">{{ t('common.fields.city') }}</Label>
                    <Input id="city" name="city" :placeholder="t('customers.create.cityPlaceholder')" />
                    <InputError :message="errors.city" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="country_id">{{ t('common.fields.country') }}</Label>
                    <Select name="country_id">
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
                    :placeholder="t('customers.create.emailPlaceholder')"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="web">{{ t('customers.create.website') }}</Label>
                <Input id="web" name="web" placeholder="https://example.com" />
                <InputError :message="errors.web" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">{{ t('common.fields.phone') }}</Label>
                    <Input id="phone" name="phone" :placeholder="t('customers.create.phonePlaceholder')" />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="nif">{{ t('customers.create.taxId') }}</Label>
                    <Input
                        id="nif"
                        name="nif"
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
    </div>
</template>

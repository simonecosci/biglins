<script setup lang="ts">
import { Head, Link, useForm } from '@inertiajs/vue3';
import CompanyController from '@/actions/App/Http/Controllers/CompanyController';
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
import { index } from '@/routes/companies';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

defineProps<{
    countries: Country[];
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Companies', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});

const form = useForm({
    name: '',
    tax_id: '',
    address: '',
    zip: '',
    city: '',
    country_id: '',
    email: '',
    phone: '',
    iban: '',
    is_default: false,
    logo: null as File | null,
});

function onLogoChange(event: Event): void {
    const target = event.target as HTMLInputElement;
    form.logo = target.files?.[0] ?? null;
}

function submit(): void {
    form.post(CompanyController.store().url);
}
</script>

<template>
    <Head title="New company" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            title="New company"
            description="Add an issuing company to the registry"
        />

        <form class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    v-model="form.name"
                    required
                    autofocus
                    placeholder="Company name"
                />
                <InputError :message="form.errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="tax_id">Tax ID</Label>
                <Input
                    id="tax_id"
                    v-model="form.tax_id"
                    placeholder="Tax identification number"
                />
                <InputError :message="form.errors.tax_id" />
            </div>

            <div class="grid gap-2">
                <Label for="address">Address</Label>
                <Input
                    id="address"
                    v-model="form.address"
                    placeholder="Address"
                />
                <InputError :message="form.errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">ZIP</Label>
                    <Input id="zip" v-model="form.zip" placeholder="ZIP code" />
                    <InputError :message="form.errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">City</Label>
                    <Input id="city" v-model="form.city" placeholder="City" />
                    <InputError :message="form.errors.city" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="country_id">Country</Label>
                <Select v-model="form.country_id">
                    <SelectTrigger id="country_id" class="w-full">
                        <SelectValue placeholder="Select a country" />
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

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    v-model="form.email"
                    placeholder="Email address"
                />
                <InputError :message="form.errors.email" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input
                        id="phone"
                        v-model="form.phone"
                        placeholder="Phone number"
                    />
                    <InputError :message="form.errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="iban">IBAN</Label>
                    <Input
                        id="iban"
                        v-model="form.iban"
                        placeholder="Bank account IBAN"
                    />
                    <InputError :message="form.errors.iban" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="logo">Logo</Label>
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
                <Label for="is_default">Default company for new invoices</Label>
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

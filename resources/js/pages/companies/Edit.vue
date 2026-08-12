<script setup lang="ts">
import { Head, Link, router, useForm } from '@inertiajs/vue3';
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

type Company = {
    id: string;
    name: string;
    tax_id: string | null;
    address: string | null;
    zip: string | null;
    city: string | null;
    country_id: string | null;
    email: string | null;
    phone: string | null;
    iban: string | null;
    logo: string | null;
    is_default: boolean;
};

const props = defineProps<{
    company: Company;
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
    name: props.company.name,
    tax_id: props.company.tax_id ?? '',
    address: props.company.address ?? '',
    zip: props.company.zip ?? '',
    city: props.company.city ?? '',
    country_id: props.company.country_id ?? '',
    email: props.company.email ?? '',
    phone: props.company.phone ?? '',
    iban: props.company.iban ?? '',
    is_default: props.company.is_default,
    logo: null as File | null,
    remove_logo: false,
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
    form
        .transform((data) => ({ ...data, _method: 'put' }))
        .post(CompanyController.update(props.company.id).url);
}

function onDelete(): void {
    if (confirm('Delete this company? This cannot be undone.')) {
        router.delete(CompanyController.destroy(props.company.id).url);
    }
}
</script>

<template>
    <Head title="Edit company" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading title="Edit company" :description="`Update ${company.name}`" />

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
                <img
                    v-if="company.logo && !form.remove_logo"
                    :src="`/${company.logo}`"
                    alt="Current logo"
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
                    <Label for="remove_logo">Remove current logo</Label>
                </div>
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

        <div class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                Delete company
            </Button>
        </div>
    </div>
</template>

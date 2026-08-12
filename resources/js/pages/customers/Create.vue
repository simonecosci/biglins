<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
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

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Customers', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="New customer" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            title="New customer"
            description="Add a customer to the registry"
        />

        <Form
            v-bind="CustomerController.store.form()"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autofocus
                    placeholder="Customer name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="grid gap-2">
                <Label for="address">Address</Label>
                <Input id="address" name="address" placeholder="Address" />
                <InputError :message="errors.address" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="zip">ZIP</Label>
                    <Input id="zip" name="zip" placeholder="ZIP code" />
                    <InputError :message="errors.zip" />
                </div>
                <div class="grid gap-2">
                    <Label for="city">City</Label>
                    <Input id="city" name="city" placeholder="City" />
                    <InputError :message="errors.city" />
                </div>
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="country_id">Country</Label>
                    <Select name="country_id">
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
                    <InputError :message="errors.country_id" />
                </div>
                <div class="grid gap-2">
                    <Label for="state">State / Province</Label>
                    <Input
                        id="state"
                        name="state"
                        placeholder="State or province"
                    />
                    <InputError :message="errors.state" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="email">Email</Label>
                <Input
                    id="email"
                    type="email"
                    name="email"
                    placeholder="Email address"
                />
                <InputError :message="errors.email" />
            </div>

            <div class="grid gap-2">
                <Label for="web">Website</Label>
                <Input id="web" name="web" placeholder="https://example.com" />
                <InputError :message="errors.web" />
            </div>

            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="phone">Phone</Label>
                    <Input id="phone" name="phone" placeholder="Phone number" />
                    <InputError :message="errors.phone" />
                </div>
                <div class="grid gap-2">
                    <Label for="nif">NIF</Label>
                    <Input
                        id="nif"
                        name="nif"
                        placeholder="Tax identification number"
                    />
                    <InputError :message="errors.nif" />
                </div>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="processing" type="submit">Save</Button>
                <Link
                    :href="index()"
                    class="text-sm text-muted-foreground hover:underline"
                >
                    Cancel
                </Link>
            </div>
        </Form>
    </div>
</template>

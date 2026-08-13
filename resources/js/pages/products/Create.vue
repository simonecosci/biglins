<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
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
import { index } from '@/routes/products';
import type { BreadcrumbItem } from '@/types';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Products', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="New product" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            title="New product"
            description="Add a product or service to the catalog"
        />

        <Form
            v-bind="ProductController.store.form()"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="code">Code</Label>
                    <Input
                        id="code"
                        name="code"
                        autofocus
                        placeholder="Optional code"
                    />
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="type">Type</Label>
                    <Select name="type" default-value="product">
                        <SelectTrigger id="type" class="w-full">
                            <SelectValue placeholder="Select a type" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="product">Product</SelectItem>
                            <SelectItem value="service">Service</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.type" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">Description</Label>
                <Input
                    id="description"
                    name="description"
                    required
                    placeholder="Description"
                />
                <InputError :message="errors.description" />
            </div>

            <div class="grid gap-2">
                <Label for="price">Price</Label>
                <Input
                    id="price"
                    name="price"
                    type="number"
                    step="0.01"
                    min="0"
                    required
                    placeholder="Price"
                />
                <InputError :message="errors.price" />
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

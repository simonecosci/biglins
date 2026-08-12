<script setup lang="ts">
import { Form, Head, Link } from '@inertiajs/vue3';
import CountryController from '@/actions/App/Http/Controllers/CountryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/countries';
import type { BreadcrumbItem } from '@/types';

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Countries', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="New country" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            title="New country"
            description="Add a country to the list available to customers"
        />

        <Form
            v-bind="CountryController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    required
                    autofocus
                    placeholder="Country name"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="flex items-center gap-4">
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

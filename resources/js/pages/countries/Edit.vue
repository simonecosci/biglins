<script setup lang="ts">
import { Form, Head, Link, router } from '@inertiajs/vue3';
import CountryController from '@/actions/App/Http/Controllers/CountryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/countries';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

const props = defineProps<{
    country: Country;
}>();

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Countries', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});

function onDelete(): void {
    if (confirm('Delete this country? This cannot be undone.')) {
        router.delete(CountryController.destroy(props.country.id).url);
    }
}
</script>

<template>
    <Head title="Edit country" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading title="Edit country" :description="`Update ${country.name}`" />

        <Form
            v-bind="CountryController.update.form(country.id)"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">Name</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="country.name"
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

        <div class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                Delete country
            </Button>
        </div>
    </div>
</template>

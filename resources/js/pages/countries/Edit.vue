<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CountryController from '@/actions/App/Http/Controllers/CountryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { confirmDialog } from '@/lib/confirmDialog';
import { index } from '@/routes/countries';
import type { BreadcrumbItem } from '@/types';

type Country = {
    id: string;
    name: string;
};

const props = defineProps<{
    country: Country;
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('countries.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

async function onDelete(): Promise<void> {
    if (await confirmDialog(t('countries.edit.confirmDelete'))) {
        router.delete(CountryController.destroy(props.country.id).url);
    }
}
</script>

<template>
    <Head :title="t('countries.edit.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('countries.edit.title')"
            :description="
                t('countries.edit.description', { name: country.name })
            "
        />

        <Form
            v-bind="CountryController.update.form(country.id)"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ t('common.fields.name') }}</Label>
                <Input
                    id="name"
                    name="name"
                    :default-value="country.name"
                    required
                    autofocus
                    :placeholder="t('countries.create.namePlaceholder')"
                />
                <InputError :message="errors.name" />
            </div>

            <div class="flex items-center gap-4">
                <Button :disabled="processing" type="submit">{{
                    t('common.actions.save')
                }}</Button>
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
                {{ t('countries.edit.deleteButton') }}
            </Button>
        </div>
    </div>
</template>

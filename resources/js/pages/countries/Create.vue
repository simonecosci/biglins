<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import CountryController from '@/actions/App/Http/Controllers/CountryController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/countries';
import type { BreadcrumbItem } from '@/types';

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('countries.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('countries.create.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('countries.create.title')"
            :description="t('countries.create.description')"
        />

        <Form
            v-bind="CountryController.store.form()"
            class="space-y-6"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="name">{{ t('common.fields.name') }}</Label>
                <Input
                    id="name"
                    name="name"
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
    </div>
</template>

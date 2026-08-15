<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import NoteController from '@/actions/App/Http/Controllers/NoteController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { index } from '@/routes/notes';
import type { BreadcrumbItem } from '@/types';

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('notes.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('notes.create.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('notes.create.title')"
            :description="t('notes.create.description')"
        />

        <Form
            v-bind="NoteController.store.form()"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="title">{{ t('notes.create.titleLabel') }}</Label>
                <Input
                    id="title"
                    name="title"
                    autofocus
                    required
                    :placeholder="t('notes.create.titlePlaceholder')"
                />
                <InputError :message="errors.title" />
            </div>

            <div class="grid gap-2">
                <Label for="content">{{
                    t('notes.create.contentLabel')
                }}</Label>
                <textarea
                    id="content"
                    name="content"
                    rows="5"
                    required
                    class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    :placeholder="t('notes.create.contentPlaceholder')"
                />
                <InputError :message="errors.content" />
            </div>

            <div class="flex items-center gap-4 pt-2">
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

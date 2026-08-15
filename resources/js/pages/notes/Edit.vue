<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import NoteController from '@/actions/App/Http/Controllers/NoteController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { confirmDialog } from '@/lib/confirmDialog';
import { index } from '@/routes/notes';
import type { BreadcrumbItem } from '@/types';

type Note = {
    id: string;
    title: string;
    content: string;
};

const props = defineProps<{
    note: Note;
}>();

const content = ref(props.note.content);

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('notes.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

async function onDelete(): Promise<void> {
    if (await confirmDialog(t('notes.edit.confirmDelete'))) {
        router.delete(NoteController.destroy(props.note.id).url);
    }
}
</script>

<template>
    <Head :title="t('notes.edit.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('notes.edit.title')"
            :description="t('notes.edit.description', { name: note.title })"
        />

        <Form
            v-bind="NoteController.update.form(note.id)"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid gap-2">
                <Label for="title">{{ t('notes.create.titleLabel') }}</Label>
                <Input
                    id="title"
                    name="title"
                    :default-value="note.title"
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
                    v-model="content"
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

        <div class="border-t pt-6">
            <Button variant="destructive" type="button" @click="onDelete">
                {{ t('notes.edit.deleteButton') }}
            </Button>
        </div>
    </div>
</template>

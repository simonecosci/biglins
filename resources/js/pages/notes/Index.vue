<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/notes';
import type { BreadcrumbItem } from '@/types';

type Note = {
    id: string;
    title: string;
    content: string;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    notes: {
        data: Note[];
        links: PaginationLink[];
    };
    filters: {
        search: string;
    };
}>();

const search = ref(props.filters.search);

const { t } = useI18n();

function onSearch(): void {
    router.get(
        index().url,
        { search: search.value },
        { preserveState: true, replace: true },
    );
}

setLayoutProps({
    breadcrumbs: [
        { title: t('notes.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('notes.index.title')" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                :title="t('notes.index.title')"
                :description="t('notes.index.description')"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    {{ t('notes.index.newButton') }}
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input
                v-model="search"
                :placeholder="t('notes.index.searchPlaceholder')"
            />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ t('notes.index.columns.title') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('notes.index.columns.content') }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="note in notes.data"
                        :key="note.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ note.title }}</td>
                        <td class="max-w-md truncate px-4 py-2">
                            {{ note.content }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('common.actions.edit')"
                            >
                                <Link :href="edit(note.id)">
                                    <Pencil />
                                </Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="notes.data.length === 0">
                        <td
                            colspan="3"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            {{ t('notes.index.empty') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="notes.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in notes.links"
                :key="i"
                :href="link.url ?? ''"
                :class="[
                    'rounded-md px-3 py-1 text-sm',
                    link.active
                        ? 'bg-primary text-primary-foreground'
                        : 'hover:bg-accent',
                    !link.url && 'pointer-events-none opacity-50',
                ]"
            >
                <span v-html="link.label" />
            </Link>
        </div>
    </div>
</template>

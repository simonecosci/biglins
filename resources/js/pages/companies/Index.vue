<script setup lang="ts">
import { Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import { useI18n } from 'vue-i18n';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/companies';
import type { BreadcrumbItem } from '@/types';

type Company = {
    id: string;
    name: string;
    city: string | null;
    email: string | null;
    is_default: boolean;
    country: { id: string; name: string } | null;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    companies: {
        data: Company[];
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
        { title: t('companies.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('companies.index.title')" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                :title="t('companies.index.title')"
                :description="t('companies.index.description')"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    {{ t('companies.index.newButton') }}
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input
                v-model="search"
                :placeholder="t('companies.index.searchPlaceholder')"
            />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">
                            {{ t('companies.index.columns.name') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('companies.index.columns.city') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('companies.index.columns.country') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('companies.index.columns.email') }}
                        </th>
                        <th class="px-4 py-2 font-medium">
                            {{ t('companies.index.columns.default') }}
                        </th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="company in companies.data"
                        :key="company.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ company.name }}</td>
                        <td class="px-4 py-2">{{ company.city ?? '—' }}</td>
                        <td class="px-4 py-2">
                            {{ company.country?.name ?? '—' }}
                        </td>
                        <td class="px-4 py-2">{{ company.email ?? '—' }}</td>
                        <td class="px-4 py-2">
                            {{
                                company.is_default
                                    ? t('companies.index.yes')
                                    : '—'
                            }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                :title="t('common.actions.edit')"
                            >
                                <Link :href="edit(company.id)">
                                    <Pencil />
                                </Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="companies.data.length === 0">
                        <td
                            colspan="6"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            {{ t('companies.index.empty') }}
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="companies.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in companies.links"
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

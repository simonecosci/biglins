<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
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

function onSearch(): void {
    router.get(
        index().url,
        { search: search.value },
        { preserveState: true, replace: true },
    );
}

defineOptions({
    layout: () => ({
        breadcrumbs: [
            { title: 'Companies', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="Companies" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                title="Companies"
                description="Manage the companies that can issue invoices"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    New company
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input v-model="search" placeholder="Search by name..." />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Name</th>
                        <th class="px-4 py-2 font-medium">City</th>
                        <th class="px-4 py-2 font-medium">Country</th>
                        <th class="px-4 py-2 font-medium">Email</th>
                        <th class="px-4 py-2 font-medium">Default</th>
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
                            {{ company.is_default ? 'Yes' : '—' }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                title="Edit"
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
                            No companies found.
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

<script setup lang="ts">
import { Head, Link, router } from '@inertiajs/vue3';
import { Pencil, Plus } from '@lucide/vue';
import { ref } from 'vue';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { create, edit, index } from '@/routes/products';
import type { BreadcrumbItem } from '@/types';

type Product = {
    id: string;
    code: string | null;
    type: 'product' | 'service';
    description: string;
    price: number;
};

type PaginationLink = {
    url: string | null;
    label: string;
    active: boolean;
};

const props = defineProps<{
    products: {
        data: Product[];
        links: PaginationLink[];
    };
    filters: {
        search: string;
    };
}>();

const search = ref(props.filters.search);

const typeLabels: Record<Product['type'], string> = {
    product: 'Product',
    service: 'Service',
};

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
            { title: 'Products', href: index() },
        ] satisfies BreadcrumbItem[],
    }),
});
</script>

<template>
    <Head title="Products" />

    <div class="flex flex-col space-y-6">
        <div class="flex items-center justify-between">
            <Heading
                title="Products"
                description="Manage your product and service catalog"
            />
            <Button as-child>
                <Link :href="create()">
                    <Plus />
                    New product
                </Link>
            </Button>
        </div>

        <form class="max-w-sm" @submit.prevent="onSearch">
            <Input
                v-model="search"
                placeholder="Search by code or description..."
            />
        </form>

        <div class="overflow-hidden rounded-lg border">
            <table class="w-full text-sm">
                <thead class="bg-muted/50 text-left">
                    <tr>
                        <th class="px-4 py-2 font-medium">Code</th>
                        <th class="px-4 py-2 font-medium">Type</th>
                        <th class="px-4 py-2 font-medium">Description</th>
                        <th class="px-4 py-2 font-medium">Price</th>
                        <th class="px-4 py-2"></th>
                    </tr>
                </thead>
                <tbody>
                    <tr
                        v-for="product in products.data"
                        :key="product.id"
                        class="border-t"
                    >
                        <td class="px-4 py-2">{{ product.code ?? '—' }}</td>
                        <td class="px-4 py-2">
                            {{ typeLabels[product.type] }}
                        </td>
                        <td class="px-4 py-2">{{ product.description }}</td>
                        <td class="px-4 py-2">
                            {{ product.price.toFixed(2) }}
                        </td>
                        <td class="px-4 py-2 text-right">
                            <Button
                                as-child
                                variant="ghost"
                                size="icon-sm"
                                title="Edit"
                            >
                                <Link :href="edit(product.id)">
                                    <Pencil />
                                </Link>
                            </Button>
                        </td>
                    </tr>
                    <tr v-if="products.data.length === 0">
                        <td
                            colspan="5"
                            class="px-4 py-6 text-center text-muted-foreground"
                        >
                            No products found.
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div v-if="products.links.length > 3" class="flex flex-wrap gap-1">
            <Link
                v-for="(link, i) in products.links"
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

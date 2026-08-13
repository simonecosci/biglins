<script setup lang="ts">
import { Search } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { index } from '@/routes/products';

export type PickedProduct = {
    id: string;
    code: string | null;
    description: string;
    price: number;
};

type ProductResult = PickedProduct & {
    type: 'product' | 'service';
};

type PaginatedProducts = {
    data: ProductResult[];
    current_page: number;
    last_page: number;
};

defineProps<{
    selectedLabel?: string | null;
}>();

const emit = defineEmits<{
    select: [product: PickedProduct];
}>();

const open = ref(false);
const search = ref('');
const loading = ref(false);
const products = ref<ProductResult[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const searchInput = ref<InstanceType<typeof Input> | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

async function loadProducts(page = 1): Promise<void> {
    loading.value = true;

    try {
        const url = new URL(index().url, window.location.origin);

        if (search.value !== '') {
            url.searchParams.set('search', search.value);
        }

        url.searchParams.set('page', String(page));

        const response = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
        });

        const data = (await response.json()) as PaginatedProducts;

        products.value = data.data;
        currentPage.value = data.current_page;
        lastPage.value = data.last_page;
    } finally {
        loading.value = false;
    }
}

watch(search, () => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    debounceTimer = setTimeout(() => {
        void loadProducts(1);
    }, 300);
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    search.value = '';
    void loadProducts(1);
    void nextTick(() => searchInput.value?.$el?.focus());
});

function choose(product: ProductResult): void {
    emit('select', {
        id: product.id,
        code: product.code,
        description: product.description,
        price: product.price,
    });
    open.value = false;
}
</script>

<template>
    <Dialog v-model:open="open">
        <TooltipProvider :delay-duration="0">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        @click="open = true"
                    >
                        <span class="sr-only">{{
                            selectedLabel || 'From catalog'
                        }}</span>
                        <Search class="size-4 text-muted-foreground" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    <p>{{ selectedLabel || 'From catalog' }}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>Choose a product</DialogTitle>
            </DialogHeader>

            <Input
                ref="searchInput"
                v-model="search"
                placeholder="Search by code or description..."
            />

            <div class="max-h-80 overflow-y-auto rounded-md border">
                <div
                    v-if="loading"
                    class="flex items-center justify-center py-8"
                >
                    <Spinner />
                </div>
                <ul v-else-if="products.length > 0" class="divide-y">
                    <li v-for="product in products" :key="product.id">
                        <button
                            type="button"
                            class="flex w-full items-center justify-between gap-4 px-3 py-2 text-left text-sm hover:bg-accent"
                            @click="choose(product)"
                        >
                            <span class="truncate">
                                <span
                                    v-if="product.code"
                                    class="text-muted-foreground"
                                    >{{ product.code }} —</span
                                >
                                {{ product.description }}
                            </span>
                            <span class="shrink-0 text-muted-foreground">
                                {{ product.price.toFixed(2) }}
                            </span>
                        </button>
                    </li>
                </ul>
                <p
                    v-else
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    No products found.
                </p>
            </div>

            <div
                v-if="lastPage > 1"
                class="flex items-center justify-between text-sm text-muted-foreground"
            >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="currentPage <= 1 || loading"
                    @click="loadProducts(currentPage - 1)"
                >
                    Previous
                </Button>
                <span>Page {{ currentPage }} of {{ lastPage }}</span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="currentPage >= lastPage || loading"
                    @click="loadProducts(currentPage + 1)"
                >
                    Next
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>

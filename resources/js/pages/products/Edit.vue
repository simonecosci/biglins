<script setup lang="ts">
import { Form, Head, Link, router, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import ProductController from '@/actions/App/Http/Controllers/ProductController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/products';
import type { BreadcrumbItem } from '@/types';

type Product = {
    id: string;
    code: string | null;
    type: 'product' | 'service';
    description: string;
    price: number;
};

const props = defineProps<{
    product: Product;
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('products.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

function onDelete(): void {
    if (confirm(t('products.edit.confirmDelete'))) {
        router.delete(ProductController.destroy(props.product.id).url);
    }
}
</script>

<template>
    <Head :title="t('products.edit.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('products.edit.title')"
            :description="t('products.edit.description', { name: product.description })"
        />

        <Form
            v-bind="ProductController.update.form(product.id)"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="code">{{ t('products.create.code') }}</Label>
                    <Input
                        id="code"
                        name="code"
                        :default-value="product.code ?? undefined"
                        autofocus
                        :placeholder="t('products.create.codePlaceholder')"
                    />
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="type">{{ t('products.create.type') }}</Label>
                    <Select name="type" :default-value="product.type">
                        <SelectTrigger id="type" class="w-full">
                            <SelectValue :placeholder="t('products.create.selectType')" />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="product">{{ t('products.type.product') }}</SelectItem>
                            <SelectItem value="service">{{ t('products.type.service') }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.type" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">{{ t('products.create.descriptionLabel') }}</Label>
                <Input
                    id="description"
                    name="description"
                    :default-value="product.description"
                    required
                    :placeholder="t('products.create.descriptionPlaceholder')"
                />
                <InputError :message="errors.description" />
            </div>

            <div class="grid gap-2">
                <Label for="price">{{ t('products.create.price') }}</Label>
                <Input
                    id="price"
                    name="price"
                    type="number"
                    step="0.01"
                    min="0"
                    :default-value="product.price"
                    required
                    :placeholder="t('products.create.pricePlaceholder')"
                />
                <InputError :message="errors.price" />
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="processing" type="submit">{{ t('common.actions.save') }}</Button>
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
                {{ t('products.edit.deleteButton') }}
            </Button>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Form, Head, Link, setLayoutProps } from '@inertiajs/vue3';
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

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('products.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});
</script>

<template>
    <Head :title="t('products.create.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('products.create.title')"
            :description="t('products.create.description')"
        />

        <Form
            v-bind="ProductController.store.form()"
            class="space-y-4"
            v-slot="{ errors, processing }"
        >
            <div class="grid grid-cols-2 gap-4">
                <div class="grid gap-2">
                    <Label for="code">{{ t('products.create.code') }}</Label>
                    <Input
                        id="code"
                        name="code"
                        autofocus
                        :placeholder="t('products.create.codePlaceholder')"
                    />
                    <InputError :message="errors.code" />
                </div>
                <div class="grid gap-2">
                    <Label for="type">{{ t('products.create.type') }}</Label>
                    <Select name="type" default-value="product">
                        <SelectTrigger id="type" class="w-full">
                            <SelectValue
                                :placeholder="t('products.create.selectType')"
                            />
                        </SelectTrigger>
                        <SelectContent>
                            <SelectItem value="product">{{
                                t('products.type.product')
                            }}</SelectItem>
                            <SelectItem value="service">{{
                                t('products.type.service')
                            }}</SelectItem>
                        </SelectContent>
                    </Select>
                    <InputError :message="errors.type" />
                </div>
            </div>

            <div class="grid gap-2">
                <Label for="description">{{
                    t('products.create.descriptionLabel')
                }}</Label>
                <Input
                    id="description"
                    name="description"
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
                    required
                    :placeholder="t('products.create.pricePlaceholder')"
                />
                <InputError :message="errors.price" />
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

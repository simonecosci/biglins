<script setup lang="ts">
import { Head, router } from '@inertiajs/vue3';
import type { AcceptableValue } from 'reka-ui';
import { useI18n } from 'vue-i18n';
import LanguageController from '@/actions/App/Http/Controllers/Settings/LanguageController';
import Heading from '@/components/Heading.vue';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { edit } from '@/routes/language';
import type { BreadcrumbItem } from '@/types';

const props = defineProps<{
    locale: string;
    locales: string[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [
            { title: 'Language settings', href: edit() },
        ] satisfies BreadcrumbItem[],
    },
});

const { t, locale: activeLocale } = useI18n();

function updateLocale(value: AcceptableValue): void {
    if (typeof value !== 'string') {
        return;
    }

    router.put(
        LanguageController.update.url(),
        { locale: value },
        {
            preserveScroll: true,
            onSuccess: () => {
                activeLocale.value = value;
            },
        },
    );
}
</script>

<template>
    <Head :title="t('settings.language.title')" />

    <h1 class="sr-only">{{ t('settings.language.title') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.language.title')"
            :description="t('settings.language.description')"
        />

        <div class="grid gap-2">
            <Select
                :model-value="props.locale"
                @update:model-value="updateLocale"
            >
                <SelectTrigger id="locale" class="w-full max-w-xs">
                    <SelectValue :placeholder="t('settings.language.label')" />
                </SelectTrigger>
                <SelectContent>
                    <SelectItem
                        v-for="option in props.locales"
                        :key="option"
                        :value="option"
                    >
                        {{ t(`settings.language.options.${option}`) }}
                    </SelectItem>
                </SelectContent>
            </Select>
        </div>
    </div>
</template>

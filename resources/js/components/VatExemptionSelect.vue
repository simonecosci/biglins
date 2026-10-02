<script setup lang="ts">
import { useI18n } from 'vue-i18n';

defineProps<{ codes: string[]; disabled?: boolean }>();

const model = defineModel<string | null>({ required: true });

const { t } = useI18n();
</script>

<template>
    <select
        :value="model ?? ''"
        :disabled="disabled"
        :aria-label="t('vatExemption.label')"
        class="h-9 w-full rounded-md border border-input bg-transparent px-2 text-sm shadow-xs dark:bg-input/30"
        @change="model = ($event.target as HTMLSelectElement).value || null"
    >
        <option value="">{{ t('vatExemption.placeholder') }}</option>
        <option v-for="code in codes" :key="code" :value="code">
            {{ code }}
        </option>
    </select>
</template>

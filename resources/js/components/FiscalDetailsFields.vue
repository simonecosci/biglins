<script setup lang="ts">
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { fiscalFieldsFor } from '@/lib/fiscalFields';

const props = defineProps<{
    isoCode: string | null | undefined;
    kind: 'company' | 'customer';
    errors?: Record<string, string | undefined>;
}>();

const model = defineModel<Record<string, string>>({ required: true });

const { t } = useI18n();

const fields = computed(() => fiscalFieldsFor(props.isoCode, props.kind));

function update(key: string, value: string | number): void {
    model.value = { ...model.value, [key]: String(value) };
}
</script>

<template>
    <fieldset v-if="fields.length" class="space-y-4 rounded-md border p-4">
        <legend class="px-1 text-sm font-medium">
            {{ t('fiscalDetails.title') }}
        </legend>
        <div v-for="field in fields" :key="field.key" class="grid gap-2">
            <Label :for="`fiscal_details_${field.key}`">
                {{ t(`fiscalDetails.fields.${field.key}`) }}
            </Label>
            <select
                v-if="field.type === 'select'"
                :id="`fiscal_details_${field.key}`"
                :name="`fiscal_details[${field.key}]`"
                :value="model[field.key] ?? ''"
                class="h-9 w-full rounded-md border border-input bg-transparent px-3 text-sm shadow-xs dark:bg-input/30"
                @change="
                    update(
                        field.key,
                        ($event.target as HTMLSelectElement).value,
                    )
                "
            >
                <option value="">—</option>
                <option
                    v-for="option in field.options"
                    :key="option"
                    :value="option"
                >
                    {{ option }} —
                    {{ t(`fiscalDetails.options.${field.key}.${option}`) }}
                </option>
            </select>
            <Input
                v-else
                :id="`fiscal_details_${field.key}`"
                :name="`fiscal_details[${field.key}]`"
                :type="field.type"
                :model-value="model[field.key] ?? ''"
                @update:model-value="(value) => update(field.key, value)"
            />
            <InputError :message="errors?.[`fiscal_details.${field.key}`]" />
        </div>
    </fieldset>
</template>

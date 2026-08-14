<script setup lang="ts">
import { useHttp } from '@inertiajs/vue3';
import { ref, useId } from 'vue';
import { useI18n } from 'vue-i18n';
import EstimationController from '@/actions/App/Http/Controllers/EstimationController';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';

const props = defineProps<{
    modelValue: string;
    label: string;
    placeholder?: string;
    error?: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const { t } = useI18n();

const fieldId = useId();
const mode = ref<'edit' | 'preview'>('edit');
const previewHtml = ref('');
const previewError = ref(false);
const http = useHttp<{ body: string }, { html: string }>({ body: '' });

let requestGeneration = 0;

async function showPreview(): Promise<void> {
    const generation = ++requestGeneration;
    http.body = props.modelValue;
    previewError.value = false;

    try {
        const response = await http.submit(
            EstimationController.markdownPreview(),
        );

        if (generation === requestGeneration) {
            previewHtml.value = response.html;
        }
    } catch {
        if (generation === requestGeneration) {
            previewHtml.value = '';
            previewError.value = true;
        }
    }
}

function switchTo(next: 'edit' | 'preview'): void {
    mode.value = next;

    if (next === 'preview') {
        void showPreview();
    }
}

function onInput(event: Event): void {
    emit('update:modelValue', (event.target as HTMLTextAreaElement).value);
}
</script>

<template>
    <div class="grid gap-2">
        <div class="flex items-center justify-between">
            <Label :for="fieldId">{{ label }}</Label>
            <div class="flex gap-1">
                <Button
                    type="button"
                    size="sm"
                    :variant="mode === 'edit' ? 'secondary' : 'ghost'"
                    @click="switchTo('edit')"
                >
                    {{ t('markdownField.edit') }}
                </Button>
                <Button
                    type="button"
                    size="sm"
                    :variant="mode === 'preview' ? 'secondary' : 'ghost'"
                    @click="switchTo('preview')"
                >
                    {{ t('markdownField.preview') }}
                </Button>
            </div>
        </div>

        <textarea
            v-if="mode === 'edit'"
            :id="fieldId"
            :value="modelValue"
            rows="8"
            class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
            :placeholder="placeholder"
            @input="onInput"
        />
        <div
            v-else
            class="min-h-40 rounded-md border border-input px-3 py-2 text-sm"
        >
            <p v-if="http.processing" class="text-muted-foreground">
                {{ t('markdownField.loading') }}
            </p>
            <p v-else-if="previewError" class="text-destructive">
                {{ t('markdownField.error') }}
            </p>
            <div v-else-if="previewHtml" v-html="previewHtml" />
            <p v-else class="text-muted-foreground">
                {{ t('markdownField.empty') }}
            </p>
        </div>

        <InputError :message="error" />
    </div>
</template>

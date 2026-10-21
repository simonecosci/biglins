<script setup lang="ts">
import { Head, router, setLayoutProps, useForm } from '@inertiajs/vue3';
import { computed, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import EInvoicingIntegrationController from '@/actions/App/Http/Controllers/EInvoicingIntegrationController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Select,
    SelectContent,
    SelectItem,
    SelectTrigger,
    SelectValue,
} from '@/components/ui/select';
import { index } from '@/routes/companies';
import type { BreadcrumbItem } from '@/types';

type CredentialField = {
    name: string;
    type: 'text' | 'password';
    required: boolean;
};

const props = defineProps<{
    company: { id: string; name: string; country_iso: string | null };
    drivers: { value: string; label: string; fields: CredentialField[] }[];
    environments: string[];
    integration: {
        driver: string;
        environment: string;
        is_active: boolean;
        configured_credentials: string[];
    } | null;
    webhookUrl: string | null;
}>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('companies.index.title'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const form = useForm({
    driver: props.integration?.driver ?? props.drivers[0]?.value ?? '',
    environment: props.integration?.environment ?? 'sandbox',
    is_active: props.integration?.is_active ?? false,
    credentials: {} as Record<string, string>,
});

const currentFields = computed(
    () =>
        props.drivers.find((driver) => driver.value === form.driver)?.fields ??
        [],
);

const copied = ref(false);

function isConfigured(name: string): boolean {
    return (
        props.integration?.driver === form.driver &&
        props.integration.configured_credentials.includes(name)
    );
}

function submit(): void {
    form.put(EInvoicingIntegrationController.update(props.company.id).url, {
        preserveScroll: true,
        onSuccess: () => form.reset('credentials'),
    });
}

function testConnection(): void {
    router.post(
        EInvoicingIntegrationController.test(props.company.id).url,
        {},
        { preserveScroll: true },
    );
}

async function copyWebhookUrl(): Promise<void> {
    if (props.webhookUrl) {
        await navigator.clipboard.writeText(props.webhookUrl);
        copied.value = true;
    }
}
</script>

<template>
    <Head :title="t('companies.eInvoicing.title')" />

    <div class="flex max-w-lg flex-col space-y-6">
        <Heading
            :title="t('companies.eInvoicing.title')"
            :description="
                t('companies.eInvoicing.description', { name: company.name })
            "
        />

        <p v-if="drivers.length === 0" class="text-sm text-muted-foreground">
            {{ t('companies.eInvoicing.unsupportedCountry') }}
        </p>

        <form v-else class="space-y-4" @submit.prevent="submit">
            <div class="grid gap-2">
                <Label for="driver">{{
                    t('companies.eInvoicing.driver')
                }}</Label>
                <Select v-model="form.driver">
                    <SelectTrigger id="driver" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="driver in drivers"
                            :key="driver.value"
                            :value="driver.value"
                        >
                            {{ driver.label }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.driver" />
            </div>

            <div class="grid gap-2">
                <Label for="environment">{{
                    t('companies.eInvoicing.environment')
                }}</Label>
                <Select v-model="form.environment">
                    <SelectTrigger id="environment" class="w-full">
                        <SelectValue />
                    </SelectTrigger>
                    <SelectContent>
                        <SelectItem
                            v-for="environment in environments"
                            :key="environment"
                            :value="environment"
                        >
                            {{
                                t(
                                    `companies.eInvoicing.environments.${environment}`,
                                )
                            }}
                        </SelectItem>
                    </SelectContent>
                </Select>
                <InputError :message="form.errors.environment" />
            </div>

            <div
                v-for="field in currentFields"
                :key="field.name"
                class="grid gap-2"
            >
                <Label :for="`credential_${field.name}`">
                    {{ t(`companies.eInvoicing.credentials.${field.name}`) }}
                </Label>
                <Input
                    :id="`credential_${field.name}`"
                    v-model="form.credentials[field.name]"
                    :type="field.type"
                    autocomplete="off"
                    :placeholder="
                        isConfigured(field.name)
                            ? t('companies.eInvoicing.credentialSet')
                            : ''
                    "
                />
                <InputError
                    :message="form.errors[`credentials.${field.name}`]"
                />
            </div>

            <div class="flex items-center gap-2">
                <Checkbox id="is_active" v-model="form.is_active" />
                <Label for="is_active">{{
                    t('companies.eInvoicing.active')
                }}</Label>
            </div>

            <div class="flex items-center gap-4 pt-2">
                <Button :disabled="form.processing" type="submit">{{
                    t('common.actions.save')
                }}</Button>
                <Button
                    type="button"
                    variant="outline"
                    :disabled="!integration"
                    @click="testConnection"
                >
                    {{ t('companies.eInvoicing.testConnection') }}
                </Button>
            </div>
        </form>

        <div v-if="webhookUrl" class="grid gap-2 border-t pt-6">
            <Label for="webhook_url">{{
                t('companies.eInvoicing.webhookUrl')
            }}</Label>
            <div class="flex gap-2">
                <Input id="webhook_url" :model-value="webhookUrl" readonly />
                <Button type="button" variant="outline" @click="copyWebhookUrl">
                    {{
                        copied
                            ? t('companies.eInvoicing.copied')
                            : t('companies.eInvoicing.copy')
                    }}
                </Button>
            </div>
            <p class="text-sm text-muted-foreground">
                {{ t('companies.eInvoicing.webhookHint') }}
            </p>
        </div>
    </div>
</template>

<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import TextLink from '@/components/TextLink.vue';
import { Button } from '@/components/ui/button';
import { Spinner } from '@/components/ui/spinner';
import { logout } from '@/routes';
import { send } from '@/routes/verification';

const { t } = useI18n();

setLayoutProps({
    title: t('auth.verifyEmail.layoutTitle'),
    description: t('auth.verifyEmail.layoutDescription'),
});

defineProps<{
    status?: string;
}>();
</script>

<template>
    <Head :title="t('auth.verifyEmail.headTitle')" />

    <div
        v-if="status === 'verification-link-sent'"
        class="mb-4 text-center text-sm font-medium text-green-600"
    >
        {{ t('auth.verifyEmail.sent') }}
    </div>

    <Form
        v-bind="send.form()"
        class="space-y-6 text-center"
        v-slot="{ processing }"
    >
        <Button :disabled="processing" variant="secondary">
            <Spinner v-if="processing" />
            {{ t('auth.verifyEmail.resend') }}
        </Button>

        <TextLink :href="logout()" as="button" class="mx-auto block text-sm">
            {{ t('auth.verifyEmail.logout') }}
        </TextLink>
    </Form>
</template>

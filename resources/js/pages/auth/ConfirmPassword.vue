<script setup lang="ts">
import { Form, Head, setLayoutProps } from '@inertiajs/vue3';
import { useI18n } from 'vue-i18n';
import {
    index as confirmOptions,
    store as confirmStore,
} from '@/actions/Laravel/Passkeys/Http/Controllers/PasskeyConfirmationController';
import InputError from '@/components/InputError.vue';
import PasskeyVerify from '@/components/PasskeyVerify.vue';
import PasswordInput from '@/components/PasswordInput.vue';
import { Button } from '@/components/ui/button';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { store } from '@/routes/password/confirm';

const { t } = useI18n();

setLayoutProps({
    title: t('auth.confirmPassword.layoutTitle'),
    description: t('auth.confirmPassword.layoutDescription'),
});
</script>

<template>
    <Head :title="t('auth.confirmPassword.headTitle')" />

    <PasskeyVerify
        :routes="{
            options: confirmOptions(),
            submit: confirmStore(),
        }"
        :label="t('auth.confirmPassword.passkeyLabel')"
        :loading-label="t('auth.confirmPassword.passkeyLoading')"
        :separator="t('auth.confirmPassword.passkeySeparator')"
    />

    <Form
        v-bind="store.form()"
        reset-on-success
        v-slot="{ errors, processing }"
    >
        <div class="space-y-6">
            <div class="grid gap-2">
                <Label htmlFor="password">{{ t('auth.confirmPassword.password') }}</Label>
                <PasswordInput
                    id="password"
                    name="password"
                    class="mt-1 block w-full"
                    required
                    autocomplete="current-password"
                    autofocus
                />

                <InputError :message="errors.password" />
            </div>

            <div class="flex items-center">
                <Button
                    class="w-full"
                    :disabled="processing"
                    data-test="confirm-password-button"
                >
                    <Spinner v-if="processing" />
                    {{ t('auth.confirmPassword.submit') }}
                </Button>
            </div>
        </div>
    </Form>
</template>

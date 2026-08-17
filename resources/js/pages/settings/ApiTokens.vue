<script setup lang="ts">
import { Head, router, setLayoutProps } from '@inertiajs/vue3';
import { onMounted, onUnmounted, ref } from 'vue';
import { useI18n } from 'vue-i18n';
import ApiTokenController from '@/actions/App/Http/Controllers/Settings/ApiTokenController';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { confirmDialog } from '@/lib/confirmDialog';
import { index } from '@/routes/api-tokens';
import type { BreadcrumbItem } from '@/types';

type ApiToken = {
    id: number;
    name: string;
    created_at_diff: string | null;
    last_used_at_diff: string | null;
};

const props = defineProps<{ tokens: ApiToken[] }>();

const { t } = useI18n();

setLayoutProps({
    breadcrumbs: [
        { title: t('settings.apiTokens.pageTitle'), href: index() },
    ] satisfies BreadcrumbItem[],
});

const name = ref('');
const nameError = ref<string | undefined>(undefined);
const creating = ref(false);
const copied = ref(false);

const newToken = ref<string | undefined>(undefined);
let unsubscribe: (() => void) | undefined;

onMounted(() => {
    unsubscribe = router.on('flash', (event) => {
        const flash = (event as CustomEvent).detail?.flash as
            { newApiToken?: string } | undefined;

        if (flash?.newApiToken) {
            newToken.value = flash.newApiToken;
        }
    });
});

onUnmounted(() => {
    unsubscribe?.();
});

function createToken(): void {
    creating.value = true;
    nameError.value = undefined;

    router.post(
        ApiTokenController.store.url(),
        { name: name.value },
        {
            preserveScroll: true,
            onSuccess: () => {
                name.value = '';
            },
            onError: (errors) => {
                nameError.value = errors.name;
            },
            onFinish: () => {
                creating.value = false;
            },
        },
    );
}

async function revokeToken(tokenId: number): Promise<void> {
    if (!(await confirmDialog(t('settings.apiTokens.confirmRevoke')))) {
        return;
    }

    router.delete(ApiTokenController.destroy.url(tokenId), {
        preserveScroll: true,
    });
}

async function copyToken(): Promise<void> {
    if (!newToken.value) {
        return;
    }

    await navigator.clipboard.writeText(newToken.value);
    copied.value = true;
    setTimeout(() => {
        copied.value = false;
    }, 2000);
}
</script>

<template>
    <Head :title="t('settings.apiTokens.pageTitle')" />

    <h1 class="sr-only">{{ t('settings.apiTokens.pageTitle') }}</h1>

    <div class="space-y-6">
        <Heading
            variant="small"
            :title="t('settings.apiTokens.title')"
            :description="t('settings.apiTokens.description')"
        />

        <div
            v-if="newToken"
            class="space-y-2 rounded-md border border-amber-400 bg-amber-50 p-4 dark:bg-amber-950"
        >
            <p class="font-medium">
                {{ t('settings.apiTokens.newTokenTitle') }}
            </p>
            <p class="text-sm text-muted-foreground">
                {{ t('settings.apiTokens.newTokenDescription') }}
            </p>
            <div class="flex items-center gap-2">
                <code
                    class="flex-1 overflow-x-auto rounded bg-muted p-2 text-sm"
                    >{{ newToken }}</code
                >
                <Button variant="outline" size="sm" @click="copyToken">
                    {{
                        copied
                            ? t('settings.apiTokens.copied')
                            : t('settings.apiTokens.copy')
                    }}
                </Button>
            </div>
        </div>

        <form class="flex items-end gap-4" @submit.prevent="createToken">
            <div class="grid flex-1 gap-2">
                <Label for="token-name">{{
                    t('settings.apiTokens.namePlaceholder')
                }}</Label>
                <Input id="token-name" v-model="name" required />
                <InputError :message="nameError" />
            </div>
            <Button :disabled="creating" type="submit">
                {{ t('settings.apiTokens.create') }}
            </Button>
        </form>

        <p
            v-if="props.tokens.length === 0"
            class="text-sm text-muted-foreground"
        >
            {{ t('settings.apiTokens.empty') }}
        </p>

        <ul v-else class="divide-y">
            <li
                v-for="token in props.tokens"
                :key="token.id"
                class="flex items-center justify-between py-3"
            >
                <div>
                    <p class="font-medium">{{ token.name }}</p>
                    <p class="text-sm text-muted-foreground">
                        {{
                            t('settings.apiTokens.createdAt', {
                                time: token.created_at_diff,
                            })
                        }}
                        ·
                        {{
                            token.last_used_at_diff
                                ? t('settings.apiTokens.lastUsedAt', {
                                      time: token.last_used_at_diff,
                                  })
                                : t('settings.apiTokens.neverUsed')
                        }}
                    </p>
                </div>
                <Button
                    variant="destructive"
                    size="sm"
                    @click="revokeToken(token.id)"
                >
                    {{ t('settings.apiTokens.revoke') }}
                </Button>
            </li>
        </ul>
    </div>
</template>

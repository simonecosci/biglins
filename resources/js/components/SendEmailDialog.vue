<script setup lang="ts">
import { useForm } from '@inertiajs/vue3';
import { Mail } from '@lucide/vue';
import { ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';

const props = defineProps<{
    sendUrl: string;
    defaultTo: string | null;
    defaultSubject: string;
    defaultMessage: string;
}>();

const { t } = useI18n();

const open = ref(false);

const form = useForm({
    to: props.defaultTo ?? '',
    subject: props.defaultSubject,
    message: props.defaultMessage,
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    form.reset();
    form.clearErrors();
});

function submit(): void {
    form.post(props.sendUrl, {
        preserveScroll: true,
        onSuccess: () => {
            open.value = false;
        },
    });
}
</script>

<template>
    <Dialog v-model:open="open">
        <TooltipProvider :delay-duration="0">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        variant="ghost"
                        size="icon-sm"
                        :title="t('sendEmailDialog.title')"
                        @click="open = true"
                    >
                        <Mail />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    <p>{{ t('sendEmailDialog.title') }}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('sendEmailDialog.title') }}</DialogTitle>
            </DialogHeader>

            <form class="space-y-4" @submit.prevent="submit">
                <div class="grid gap-2">
                    <Label for="send-email-to">{{
                        t('sendEmailDialog.to')
                    }}</Label>
                    <Input id="send-email-to" v-model="form.to" type="email" />
                    <InputError :message="form.errors.to" />
                </div>
                <div class="grid gap-2">
                    <Label for="send-email-subject">{{
                        t('sendEmailDialog.subject')
                    }}</Label>
                    <Input id="send-email-subject" v-model="form.subject" />
                    <InputError :message="form.errors.subject" />
                </div>
                <div class="grid gap-2">
                    <Label for="send-email-message">{{
                        t('sendEmailDialog.message')
                    }}</Label>
                    <textarea
                        id="send-email-message"
                        v-model="form.message"
                        rows="6"
                        class="w-full rounded-md border border-input bg-transparent px-3 py-2 text-base shadow-xs outline-none focus-visible:ring-[3px] focus-visible:ring-ring/50 md:text-sm dark:bg-input/30"
                    />
                    <InputError :message="form.errors.message" />
                </div>
                <DialogFooter>
                    <Button type="submit" :disabled="form.processing">
                        {{ t('sendEmailDialog.send') }}
                    </Button>
                </DialogFooter>
            </form>
        </DialogContent>
    </Dialog>
</template>

<script setup lang="ts">
import { router } from '@inertiajs/vue3';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import InvoiceSubmissionController from '@/actions/App/Http/Controllers/InvoiceSubmissionController';
import { Alert, AlertDescription, AlertTitle } from '@/components/ui/alert';
import { Badge } from '@/components/ui/badge';
import { Button } from '@/components/ui/button';
import type { InvoiceStatus, SubmissionStatus } from '@/lib/invoiceStatus';
import { submissionStatusVariant } from '@/lib/invoiceStatus';

export type Submission = {
    id: string;
    status: SubmissionStatus;
    provider_status: string | null;
    authority_id: string | null;
    error_message: string | null;
    submitted_at: string | null;
    completed_at: string | null;
    created_at: string;
    events: { id: string; type: string; received_at: string }[];
};

const props = defineProps<{
    invoiceId: string;
    invoiceStatus: InvoiceStatus;
    submissions: Submission[];
}>();

const { t } = useI18n();

const latest = computed(() => props.submissions[0] ?? null);

const alertStatus = computed(() =>
    latest.value &&
    ['failed', 'rejected', 'not_delivered'].includes(latest.value.status)
        ? latest.value.status
        : null,
);

function formatDateTime(value: string | null): string {
    return value ? new Date(value).toLocaleString() : '—';
}

function refresh(): void {
    router.post(
        InvoiceSubmissionController.refresh(props.invoiceId).url,
        {},
        { preserveScroll: true },
    );
}

function retry(): void {
    router.post(
        InvoiceSubmissionController.issue(props.invoiceId).url,
        {},
        { preserveScroll: true },
    );
}
</script>

<template>
    <section v-if="latest" class="space-y-4 rounded-lg border p-4">
        <div class="flex items-center justify-between">
            <h2 class="text-sm font-medium">
                {{ t('invoiceSubmissions.title') }}
            </h2>
            <div class="flex gap-2">
                <Button
                    v-if="
                        latest.status === 'pending' ||
                        latest.status === 'submitted'
                    "
                    size="sm"
                    variant="outline"
                    @click="refresh"
                >
                    {{ t('invoiceSubmissions.refresh') }}
                </Button>
                <Button
                    v-if="
                        invoiceStatus === 'draft' && latest.status === 'failed'
                    "
                    size="sm"
                    @click="retry"
                >
                    {{ t('invoiceSubmissions.retry') }}
                </Button>
            </div>
        </div>

        <Alert
            v-if="alertStatus"
            :variant="
                alertStatus === 'not_delivered' ? 'default' : 'destructive'
            "
        >
            <AlertTitle>{{
                t(`invoiceSubmissions.status.${alertStatus}`)
            }}</AlertTitle>
            <AlertDescription>
                {{ t(`invoiceSubmissions.alerts.${alertStatus}`) }}
                <span
                    v-if="latest.error_message"
                    class="block font-mono text-xs"
                    >{{ latest.error_message }}</span
                >
            </AlertDescription>
        </Alert>

        <ul class="space-y-3 text-sm">
            <li
                v-for="submission in submissions"
                :key="submission.id"
                class="space-y-1"
            >
                <div class="flex flex-wrap items-center gap-2">
                    <Badge
                        :variant="submissionStatusVariant(submission.status)"
                    >
                        {{
                            t(`invoiceSubmissions.status.${submission.status}`)
                        }}
                    </Badge>
                    <span
                        v-if="submission.provider_status"
                        class="text-muted-foreground"
                        >{{ submission.provider_status }}</span
                    >
                    <span class="text-muted-foreground">{{
                        formatDateTime(submission.created_at)
                    }}</span>
                </div>
                <div
                    v-if="submission.authority_id"
                    class="text-muted-foreground"
                >
                    {{ t('invoiceSubmissions.authorityId') }}:
                    <span class="break-all">{{ submission.authority_id }}</span>
                </div>
                <ul
                    v-if="submission.events.length"
                    class="ml-4 list-disc text-xs text-muted-foreground"
                >
                    <li v-for="event in submission.events" :key="event.id">
                        {{ event.type }} —
                        {{ formatDateTime(event.received_at) }}
                    </li>
                </ul>
            </li>
        </ul>
    </section>
</template>

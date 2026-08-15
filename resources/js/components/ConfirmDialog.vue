<script setup lang="ts">
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogDescription,
    DialogFooter,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import {
    resolveConfirmDialog,
    useConfirmDialogState,
} from '@/lib/confirmDialog';

const { t } = useI18n();
const state = useConfirmDialogState();

function onUpdateOpen(open: boolean): void {
    if (!open) {
        resolveConfirmDialog(false);
    }
}
</script>

<template>
    <Dialog :open="state.open" @update:open="onUpdateOpen">
        <DialogContent>
            <DialogHeader>
                <DialogTitle>{{
                    state.title || t('common.confirm.title')
                }}</DialogTitle>
                <DialogDescription>{{ state.message }}</DialogDescription>
            </DialogHeader>
            <DialogFooter>
                <Button variant="outline" @click="resolveConfirmDialog(false)">
                    {{ t('common.actions.cancel') }}
                </Button>
                <Button
                    variant="destructive"
                    @click="resolveConfirmDialog(true)"
                >
                    {{ t('common.actions.confirm') }}
                </Button>
            </DialogFooter>
        </DialogContent>
    </Dialog>
</template>

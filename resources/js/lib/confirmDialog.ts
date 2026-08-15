import { reactive } from 'vue';

type ConfirmDialogState = {
    open: boolean;
    title: string;
    message: string;
    resolve: ((value: boolean) => void) | null;
};

const state = reactive<ConfirmDialogState>({
    open: false,
    title: '',
    message: '',
    resolve: null,
});

export function useConfirmDialogState(): ConfirmDialogState {
    return state;
}

export function confirmDialog(message: string, title = ''): Promise<boolean> {
    return new Promise((resolve) => {
        state.title = title;
        state.message = message;
        state.open = true;
        state.resolve = resolve;
    });
}

export function resolveConfirmDialog(result: boolean): void {
    state.open = false;
    state.resolve?.(result);
    state.resolve = null;
}

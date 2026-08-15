<script setup lang="ts">
import { NotebookPen } from '@lucide/vue';
import { nextTick, ref, watch } from 'vue';
import { useI18n } from 'vue-i18n';
import { Button } from '@/components/ui/button';
import {
    Dialog,
    DialogContent,
    DialogHeader,
    DialogTitle,
} from '@/components/ui/dialog';
import { Input } from '@/components/ui/input';
import { Spinner } from '@/components/ui/spinner';
import {
    Tooltip,
    TooltipContent,
    TooltipProvider,
    TooltipTrigger,
} from '@/components/ui/tooltip';
import { index } from '@/routes/notes';

export type PickedNote = {
    id: string;
    title: string;
    content: string;
};

type PaginatedNotes = {
    data: PickedNote[];
    current_page: number;
    last_page: number;
};

const emit = defineEmits<{
    select: [note: PickedNote];
}>();

const { t } = useI18n();

const open = ref(false);
const search = ref('');
const loading = ref(false);
const notes = ref<PickedNote[]>([]);
const currentPage = ref(1);
const lastPage = ref(1);
const searchInput = ref<InstanceType<typeof Input> | null>(null);

let debounceTimer: ReturnType<typeof setTimeout> | undefined;

async function loadNotes(page = 1): Promise<void> {
    loading.value = true;

    try {
        const url = new URL(index().url, window.location.origin);

        if (search.value !== '') {
            url.searchParams.set('search', search.value);
        }

        url.searchParams.set('page', String(page));

        const response = await fetch(url.toString(), {
            headers: { Accept: 'application/json' },
        });

        const data = (await response.json()) as PaginatedNotes;

        notes.value = data.data;
        currentPage.value = data.current_page;
        lastPage.value = data.last_page;
    } finally {
        loading.value = false;
    }
}

watch(search, () => {
    if (debounceTimer) {
        clearTimeout(debounceTimer);
    }

    debounceTimer = setTimeout(() => {
        void loadNotes(1);
    }, 300);
});

watch(open, (isOpen) => {
    if (!isOpen) {
        return;
    }

    search.value = '';
    void loadNotes(1);
    void nextTick(() => searchInput.value?.$el?.focus());
});

function choose(note: PickedNote): void {
    emit('select', note);
}
</script>

<template>
    <Dialog v-model:open="open">
        <TooltipProvider :delay-duration="0">
            <Tooltip>
                <TooltipTrigger as-child>
                    <Button
                        type="button"
                        variant="outline"
                        size="icon"
                        @click="open = true"
                    >
                        <span class="sr-only">{{
                            t('notePicker.fallbackLabel')
                        }}</span>
                        <NotebookPen class="size-4 text-muted-foreground" />
                    </Button>
                </TooltipTrigger>
                <TooltipContent>
                    <p>{{ t('notePicker.fallbackLabel') }}</p>
                </TooltipContent>
            </Tooltip>
        </TooltipProvider>

        <DialogContent class="sm:max-w-lg">
            <DialogHeader>
                <DialogTitle>{{ t('notePicker.title') }}</DialogTitle>
            </DialogHeader>

            <Input
                ref="searchInput"
                v-model="search"
                :placeholder="t('notePicker.searchPlaceholder')"
            />

            <div class="max-h-80 overflow-y-auto rounded-md border">
                <div
                    v-if="loading"
                    class="flex items-center justify-center py-8"
                >
                    <Spinner />
                </div>
                <ul v-else-if="notes.length > 0" class="divide-y">
                    <li v-for="note in notes" :key="note.id">
                        <button
                            type="button"
                            class="flex w-full flex-col items-start gap-0.5 px-3 py-2 text-left text-sm hover:bg-accent"
                            @click="choose(note)"
                        >
                            <span class="font-medium">{{ note.title }}</span>
                            <span class="line-clamp-1 text-muted-foreground">{{
                                note.content
                            }}</span>
                        </button>
                    </li>
                </ul>
                <p
                    v-else
                    class="px-3 py-8 text-center text-sm text-muted-foreground"
                >
                    {{ t('notePicker.empty') }}
                </p>
            </div>

            <div
                v-if="lastPage > 1"
                class="flex items-center justify-between text-sm text-muted-foreground"
            >
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="currentPage <= 1 || loading"
                    @click="loadNotes(currentPage - 1)"
                >
                    {{ t('common.actions.previous') }}
                </Button>
                <span>{{
                    t('notePicker.page', {
                        current: currentPage,
                        last: lastPage,
                    })
                }}</span>
                <Button
                    type="button"
                    variant="outline"
                    size="sm"
                    :disabled="currentPage >= lastPage || loading"
                    @click="loadNotes(currentPage + 1)"
                >
                    {{ t('common.actions.next') }}
                </Button>
            </div>
        </DialogContent>
    </Dialog>
</template>

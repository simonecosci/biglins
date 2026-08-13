<script setup lang="ts">
import { Link, router, usePage } from '@inertiajs/vue3';
import { Building2, ChevronDown, Plus } from '@lucide/vue';
import type { AcceptableValue } from 'reka-ui';
import { computed } from 'vue';
import { useI18n } from 'vue-i18n';
import CurrentCompanyController from '@/actions/App/Http/Controllers/CurrentCompanyController';
import { Button } from '@/components/ui/button';
import {
    DropdownMenu,
    DropdownMenuContent,
    DropdownMenuLabel,
    DropdownMenuRadioGroup,
    DropdownMenuRadioItem,
    DropdownMenuSeparator,
    DropdownMenuTrigger,
} from '@/components/ui/dropdown-menu';
import { create } from '@/routes/companies';

const { t } = useI18n();

const page = usePage();
const currentCompany = computed(() => page.props.currentCompany);
const companies = computed(() => page.props.companies);

function selectCompany(value: AcceptableValue): void {
    if (typeof value !== 'string' || value === currentCompany.value?.id) {
        return;
    }

    router.put(
        CurrentCompanyController.update.url(),
        { company_id: value },
        { preserveScroll: true },
    );
}
</script>

<template>
    <DropdownMenu v-if="companies.length > 0">
        <DropdownMenuTrigger :as-child="true">
            <Button variant="ghost" size="sm" class="gap-2">
                <Building2 class="size-4" />
                <span class="max-w-40 truncate">{{
                    currentCompany?.name
                }}</span>
                <ChevronDown class="size-3 opacity-60" />
            </Button>
        </DropdownMenuTrigger>
        <DropdownMenuContent align="end" class="w-56">
            <DropdownMenuLabel>{{
                t('companySwitcher.label')
            }}</DropdownMenuLabel>
            <DropdownMenuSeparator />
            <DropdownMenuRadioGroup
                :model-value="currentCompany?.id"
                @update:model-value="selectCompany"
            >
                <DropdownMenuRadioItem
                    v-for="company in companies"
                    :key="company.id"
                    :value="company.id"
                >
                    {{ company.name }}
                </DropdownMenuRadioItem>
            </DropdownMenuRadioGroup>
        </DropdownMenuContent>
    </DropdownMenu>
    <Button v-else as-child variant="ghost" size="sm" class="gap-2">
        <Link :href="create()">
            <Plus class="size-4" />
            {{ t('companySwitcher.createFirst') }}
        </Link>
    </Button>
</template>

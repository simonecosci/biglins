<script setup lang="ts">
import type { HTMLAttributes } from 'vue';

defineOptions({
    inheritAttrs: false,
});

type Props = {
    className?: HTMLAttributes['class'];
};

defineProps<Props>();
</script>

<template>
    <img
        src="/images/logo-icon.png"
        alt="Logo"
        class="object-contain"
        :class="className"
        v-bind="$attrs"
    />
</template>

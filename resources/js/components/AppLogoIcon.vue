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
    <svg
        xmlns="http://www.w3.org/2000/svg"
        viewBox="0 0 40 40"
        fill="none"
        :class="className"
        v-bind="$attrs"
    >
        <path
            d="M14 10v20M20 10l7 10-7 10"
            stroke="currentColor"
            stroke-width="3.4"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
    </svg>
</template>

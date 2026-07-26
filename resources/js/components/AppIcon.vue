<script setup lang="ts">
import { computed, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        launchUrl: string | null;
        initials: string;
        accent: string | null;
        size?: 'xs' | 'sm' | 'md' | 'node';
        disabled?: boolean;
    }>(),
    {
        size: 'md',
        disabled: false,
    },
);

// Try the app's own favicon (svg first for crispness, then ico); fall back to
// the initials tile when neither loads or the user can't access the app.
const candidates = computed<string[]>(() => {
    if (props.launchUrl === null) {
        return [];
    }

    try {
        const origin = new URL(props.launchUrl).origin;

        return [`${origin}/favicon.svg`, `${origin}/favicon.ico`];
    } catch {
        return [];
    }
});

const index = ref(0);
const exhausted = ref(false);

watch(candidates, () => {
    index.value = 0;
    exhausted.value = false;
});

const src = computed<string | null>(
    () => candidates.value[index.value] ?? null,
);
const showIcon = computed(
    () => !props.disabled && !exhausted.value && src.value !== null,
);

function onError(): void {
    if (index.value < candidates.value.length - 1) {
        index.value += 1;

        return;
    }

    exhausted.value = true;
}

// Some apps serve an empty 200 for favicon.ico, which fires `load`, not
// `error`. Treat a zero-dimension image as a miss so the fallback still kicks in.
function onLoad(event: Event): void {
    if ((event.target as HTMLImageElement).naturalWidth === 0) {
        onError();
    }
}

const tile = computed(
    () =>
        ({
            xs: 'size-[22px] rounded-md',
            sm: 'size-8 rounded-lg',
            md: 'size-11 rounded-xl',
            node: 'size-9 rounded-[10px]',
        })[props.size],
);
const text = computed(
    () =>
        ({
            xs: 'text-[11px]',
            sm: 'text-sm',
            md: 'text-lg',
            node: 'text-base',
        })[props.size],
);
const pad = computed(
    () => ({ xs: 'p-[3px]', sm: 'p-1', md: 'p-1.5', node: 'p-1' })[props.size],
);

const fallbackStyle = computed(() =>
    props.disabled
        ? {}
        : {
              background: `linear-gradient(150deg, color-mix(in srgb, ${props.accent ?? '#B7863A'} 82%, white), ${props.accent ?? '#B7863A'})`,
          },
);
</script>

<template>
    <span
        :class="[
            tile,
            'flex shrink-0 items-center justify-center overflow-hidden',
            showIcon
                ? 'bg-muted'
                : disabled
                  ? 'bg-muted font-extrabold text-muted-foreground grayscale'
                  : ['font-extrabold text-white', text],
        ]"
        :style="showIcon ? {} : fallbackStyle"
    >
        <img
            v-if="showIcon"
            :src="src!"
            alt=""
            :class="['size-full object-contain', pad]"
            @error="onError"
            @load="onLoad"
        />
        <template v-else>{{ initials }}</template>
    </span>
</template>

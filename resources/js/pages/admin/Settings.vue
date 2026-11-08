<script setup lang="ts">
import { Form, Head } from '@inertiajs/vue3';
import Heading from '@/components/Heading.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent } from '@/components/ui/card';
import { edit, update } from '@/routes/admin/settings';

const props = defineProps<{ qrLoginEnabled: boolean }>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Settings', href: edit() }],
    },
});
</script>

<template>
    <Head title="Settings" />

    <div class="space-y-6">
        <Heading
            title="Settings"
            description="Switches that apply to everyone who signs in."
        />

        <Card>
            <CardContent class="p-6">
                <Form
                    v-bind="update.form()"
                    v-slot="{ processing }"
                    class="flex flex-col gap-4"
                >
                    <label class="flex items-start gap-3">
                        <input
                            type="checkbox"
                            name="qr_login_enabled"
                            value="1"
                            :checked="props.qrLoginEnabled"
                            class="mt-1 size-4 rounded border-border"
                        />
                        <span class="space-y-1">
                            <span class="block text-sm font-medium">
                                Sign in with a QR code
                            </span>
                            <span class="block text-sm text-muted-foreground">
                                Lets a signed-in phone approve a sign-in on a
                                computer, and the other way round. Turning it
                                off hides the QR options and refuses every QR
                                request, including ones already shown. Changing
                                this needs a recent confirmation.
                            </span>
                        </span>
                    </label>
                    <div>
                        <Button type="submit" :disabled="processing">
                            Save
                        </Button>
                    </div>
                </Form>
            </CardContent>
        </Card>
    </div>
</template>

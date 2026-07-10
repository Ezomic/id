<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import {
    Card,
    CardContent,
    CardDescription,
    CardHeader,
    CardTitle,
} from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { Spinner } from '@/components/ui/spinner';
import { index as usersIndex } from '@/routes/admin/users';
import { store } from '@/routes/admin/users';
import { update as updateAccess } from '@/routes/admin/users/access';

interface Application {
    id: number;
    name: string;
    slug: string;
    active: boolean;
}

interface AdminUser {
    id: number;
    name: string;
    email: string;
    is_admin: boolean;
    application_ids: number[];
}

const props = defineProps<{
    users: AdminUser[];
    applications: Application[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Users', href: usersIndex() }],
    },
});

const createForm = useForm<{
    name: string;
    email: string;
    is_admin: boolean;
    applications: number[];
}>({
    name: '',
    email: '',
    is_admin: false,
    applications: [],
});

function toggle(list: number[], id: number): number[] {
    return list.includes(id) ? list.filter((v) => v !== id) : [...list, id];
}

function submitCreate() {
    createForm.post(store.url(), {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

const access = reactive<Record<number, number[]>>(
    Object.fromEntries(props.users.map((u) => [u.id, [...u.application_ids]])),
);

const savingAccess = reactive<Record<number, boolean>>({});

function saveAccess(user: AdminUser) {
    savingAccess[user.id] = true;
    router.put(
        updateAccess(user.id).url,
        { applications: access[user.id] },
        {
            preserveScroll: true,
            onFinish: () => (savingAccess[user.id] = false),
        },
    );
}
</script>

<template>
    <Head title="Users" />

    <div class="flex flex-col gap-6 p-4">
        <Heading
            title="Users & access"
            description="Create users and control which workflow apps each one can sign in to."
        />

        <Card>
            <CardHeader>
                <CardTitle>Add user</CardTitle>
                <CardDescription>
                    New users sign in passwordlessly with an email code or a
                    passkey. No password is set.
                </CardDescription>
            </CardHeader>
            <CardContent>
                <form
                    class="flex flex-col gap-4"
                    @submit.prevent="submitCreate"
                >
                    <div class="grid gap-4 md:grid-cols-2">
                        <div class="grid gap-2">
                            <Label for="name">Name</Label>
                            <Input
                                id="name"
                                v-model="createForm.name"
                                required
                            />
                            <InputError :message="createForm.errors.name" />
                        </div>
                        <div class="grid gap-2">
                            <Label for="email">Email</Label>
                            <Input
                                id="email"
                                type="email"
                                v-model="createForm.email"
                                required
                            />
                            <InputError :message="createForm.errors.email" />
                        </div>
                    </div>

                    <Label class="flex items-center gap-2">
                        <Checkbox v-model="createForm.is_admin" />
                        <span>Administrator</span>
                    </Label>

                    <div class="grid gap-2">
                        <span class="text-sm font-medium">App access</span>
                        <div class="flex flex-wrap gap-3">
                            <Label
                                v-for="app in applications"
                                :key="app.id"
                                class="flex items-center gap-2"
                            >
                                <Checkbox
                                    :model-value="
                                        createForm.applications.includes(app.id)
                                    "
                                    @update:model-value="
                                        createForm.applications = toggle(
                                            createForm.applications,
                                            app.id,
                                        )
                                    "
                                />
                                <span>{{ app.name }}</span>
                            </Label>
                        </div>
                    </div>

                    <div>
                        <Button type="submit" :disabled="createForm.processing">
                            <Spinner v-if="createForm.processing" />
                            Create user
                        </Button>
                    </div>
                </form>
            </CardContent>
        </Card>

        <Card>
            <CardHeader>
                <CardTitle>Existing users</CardTitle>
            </CardHeader>
            <CardContent class="flex flex-col gap-6">
                <div
                    v-for="user in users"
                    :key="user.id"
                    class="flex flex-col gap-3 rounded-lg border p-4"
                >
                    <div class="flex items-center justify-between">
                        <div>
                            <p class="font-medium">
                                {{ user.name }}
                                <span
                                    v-if="user.is_admin"
                                    class="ml-2 text-xs text-muted-foreground"
                                    >(admin)</span
                                >
                            </p>
                            <p class="text-sm text-muted-foreground">
                                {{ user.email }}
                            </p>
                        </div>
                        <Button
                            size="sm"
                            variant="outline"
                            :disabled="savingAccess[user.id]"
                            @click="saveAccess(user)"
                        >
                            <Spinner v-if="savingAccess[user.id]" />
                            Save access
                        </Button>
                    </div>
                    <div class="flex flex-wrap gap-3">
                        <Label
                            v-for="app in applications"
                            :key="app.id"
                            class="flex items-center gap-2"
                        >
                            <Checkbox
                                :model-value="access[user.id].includes(app.id)"
                                @update:model-value="
                                    access[user.id] = toggle(
                                        access[user.id],
                                        app.id,
                                    )
                                "
                            />
                            <span>{{ app.name }}</span>
                        </Label>
                    </div>
                </div>
            </CardContent>
        </Card>
    </div>
</template>

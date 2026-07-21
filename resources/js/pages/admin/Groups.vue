<script setup lang="ts">
import { Head, router, useForm } from '@inertiajs/vue3';
import { reactive } from 'vue';
import Heading from '@/components/Heading.vue';
import InputError from '@/components/InputError.vue';
import { Button } from '@/components/ui/button';
import { Card, CardContent, CardHeader, CardTitle } from '@/components/ui/card';
import { Checkbox } from '@/components/ui/checkbox';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { destroy, index, store, update } from '@/routes/admin/groups';

interface Group {
    id: number;
    name: string;
    user_ids: number[];
    application_ids: number[];
}

interface Option {
    id: number;
    name: string;
    email?: string;
}

const props = defineProps<{
    groups: Group[];
    users: Option[];
    applications: Option[];
}>();

defineOptions({
    layout: {
        breadcrumbs: [{ title: 'Groups', href: index() }],
    },
});

const createForm = useForm({ name: '' });

const selection = reactive<
    Record<number, { users: number[]; applications: number[] }>
>(
    Object.fromEntries(
        props.groups.map((group) => [
            group.id,
            {
                users: [...group.user_ids],
                applications: [...group.application_ids],
            },
        ]),
    ),
);

function createGroup() {
    createForm.post(store().url, {
        preserveScroll: true,
        onSuccess: () => createForm.reset(),
    });
}

function saveGroup(group: Group) {
    router.put(
        update(group.id).url,
        {
            users: selection[group.id].users,
            applications: selection[group.id].applications,
        },
        { preserveScroll: true },
    );
}

function deleteGroup(group: Group) {
    router.delete(destroy(group.id).url, { preserveScroll: true });
}
</script>

<template>
    <Head title="Groups" />

    <div class="space-y-6">
        <Heading
            title="Groups"
            description="Grant app access to a group of users at once. Effective access is direct grants plus every group a user is in."
        />

        <Card>
            <CardHeader>
                <CardTitle>New group</CardTitle>
            </CardHeader>
            <CardContent>
                <form
                    class="flex items-end gap-3"
                    @submit.prevent="createGroup"
                >
                    <div class="flex-1 space-y-1">
                        <Label for="group-name">Name</Label>
                        <Input
                            id="group-name"
                            v-model="createForm.name"
                            placeholder="e.g. Engineering"
                        />
                        <InputError :message="createForm.errors.name" />
                    </div>
                    <Button type="submit" :disabled="createForm.processing">
                        Create
                    </Button>
                </form>
            </CardContent>
        </Card>

        <Card v-for="group in props.groups" :key="group.id">
            <CardHeader class="flex-row items-center justify-between">
                <CardTitle>{{ group.name }}</CardTitle>
                <Button
                    variant="ghost"
                    size="sm"
                    class="text-red-600"
                    @click="deleteGroup(group)"
                >
                    Delete
                </Button>
            </CardHeader>
            <CardContent class="grid gap-6 sm:grid-cols-2">
                <div class="space-y-2">
                    <p class="text-sm font-semibold">Members</p>
                    <label
                        v-for="user in props.users"
                        :key="user.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                selection[group.id].users.includes(user.id)
                            "
                            @update:model-value="
                                (v) =>
                                    (selection[group.id].users = v
                                        ? [
                                              ...selection[group.id].users,
                                              user.id,
                                          ]
                                        : selection[group.id].users.filter(
                                              (id) => id !== user.id,
                                          ))
                            "
                        />
                        {{ user.name }}
                    </label>
                </div>
                <div class="space-y-2">
                    <p class="text-sm font-semibold">Apps this group grants</p>
                    <label
                        v-for="application in props.applications"
                        :key="application.id"
                        class="flex items-center gap-2 text-sm"
                    >
                        <Checkbox
                            :model-value="
                                selection[group.id].applications.includes(
                                    application.id,
                                )
                            "
                            @update:model-value="
                                (v) =>
                                    (selection[group.id].applications = v
                                        ? [
                                              ...selection[group.id]
                                                  .applications,
                                              application.id,
                                          ]
                                        : selection[
                                              group.id
                                          ].applications.filter(
                                              (id) => id !== application.id,
                                          ))
                            "
                        />
                        {{ application.name }}
                    </label>
                </div>
                <div class="sm:col-span-2">
                    <Button size="sm" @click="saveGroup(group)">
                        Save group
                    </Button>
                </div>
            </CardContent>
        </Card>
    </div>
</template>

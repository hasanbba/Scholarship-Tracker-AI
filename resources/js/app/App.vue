<script setup>
import { computed } from 'vue';
import { RouterView, useRoute } from 'vue-router';
import PublicLayout from '../layouts/PublicLayout.vue';
import StudentLayout from '../layouts/StudentLayout.vue';
import AdminLayout from '../layouts/AdminLayout.vue';

const route = useRoute();
const layout = computed(() => ({ public: PublicLayout, student: StudentLayout, admin: AdminLayout })[route.meta.layout] || PublicLayout);
</script>

<template>
    <component :is="layout"><RouterView /></component>
</template>

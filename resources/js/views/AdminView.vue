<script setup>
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
</script>

<template>
    <section class="page-heading"><p class="eyebrow">FOUNDATION ACCESS</p><h1>Welcome to the <em>control room.</em></h1><p>Role and permission checks are active on the server and reflected in this account.</p></section>
    <section class="admin-notice"><div class="admin-shield">✓</div><div><span class="eyebrow">ACCESS CONFIRMED</span><h2>Administrator foundation is ready.</h2><p>This phase establishes secure access and the application shell. Catalog, crawler, review, user-management and reporting tools belong to later phases.</p></div></section>
    <section class="permission-panel"><span class="eyebrow">YOUR FOUNDATION PERMISSIONS</span><ul><li v-for="permission in auth.user?.permissions || []" :key="permission"><span class="status-dot"></span>{{ permission }}</li><li v-if="!auth.user?.permissions?.length" class="muted">No administrative permissions are assigned.</li></ul></section>
</template>

<script setup>
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
</script>

<template>
    <section class="page-heading"><p class="eyebrow">ACCOUNT</p><h1>Your <em>profile.</em></h1><p>Basic account information for your workspace.</p></section>
    <section class="profile-panel"><div class="profile-avatar">{{ auth.user?.name?.slice(0, 1)?.toUpperCase() }}</div><div><span class="eyebrow">SIGNED IN AS</span><h2>{{ auth.user?.name }}</h2><p>{{ auth.user?.email }}</p><span class="status-chip"><span class="status-dot"></span> Account active</span></div></section>
</template>

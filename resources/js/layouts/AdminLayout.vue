<script setup>
import { RouterLink, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
async function signOut() { await auth.logout(); await router.push('/'); }
</script>

<template>
    <div class="app-shell admin-shell">
        <aside class="sidebar admin-sidebar">
            <RouterLink class="brand" to="/admin"><span class="brand-mark">S</span><span>Scholar<span class="brand-light">Signal</span></span></RouterLink>
            <p class="sidebar-label">PLATFORM</p>
            <nav class="side-nav" aria-label="Administration navigation"><RouterLink to="/admin" class="side-link"><span>⌘</span> Foundation</RouterLink><RouterLink to="/dashboard" class="side-link"><span>⌂</span> Student view</RouterLink></nav>
            <div class="sidebar-bottom"><span class="avatar avatar-admin">{{ auth.user?.name?.slice(0, 1)?.toUpperCase() || 'A' }}</span><span class="user-mini"><strong>{{ auth.user?.name }}</strong><small>Administrator</small></span><button class="icon-button" aria-label="Sign out" @click="signOut">↗</button></div>
        </aside>
        <main class="workspace"><header class="workspace-top"><span class="eyebrow">ADMINISTRATION · PHASE 1</span><span class="secure-label"><span class="status-dot"></span> Permission checked</span></header><div class="workspace-content"><slot /></div></main>
    </div>
</template>

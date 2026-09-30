<script setup>
import { RouterLink, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
async function signOut() { await auth.logout(); await router.push('/'); }
</script>

<template>
    <div class="app-shell">
        <aside class="sidebar">
            <RouterLink class="brand" to="/dashboard"><span class="brand-mark">S</span><span>Scholar<span class="brand-light">Signal</span></span></RouterLink>
            <p class="sidebar-label">STUDENT SPACE</p>
            <nav class="side-nav" aria-label="Student navigation">
                <RouterLink to="/dashboard" class="side-link"><span>⌂</span> Overview</RouterLink>
                <RouterLink to="/profile" class="side-link"><span>◉</span> My profile</RouterLink>
            </nav>
            <div class="sidebar-bottom"><span class="avatar">{{ auth.user?.name?.slice(0, 1)?.toUpperCase() || 'S' }}</span><span class="user-mini"><strong>{{ auth.user?.name }}</strong><small>{{ auth.user?.email }}</small></span><button class="icon-button" aria-label="Sign out" @click="signOut">↗</button></div>
        </aside>
        <main class="workspace"><header class="workspace-top"><span class="eyebrow">STUDENT WORKSPACE</span><RouterLink v-if="auth.canAccessAdmin" to="/admin" class="nav-link">Administration <span aria-hidden="true">↗</span></RouterLink></header><div class="workspace-content"><slot /></div></main>
    </div>
</template>

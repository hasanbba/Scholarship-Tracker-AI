<script setup>
import { computed } from 'vue';
import { RouterLink, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const router = useRouter();
const homeTarget = computed(() => auth.isAuthenticated ? '/dashboard' : '/');

async function signOut() {
    await auth.logout();
    await router.push('/');
}
</script>

<template>
    <div class="site-shell">
        <header class="topbar public-topbar">
            <RouterLink class="brand" :to="homeTarget"><span class="brand-mark">S</span><span>Scholar<span class="brand-light">Signal</span></span></RouterLink>
            <nav class="top-actions" aria-label="Main navigation">
                <a href="/scholarships" class="nav-link">Browse scholarships</a>
                <RouterLink v-if="auth.isAuthenticated" to="/dashboard" class="nav-link">Workspace</RouterLink>
                <RouterLink v-if="auth.isAuthenticated && auth.canAccessAdmin" to="/admin" class="nav-link">Admin</RouterLink>
                <button v-if="auth.isAuthenticated" class="button button-quiet" @click="signOut">Sign out</button>
                <RouterLink v-else to="/login" class="button button-quiet">Sign in</RouterLink>
                <RouterLink v-if="!auth.isAuthenticated" to="/register" class="button button-primary button-small">Create account <span aria-hidden="true">↗</span></RouterLink>
            </nav>
        </header>
        <main class="public-main"><slot /></main>
        <footer class="site-footer"><span>ScholarSignal</span><span>Official opportunities, carefully verified.</span></footer>
    </div>
</template>

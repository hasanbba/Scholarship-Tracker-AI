<script setup>
import { ref } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { errorMessage } from '../services/api';

const auth = useAuthStore();
const route = useRoute();
const router = useRouter();
const form = ref({ email: '', password: '', remember: true });
const busy = ref(false);
const error = ref('');

async function submit() {
    busy.value = true;
    error.value = '';
    try {
        await auth.login(form.value);
        await router.replace(typeof route.query.redirect === 'string' ? route.query.redirect : '/dashboard');
    } catch (exception) {
        error.value = errorMessage(exception, 'Check your details and try again.');
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="auth-section"><div class="auth-aside"><p class="eyebrow">WELCOME BACK</p><h1>Good things<br /><em>are worth finding.</em></h1><p>Your workspace is ready when you are.</p><div class="aside-rule"></div><span class="aside-note">A little closer to the future you imagine.</span></div><div class="auth-card"><span class="card-index">01 / SIGN IN</span><h2>Come on in.</h2><p class="muted">Sign in to continue to your workspace.</p><div v-if="error" class="alert alert-error" role="alert">{{ error }}</div><form class="form-stack" @submit.prevent="submit"><label>Email address<input v-model.trim="form.email" type="email" autocomplete="email" required placeholder="you@example.com" /></label><label>Password<input v-model="form.password" type="password" autocomplete="current-password" required placeholder="Your password" /></label><label class="check-label"><input v-model="form.remember" type="checkbox" /> Keep me signed in</label><button class="button button-primary button-wide" :disabled="busy">{{ busy ? 'Signing you in…' : 'Sign in' }} <span aria-hidden="true">→</span></button></form><p class="auth-foot">New around here? <RouterLink to="/register">Create your account</RouterLink></p></div></section>
</template>

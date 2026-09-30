<script setup>
import { ref } from 'vue';
import { useRouter } from 'vue-router';
import { useAuthStore } from '../stores/auth';
import { errorMessage } from '../services/api';

const auth = useAuthStore();
const router = useRouter();
const form = ref({ name: '', email: '', password: '', password_confirmation: '' });
const busy = ref(false);
const error = ref('');
const fieldErrors = ref({});

async function submit() {
    busy.value = true;
    error.value = '';
    fieldErrors.value = {};
    try {
        await auth.register(form.value);
        await router.replace('/dashboard');
    } catch (exception) {
        error.value = errorMessage(exception, 'Please review your details and try again.');
        fieldErrors.value = exception.response?.data?.errors || {};
    } finally {
        busy.value = false;
    }
}
</script>

<template>
    <section class="auth-section"><div class="auth-aside"><p class="eyebrow">MAKE SPACE FOR WHAT'S NEXT</p><h1>Start with<br /><em>possibility.</em></h1><p>One thoughtful step toward the education you want.</p><div class="aside-rule"></div><span class="aside-note">Your account is personal and protected.</span></div><div class="auth-card"><span class="card-index">02 / CREATE ACCOUNT</span><h2>A fresh start.</h2><p class="muted">Create your student workspace.</p><div v-if="error" class="alert alert-error" role="alert">{{ error }}<ul v-if="Object.keys(fieldErrors).length"><li v-for="(messages, field) in fieldErrors" :key="field">{{ messages[0] }}</li></ul></div><form class="form-stack" @submit.prevent="submit"><label>Your name<input v-model.trim="form.name" type="text" autocomplete="name" required maxlength="120" placeholder="Your full name" /></label><label>Email address<input v-model.trim="form.email" type="email" autocomplete="email" required placeholder="you@example.com" /></label><label>Password<input v-model="form.password" type="password" autocomplete="new-password" required minlength="8" placeholder="At least 8 characters" /></label><label>Confirm password<input v-model="form.password_confirmation" type="password" autocomplete="new-password" required placeholder="Enter it again" /></label><button class="button button-primary button-wide" :disabled="busy">{{ busy ? 'Creating account…' : 'Create account' }} <span aria-hidden="true">→</span></button></form><p class="auth-foot">Already have an account? <RouterLink to="/login">Sign in</RouterLink></p></div></section>
</template>

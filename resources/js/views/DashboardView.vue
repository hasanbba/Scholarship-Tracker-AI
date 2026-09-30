<script setup>
import { useRoute } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const auth = useAuthStore();
const route = useRoute();
</script>

<template>
    <section class="page-heading"><p class="eyebrow">YOUR STUDENT SPACE</p><h1>Welcome, <em>{{ auth.user?.name?.split(' ')[0] }}.</em></h1><p>A calm place to start shaping your next step.</p></section>
    <div v-if="route.query.denied" class="alert alert-warning" role="status">That area needs administrator access. You’re back in your student workspace.</div>
    <section class="empty-panel"><div class="empty-icon">✳</div><span class="eyebrow">YOUR WORKSPACE IS READY</span><h2>Good things take a little groundwork.</h2><p>Your account is set up. Scholarship discovery and application tools will arrive in later phases, after the official-source and verification foundations are in place.</p><RouterLink to="/profile" class="text-link">View your account details <span aria-hidden="true">→</span></RouterLink></section>
</template>

import { createRouter, createWebHistory } from 'vue-router';
import { useAuthStore } from '../stores/auth';

const routes = [
    { path: '/', name: 'home', component: () => import('../views/HomeView.vue'), meta: { layout: 'public' } },
    { path: '/login', name: 'login', component: () => import('../views/LoginView.vue'), meta: { layout: 'public', guestOnly: true } },
    { path: '/register', name: 'register', component: () => import('../views/RegisterView.vue'), meta: { layout: 'public', guestOnly: true } },
    { path: '/dashboard', name: 'dashboard', component: () => import('../views/DashboardView.vue'), meta: { layout: 'student', requiresAuth: true } },
    { path: '/profile', name: 'profile', component: () => import('../views/ProfileView.vue'), meta: { layout: 'student', requiresAuth: true } },
    { path: '/admin', name: 'admin', component: () => import('../views/AdminView.vue'), meta: { layout: 'admin', requiresAuth: true, requiresAdmin: true } },
    { path: '/unavailable', name: 'unavailable', component: () => import('../views/UnavailableView.vue'), meta: { layout: 'public' } },
    { path: '/:pathMatch(.*)*', name: 'not-found', component: () => import('../views/NotFoundView.vue'), meta: { layout: 'public' } },
];

const router = createRouter({ history: createWebHistory(), routes, scrollBehavior: () => ({ top: 0 }) });

router.beforeEach(async (to) => {
    const auth = useAuthStore();
    if (auth.status === 'unknown') {
        try {
            await auth.loadCurrentUser();
        } catch {
            if (to.name !== 'unavailable') return { name: 'unavailable' };
        }
    }
    if (to.meta.requiresAuth && !auth.isAuthenticated) return { name: 'login', query: { redirect: to.fullPath } };
    if (to.meta.requiresAdmin && !auth.canAccessAdmin) return { name: 'dashboard', query: { denied: '1' } };
    if (to.meta.guestOnly && auth.isAuthenticated) return { name: 'dashboard' };
    return true;
});

export default router;

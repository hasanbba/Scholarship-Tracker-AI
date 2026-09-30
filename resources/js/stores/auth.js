import { defineStore } from 'pinia';
import { api, prepareCsrf } from '../services/api';

export const useAuthStore = defineStore('auth', {
    state: () => ({
        status: 'unknown',
        user: null,
        error: null,
    }),
    getters: {
        isAuthenticated: (state) => state.status === 'authenticated',
        canAccessAdmin: (state) => state.user?.permissions?.includes('admin.access') ?? false,
    },
    actions: {
        async loadCurrentUser() {
            this.status = 'loading';
            this.error = null;
            try {
                const { data } = await api.get('/api/v1/auth/me');
                this.user = data.data;
                this.status = 'authenticated';
                return this.user;
            } catch (error) {
                this.user = null;
                if (error.response?.status === 401) {
                    this.status = 'guest';
                    return null;
                }
                this.status = 'error';
                this.error = error.response?.data?.message || 'We could not check your session.';
                throw error;
            }
        },
        async login(credentials) {
            await prepareCsrf();
            const { data } = await api.post('/api/v1/auth/login', credentials);
            this.user = data.data;
            this.status = 'authenticated';
            this.error = null;
            return this.user;
        },
        async register(details) {
            await prepareCsrf();
            const { data } = await api.post('/api/v1/auth/register', details);
            this.user = data.data;
            this.status = 'authenticated';
            this.error = null;
            return this.user;
        },
        async logout() {
            await api.post('/api/v1/auth/logout');
            this.user = null;
            this.status = 'guest';
        },
    },
});

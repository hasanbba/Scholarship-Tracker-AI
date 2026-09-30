import axios from 'axios';

export const api = axios.create({
    baseURL: import.meta.env.VITE_API_BASE_URL || '',
    withCredentials: true,
    withXSRFToken: true,
    headers: {
        Accept: 'application/json',
        'X-Requested-With': 'XMLHttpRequest',
    },
});

export async function prepareCsrf() {
    await api.get('/sanctum/csrf-cookie');
}

export function errorMessage(error, fallback = 'Something went wrong. Please try again.') {
    return error?.response?.data?.message || fallback;
}

import axios from 'axios';

const api = axios.create({
    baseURL: '/api/pos',
    headers: { 'Accept': 'application/json', 'Content-Type': 'application/json' },
});

// Attach Sanctum token from localStorage on every request
api.interceptors.request.use((config) => {
    const token = localStorage.getItem('pos_token');
    if (token) config.headers['Authorization'] = `Bearer ${token}`;
    return config;
});

export default api;

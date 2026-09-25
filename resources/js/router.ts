import { createRouter, createWebHistory } from 'vue-router';
import LoginPage from './views/LoginPage.vue';
import MailPage from './views/MailPage.vue';

export const router = createRouter({
    history: createWebHistory(),
    routes: [
        { path: '/', redirect: '/mail' },
        { path: '/login', component: LoginPage },
        { path: '/mail/:view?', component: MailPage },
    ],
});

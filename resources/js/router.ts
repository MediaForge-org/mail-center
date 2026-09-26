import { createRouter, createWebHistory, type RouteRecordRaw } from 'vue-router';
import LoginPage from './views/LoginPage.vue';
import MailPage from './views/MailPage.vue';

export const routes: RouteRecordRaw[] = [
    { path: '/', redirect: '/mail/all' },
    { path: '/login', component: LoginPage },
    { path: '/mail', redirect: '/mail/all' },
    { path: '/mail/account/:accountId(\\d+)', component: MailPage },
    { path: '/mail/:view(all|inbox|unread|accounts)', component: MailPage },
    { path: '/mail/:pathMatch(.*)*', redirect: '/mail/all' },
];

export const router = createRouter({ history: createWebHistory(), routes });

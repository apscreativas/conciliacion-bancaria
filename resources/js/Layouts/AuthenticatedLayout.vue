<script setup lang="ts">
import { ref, watch, onMounted } from "vue";
import ApplicationLogo from "@/Components/ApplicationLogo.vue";
import Dropdown from "@/Components/Dropdown.vue";
import DropdownLink from "@/Components/DropdownLink.vue";
import SidebarLink from "@/Components/SidebarLink.vue";
import LanguageSwitcher from "@/Components/LanguageSwitcher.vue";
import { Link, router, usePage } from "@inertiajs/vue3";

const page = usePage();
const showingNavigationDropdown = ref(false);

const updateDateFilter = (key: 'month' | 'year', value: string) => {
    const currentUrl = new URL(window.location.href);
    currentUrl.searchParams.set(key, value.toString());
    router.visit(currentUrl.toString(), {
        preserveScroll: true,
        preserveState: true,
    });
};

const isDark = ref(false);

const toggleDarkMode = () => {
    isDark.value = !isDark.value;
    if (isDark.value) {
        document.documentElement.classList.add('dark');
        localStorage.setItem('theme', 'dark');
    } else {
        document.documentElement.classList.remove('dark');
        localStorage.setItem('theme', 'light');
    }
};

// Initialize
if (typeof window !== 'undefined') {
    const savedTheme = localStorage.getItem('theme');
    const systemDark = window.matchMedia('(prefers-color-scheme: dark)').matches;
    
    if (savedTheme === 'dark' || (!savedTheme && systemDark)) {
        isDark.value = true;
        document.documentElement.classList.add('dark');
    } else {
        isDark.value = false;
        document.documentElement.classList.remove('dark');
    }
}

// Auto-dismiss functionality
// Auto-dismiss functionality
const autoDismiss = () => {
    // Dismiss Success
    if (page.props.flash.success) {
        setTimeout(() => {
            page.props.flash.success = undefined;
        }, 3000);
    }
    // Dismiss Error
    if (page.props.flash.error) {
        setTimeout(() => {
            page.props.flash.error = undefined;
        }, 3000);
    }
    // Dismiss Toasts (if implemented as array)
    if (page.props.flash.toasts && page.props.flash.toasts.length > 0) {
        page.props.flash.toasts.forEach((toast: any, index: number) => {
             setTimeout(() => {
                const idx = page.props.flash.toasts?.indexOf(toast) ?? -1;
                if (idx > -1) {
                    page.props.flash.toasts?.splice(idx, 1);
                }
            }, 3000 + (index * 500)); // Stagger slightly
        });
    }
};

watch(() => page.props.flash, () => {
    autoDismiss();
}, { deep: true });

onMounted(() => {
    autoDismiss();
});

</script>

<template>
    <div class="flex h-screen bg-gray-100 dark:bg-gray-900 font-sans">
        <!-- Sidebar (Desktop & Mobile Wrapper) -->
        <aside
            :class="
                showingNavigationDropdown
                    ? 'translate-x-0'
                    : '-translate-x-full'
            "
            class="fixed inset-y-0 left-0 z-30 w-64 bg-gray-900 text-white transition-transform duration-300 ease-in-out lg:translate-x-0 lg:static lg:inset-auto flex flex-col"
        >
            <!-- Logo Area -->
            <div
                class="flex items-center justify-center h-16 bg-gray-900 border-b border-gray-800 shadow-md flex-shrink-0"
            >
                <Link
                    :href="route('dashboard')"
                    class="flex items-center space-x-2"
                >
                    <ApplicationLogo class="h-10 w-auto" />

                </Link>
            </div>

            <!-- Navigation Links -->
            <nav class="mt-5 px-2 space-y-1 flex-1 overflow-y-auto min-h-0">
                
                <!-- Period Selector (Filters) -->
                <div class="mb-6 px-2">
                    <div class="text-xs font-semibold text-gray-500 uppercase tracking-wider mb-2">
                        {{ $t('Periodo Contable') }}
                    </div>
                    <div class="bg-gray-800 rounded-lg p-2 border border-gray-700 shadow-sm">
                        <div class="grid grid-cols-2 gap-2">
                            <div class="relative">
                                <select 
                                    :value="$page.props.filters.month"
                                    @change="updateDateFilter('month', ($event.target as HTMLSelectElement).value)"
                                    class="block w-full text-xs bg-gray-900 border-none text-gray-300 rounded focus:ring-indigo-500 py-1.5 pl-2 pr-6 cursor-pointer appearance-none"
                                >
                                    <option v-for="m in 12" :key="m" :value="m">
                                        {{ new Date(0, m - 1).toLocaleString('es-MX', { month: 'short' }).toUpperCase() }}
                                    </option>
                                </select>
                            </div>
                            <div class="relative">
                                <select 
                                    :value="$page.props.filters.year"
                                    @change="updateDateFilter('year', ($event.target as HTMLSelectElement).value)"
                                    class="block w-full text-xs bg-gray-900 border-none text-white font-bold rounded focus:ring-indigo-500 py-1.5 pl-2 pr-6 cursor-pointer appearance-none"
                                >
                                    <option v-for="y in $page.props.available_years" :key="y" :value="y">
                                        {{ y }}
                                    </option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <SidebarLink
                    :href="route('dashboard')"
                    :active="route().current('dashboard')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M4 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2V6zM14 6a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2V6zM4 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2H6a2 2 0 01-2-2v-2zM14 16a2 2 0 012-2h2a2 2 0 012 2v2a2 2 0 01-2 2h-2a2 2 0 01-2-2v-2z"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Inicio') }}
                </SidebarLink>

                <!-- Dashboard ejecutivo (solo owner del team) -->
                <SidebarLink
                    v-if="$page.props.auth.user.manages_team"
                    :href="route('executive')"
                    :active="route().current('executive')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Dashboard ejecutivo') }}
                </SidebarLink>

                <!-- Placeholder for future links -->
                <SidebarLink
                    :href="route('reconciliation.index')"
                    :active="route().current('reconciliation.index')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 12h6m-6 4h6m2 5H7a2 2 0 01-2-2V5a2 2 0 012-2h5.586a1 1 0 01.707.293l5.414 5.414a1 1 0 01.293.707V19a2 2 0 01-2 2z"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Mesa de Trabajo') }}
                </SidebarLink>

                <SidebarLink
                    :href="route('reconciliation.history')"
                    :active="route().current('reconciliation.history')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 8v4l3 3m6-3a9 9 0 11-18 0 9 9 0 0118 0z"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Historial') }}
                </SidebarLink>

                <SidebarLink
                    :href="route('reconciliation.status')"
                    :active="route().current('reconciliation.status')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 19v-6a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2a2 2 0 002-2zm0 0V9a2 2 0 012-2h2a2 2 0 012 2v10m-6 0a2 2 0 002 2h2a2 2 0 002-2m0 0V5a2 2 0 012-2h2a2 2 0 012 2v14a2 2 0 01-2 2h-2a2 2 0 01-2-2z"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Reporte Estatus') }}
                </SidebarLink>

                <SidebarLink
                    :href="route('movements.index')"
                    :active="route().current('movements.*')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Movimientos') }}
                </SidebarLink>

                <SidebarLink
                    :href="route('invoices.index')"
                    :active="route().current('invoices.*')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M9 5H7a2 2 0 00-2 2v12a2 2 0 002 2h10a2 2 0 002-2V7a2 2 0 00-2-2h-2M9 5a2 2 0 002 2h2a2 2 0 002-2M9 5a2 2 0 012-2h2a2 2 0 012 2m-3 7h3m-3 4h3m-6-4h.01M9 16h.01"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Facturas') }}
                </SidebarLink>

                <SidebarLink
                    :href="route('expenses.index')"
                    :active="route().current('expenses.*')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M17 9V7a2 2 0 00-2-2H5a2 2 0 00-2 2v6a2 2 0 002 2h2m2 4h10a2 2 0 002-2v-6a2 2 0 00-2-2H9a2 2 0 00-2 2v6a2 2 0 002 2zm7-5a2 2 0 11-4 0 2 2 0 014 0z"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Egresos') }}
                </SidebarLink>

                <SidebarLink
                    :href="route('cash-income.index')"
                    :active="route().current('cash-income.*')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M12 8c-1.657 0-3 .895-3 2s1.343 2 3 2 3 .895 3 2-1.343 2-3 2m0-8c1.11 0 2.08.402 2.599 1M12 8V7m0 1v8m0 0v1m0-1c-1.11 0-2.08-.402-2.599-1M21 12a9 9 0 11-18 0 9 9 0 0118 0z"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Ingresos') }}
                </SidebarLink>

                <!-- Clientes: catálogo cliente→empresa (cualquier miembro) -->
                <SidebarLink
                    :href="route('clients.index')"
                    :active="route().current('clients.*')"
                >
                    <template #icon>
                        <svg
                            class="w-5 h-5"
                            fill="none"
                            stroke="currentColor"
                            viewBox="0 0 24 24"
                        >
                            <path
                                stroke-linecap="round"
                                stroke-linejoin="round"
                                stroke-width="2"
                                d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 00-3-3.87"
                            ></path>
                        </svg>
                    </template>
                    {{ $t('Clientes') }}
                </SidebarLink>

                <!-- Settings -->
                <div
                    class="pt-4 border-t border-gray-800 mt-4"
                >
                    <div
                        class="px-2 mb-2 text-xs font-semibold text-gray-500 uppercase tracking-wider"
                    >
                        {{ $t('Configuración') }}
                    </div>
                    <SidebarLink
                        :href="route('bank-formats.index')"
                        :active="route().current('bank-formats.*')"
                    >
                        <template #icon>
                            <svg class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 11H5m14 0a2 2 0 012 2v6a2 2 0 01-2 2H5a2 2 0 01-2-2v-6a2 2 0 012-2m14 0V9a2 2 0 00-2-2M5 11V9a2 2 0 012-2m0 0V5a2 2 0 012-2h6a2 2 0 012 2v2M7 7h10" />
                            </svg>
                        </template>
                        {{ $t('Formatos Bancarios') }}
                    </SidebarLink>
                    <SidebarLink
                        v-if="$page.props.auth.user.manages_team"
                        :href="route('settings.tolerance')"
                        :active="route().current('settings.tolerance')"
                    >
                        <template #icon>
                            <svg
                                class="w-5 h-5"
                                fill="none"
                                stroke="currentColor"
                                viewBox="0 0 24 24"
                            >
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M10.325 4.317c.426-1.756 2.924-1.756 3.35 0a1.724 1.724 0 002.573 1.066c1.543-.94 3.31.826 2.37 2.37a1.724 1.724 0 001.065 2.572c1.756.426 1.756 2.924 0 3.35a1.724 1.724 0 00-1.066 2.573c.94 1.543-.826 3.31-2.37 2.37a1.724 1.724 0 00-2.572 1.065c-.426 1.756-2.924 1.756-3.35 0a1.724 1.724 0 00-2.573-1.066c-1.543.94-3.31-.826-2.37-2.37a1.724 1.724 0 00-1.065-2.572c-1.756-.426-1.756-2.924 0-3.35a1.724 1.724 0 001.066-2.573c-.94-1.543.826-3.31 2.37-2.37.996.608 2.296.07 2.572-1.065z"
                                ></path>
                                <path
                                    stroke-linecap="round"
                                    stroke-linejoin="round"
                                    stroke-width="2"
                                    d="M15 12a3 3 0 11-6 0 3 3 0 016 0z"
                                ></path>
                            </svg>
                        </template>
                        {{ $t('Tolerancia') }}
                    </SidebarLink>
                    <SidebarLink
                        v-if="$page.props.auth.user.manages_team"
                        :href="route('settings.companies.index')"
                        :active="route().current('settings.companies.*')"
                    >
                        <template #icon>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M19 21V5a2 2 0 00-2-2H7a2 2 0 00-2 2v16m14 0H5m14 0h2M5 21H3m4-4h.01M9 9h.01M9 13h.01M13 13h.01M13 9h.01" />
                            </svg>
                        </template>
                        {{ $t('Empresas') }}
                    </SidebarLink>
                    <SidebarLink
                        v-if="$page.props.auth.user.manages_team"
                        :href="route('settings.categories.index')"
                        :active="route().current('settings.categories.*')"
                    >
                        <template #icon>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M7 7h.01M7 3h5a1.99 1.99 0 011.414.586l7 7a2 2 0 010 2.828l-7 7a2 2 0 01-2.828 0l-7-7A1.99 1.99 0 013 12V7a4 4 0 014-4z" />
                            </svg>
                        </template>
                        {{ $t('Categorías') }}
                    </SidebarLink>
                    <SidebarLink
                        v-if="$page.props.auth.user.manages_team"
                        :href="route('employees.index')"
                        :active="route().current('employees.*')"
                    >
                        <template #icon>
                            <svg class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M17 20h5v-2a4 4 0 00-3-3.87M9 20H4v-2a4 4 0 013-3.87m6-1.13a4 4 0 10-4-4 4 4 0 004 4zm6 0a4 4 0 00-3-3.87" />
                            </svg>
                        </template>
                        {{ $t('Empleados') }}
                    </SidebarLink>
                </div>
            </nav>

            <!-- User Area (Bottom Sidebar) -->
            <div class="flex-shrink-0 w-full border-t border-gray-800 p-4 bg-gray-900">
                <div class="flex flex-col space-y-4">
                    <!-- Controls -->
                    <div class="flex items-center justify-between">
                        <!-- Dark Mode Toggle -->
                        <button 
                            @click="toggleDarkMode"
                            class="p-2 text-gray-400 hover:text-white transition-colors rounded-full hover:bg-gray-800"
                            title="Toggle Dark Mode"
                        >
                            <svg v-if="isDark" class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M12 3v1m0 16v1m9-9h-1M4 12H3m15.364 6.364l-.707-.707M6.343 6.343l-.707-.707m12.728 0l-.707.707M6.343 17.657l-.707.707M16 12a4 4 0 11-8 0 4 4 0 018 0z"></path>
                            </svg>
                            <svg v-else class="w-5 h-5" fill="none" stroke="currentColor" viewBox="0 0 24 24">
                                <path stroke-linecap="round" stroke-linejoin="round" stroke-width="2" d="M20.354 15.354A9 9 0 018.646 3.646 9.003 9.003 0 0012 21a9.003 9.003 0 008.354-5.646z"></path>
                            </svg>
                        </button>

                        <!-- Language Switcher -->
                        <LanguageSwitcher />

                        <!-- Team Switcher -->
                        <Dropdown
                            align="right"
                            direction="up"
                            width="60"
                            v-if="$page.props.auth.user.current_team"
                        >
                            <template #trigger>
                                <button type="button" class="flex items-center text-xs font-medium text-gray-400 hover:text-white transition-colors">
                                    <span class="truncate max-w-[100px]">{{ $page.props.auth.user.current_team.name }}</span>
                                    <svg class="ml-1 h-3 w-3" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20" fill="currentColor">
                                        <path fill-rule="evenodd" d="M10 3a1 1 0 01.707.293l3 3a1 1 0 01-1.414 1.414L10 5.414 7.707 7.707a1 1 0 01-1.414-1.414l3-3A1 1 0 0110 3zm-3.707 9.293a1 1 0 011.414 0L10 14.586l2.293-2.293a1 1 0 011.414 1.414l-3 3a1 1 0 01-1.414 0l-3-3a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </template>

                            <template #content>
                                <div class="w-60">
                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                        {{ $t('Administrar Equipo') }}
                                    </div>
                                    <DropdownLink :href="route('teams.show')">
                                        {{ $t('Configuración del Equipo') }}
                                    </DropdownLink>
                                    <DropdownLink :href="route('teams.create')">
                                        {{ $t('+ Crear Nuevo Equipo') }}
                                    </DropdownLink>
                                    <div class="border-t border-gray-200 dark:border-gray-600"></div>
                                    <div class="block px-4 py-2 text-xs text-gray-400">
                                        {{ $t('Cambiar Equipo') }}
                                    </div>
                                    <div v-for="team in $page.props.auth.user.all_teams" :key="team.id">
                                        <form @submit.prevent="router.put(route('current-team.update'), { team_id: team.id })">
                                            <DropdownLink as="button">
                                                <div class="flex items-center">
                                                    <svg v-if="team.id == $page.props.auth.user.current_team_id" class="mr-2 h-5 w-5 text-green-400" xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                                    </svg>
                                                    <div class="truncate">{{ team.name }}</div>
                                                </div>
                                            </DropdownLink>
                                        </form>
                                    </div>
                                </div>
                            </template>
                        </Dropdown>
                    </div>

                    <!-- User Profile Dropdown -->
                    <Dropdown align="right" direction="up" width="48">
                        <template #trigger>
                            <button class="flex items-center w-full group focus:outline-none">
                                <div class="w-8 h-8 rounded-full bg-indigo-500 flex items-center justify-center text-sm font-bold text-white shadow-sm group-hover:ring-2 group-hover:ring-indigo-400 transition-all">
                                    {{ $page.props.auth.user.name.charAt(0) }}
                                </div>
                                <div class="ml-3 text-left">
                                    <p class="text-sm font-medium text-white group-hover:text-indigo-300 transition-colors">
                                        {{ $page.props.auth.user.name }}
                                    </p>
                                    <p class="text-xs text-gray-500 group-hover:text-gray-400">
                                        {{ $t('Ver Perfil') }}
                                    </p>
                                </div>
                            </button>
                        </template>

                        <template #content>
                            <DropdownLink :href="route('profile.edit')">
                                {{ $t('Perfil') }}
                            </DropdownLink>
                            <DropdownLink :href="route('logout')" method="post" as="button">
                                {{ $t('Cerrar Sesión') }}
                            </DropdownLink>
                        </template>
                    </Dropdown>
                </div>
            </div>
        </aside>

        <!-- Mobile Overlay -->
        <div
            v-if="showingNavigationDropdown"
            @click="showingNavigationDropdown = false"
            class="fixed inset-0 z-20 bg-black opacity-50 lg:hidden"
        ></div>

        <!-- Global Toast Notifications -->
        <div class="fixed top-6 left-1/2 transform -translate-x-1/2 z-50 space-y-2 w-full max-w-md pointer-events-none">
            
            <!-- Computed List of Toasts (Legacy + New Array) -->
            <TransitionQuery
                as="div" 
                class="flex flex-col gap-2 items-center w-full"
            >
                <!-- Render Legacy Success -->
                 <Transition
                    enter-active-class="transform ease-out duration-300 transition"
                    enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
                    enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
                    leave-active-class="transition ease-in duration-100"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-if="$page.props.flash.success" class="pointer-events-auto w-full max-w-md overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-black ring-opacity-5">
                        <div class="p-4">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <svg class="h-6 w-6 text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                </div>
                                <div class="ml-3 w-0 flex-1 pt-0.5">
                                    <p class="text-sm font-medium text-gray-900">Exitoso</p>
                                    <p class="mt-1 text-sm text-gray-500 leading-relaxed">{{ $page.props.flash.success }}</p>
                                </div>
                                <div class="ml-4 flex flex-shrink-0">
                                    <button @click="$page.props.flash.success = undefined" class="inline-flex rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none">
                                        <span class="sr-only">Close</span>
                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>

                <!-- Render Legacy Error -->
                <Transition
                    enter-active-class="transform ease-out duration-300 transition"
                    enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
                    enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
                    leave-active-class="transition ease-in duration-100"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                    <div v-if="$page.props.flash.error" class="pointer-events-auto w-full max-w-md overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-black ring-opacity-5">
                       <div class="p-4">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <svg class="h-6 w-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                </div>
                                <div class="ml-3 w-0 flex-1 pt-0.5">
                                    <p class="text-sm font-medium text-gray-900">Error</p>
                                    <p class="mt-1 text-sm text-gray-500 leading-relaxed">{{ $page.props.flash.error }}</p>
                                </div>
                                <div class="ml-4 flex flex-shrink-0">
                                    <button @click="$page.props.flash.error = undefined" class="inline-flex rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none">
                                        <span class="sr-only">Close</span>
                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>

                <!-- Render New Toasts Array -->
                <Transition
                    v-for="(toast, index) in $page.props.flash.toasts || []"
                    :key="index"
                    appear
                    enter-active-class="transform ease-out duration-300 transition"
                    enter-from-class="translate-y-2 opacity-0 sm:translate-y-0 sm:translate-x-2"
                    enter-to-class="translate-y-0 opacity-100 sm:translate-x-0"
                    leave-active-class="transition ease-in duration-100"
                    leave-from-class="opacity-100"
                    leave-to-class="opacity-0"
                >
                     <div class="pointer-events-auto w-full max-w-md overflow-hidden rounded-lg bg-white shadow-lg ring-1 ring-black ring-opacity-5 mb-2">
                        <div class="p-4">
                            <div class="flex items-start">
                                <div class="flex-shrink-0">
                                    <!-- Success Icon -->
                                    <svg v-if="toast.type === 'success'" class="h-6 w-6 text-green-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M9 12.75L11.25 15 15 9.75M21 12a9 9 0 11-18 0 9 9 0 0118 0z" />
                                    </svg>
                                    <!-- Error Icon -->
                                    <svg v-else-if="toast.type === 'error'" class="h-6 w-6 text-red-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m9-.75a9 9 0 11-18 0 9 9 0 0118 0zm-9 3.75h.008v.008H12v-.008z" />
                                    </svg>
                                    <!-- Warning Icon -->
                                    <svg v-else class="h-6 w-6 text-yellow-400" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor">
                                        <path stroke-linecap="round" stroke-linejoin="round" d="M12 9v3.75m-9.303 3.376c-.866 1.5.217 3.374 1.948 3.374h14.71c1.73 0 2.813-1.874 1.948-3.374L13.949 3.378c-.866-1.5-3.032-1.5-3.898 0L2.697 16.126zM12 15.75h.007v.008H12v-.008z" />
                                    </svg>
                                </div>
                                <div class="ml-3 w-0 flex-1 pt-0.5">
                                    <!-- Title based on type -->
                                    <p class="text-sm font-medium text-gray-900">
                                        {{ toast.type === 'success' ? 'Exitoso' : (toast.type === 'error' ? 'Error' : 'Atención') }}
                                    </p>
                                    <p class="mt-1 text-sm text-gray-500 leading-relaxed">{{ toast.message }}</p>
                                </div>
                                <div class="ml-4 flex flex-shrink-0">
                                    <button @click="$page.props.flash.toasts?.splice(index, 1)" class="inline-flex rounded-md bg-white text-gray-400 hover:text-gray-500 focus:outline-none">
                                        <span class="sr-only">Close</span>
                                        <svg class="h-5 w-5" viewBox="0 0 20 20" fill="currentColor"><path d="M6.28 5.22a.75.75 0 00-1.06 1.06L8.94 10l-3.72 3.72a.75.75 0 101.06 1.06L10 11.06l3.72 3.72a.75.75 0 101.06-1.06L11.06 10l3.72-3.72a.75.75 0 00-1.06-1.06L10 8.94 6.28 5.22z" /></svg>
                                    </button>
                                </div>
                            </div>
                        </div>
                    </div>
                </Transition>
            </TransitionQuery>
        </div>

        <!-- Main Content Wrapper -->
        <div class="flex-1 flex flex-col overflow-hidden">
            <!-- Top Header -->
            <header
                class="flex items-center justify-between h-16 px-6 bg-white dark:bg-gray-800 shadow-sm border-b border-gray-200 dark:border-gray-700"
            >
                <div class="flex items-center">
                    <!-- Hamburger Button (Mobile) -->
                    <button
                        @click="
                            showingNavigationDropdown =
                                !showingNavigationDropdown
                        "
                        class="text-gray-500 focus:outline-none lg:hidden"
                    >
                        <svg
                            class="w-6 h-6"
                            viewBox="0 0 24 24"
                            fill="none"
                            xmlns="http://www.w3.org/2000/svg"
                        >
                            <path
                                d="M4 6H20M4 12H20M4 18H11"
                                stroke="currentColor"
                                stroke-width="2"
                                stroke-linecap="round"
                                stroke-linejoin="round"
                            />
                        </svg>
                    </button>
                    <!-- Page Header Slot -->
                    <div class="ml-4 lg:ml-0">
                        <slot name="header" />
                    </div>
                </div>




            </header>

            <!-- Main Content Area -->
            <main
                class="flex-1 overflow-x-hidden overflow-y-auto bg-gray-100 dark:bg-gray-900 p-6"
            >
                <slot />
            </main>
        </div>
    </div>
</template>

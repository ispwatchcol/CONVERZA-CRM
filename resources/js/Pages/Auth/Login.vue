<script setup>
import { Head, useForm } from '@inertiajs/vue3';
import { ref } from 'vue';
import Logo from '@/Components/Logo.vue';

defineProps({
    status: String,
});

const form = useForm({
    email: '',
    password: '',
    remember: false,
});

const showPassword = ref(false);

const submit = () => {
    form.post(route('login'), {
        onFinish: () => form.reset('password'),
    });
};
</script>

<template>
    <Head title="Iniciar sesión" />

    <div class="min-h-screen flex flex-col md:flex-row bg-white">
        <!-- ── Left panel (brand) ─────────────────────────────────────────── -->
        <aside
            class="relative hidden md:flex md:w-1/2 lg:w-2/5 bg-sidebar text-white p-10 overflow-hidden"
        >
            <!-- Decorative blobs -->
            <div class="absolute -top-32 -right-32 w-96 h-96 rounded-full bg-accent/20 blur-3xl"></div>
            <div class="absolute -bottom-32 -left-32 w-[28rem] h-[28rem] rounded-full bg-accent-light/10 blur-3xl"></div>

            <!-- Subtle grid pattern -->
            <div
                class="absolute inset-0 opacity-[0.04]"
                :style="{
                    backgroundImage: 'linear-gradient(rgba(255,255,255,0.5) 1px, transparent 1px), linear-gradient(90deg, rgba(255,255,255,0.5) 1px, transparent 1px)',
                    backgroundSize: '32px 32px',
                }"
            ></div>

            <!-- Content -->
            <div class="relative z-10 flex flex-col justify-between w-full">
                <!-- Brand -->
                <div class="flex items-center space-x-3">
                    <Logo size="lg" />
                    <div>
                        <h1 class="text-2xl font-bold tracking-tight">CONVERZA</h1>
                        <p class="text-text-muted text-[11px] uppercase tracking-widest">CRM &amp; Chatbot</p>
                    </div>
                </div>

                <!-- Tagline -->
                <div class="space-y-5 my-12">
                    <h2 class="text-4xl lg:text-5xl font-bold leading-tight">
                        Centraliza tu<br />
                        conversación con<br />
                        <span class="text-accent">cada cliente</span>.
                    </h2>
                    <p class="text-text-muted text-base max-w-md leading-relaxed">
                        Gestiona contactos, equipos y mensajes de WhatsApp desde un solo lugar, con métricas en tiempo real y respuestas automatizadas.
                    </p>
                </div>

                <!-- Footer -->
                <div class="text-text-muted text-xs">
                    © {{ new Date().getFullYear() }} Converza. Todos los derechos reservados.
                </div>
            </div>
        </aside>

        <!-- ── Right panel (form) ─────────────────────────────────────────── -->
        <main class="flex-1 flex items-center justify-center p-6 sm:p-10 lg:p-16 bg-gray-50">
            <div class="w-full max-w-md">
                <!-- Mobile brand -->
                <div class="md:hidden flex items-center justify-center space-x-3 mb-10">
                    <Logo size="md" />
                    <h1 class="text-xl font-bold text-gray-900">CONVERZA</h1>
                </div>

                <div class="mb-8">
                    <h2 class="text-3xl font-bold text-gray-900 mb-2">Bienvenido de vuelta</h2>
                    <p class="text-gray-500 text-sm">Inicia sesión para acceder a tu panel.</p>
                </div>

                <!-- Status -->
                <div
                    v-if="status"
                    class="mb-4 px-4 py-3 rounded-lg bg-accent/10 border border-accent/30 text-accent text-sm"
                >
                    {{ status }}
                </div>

                <form @submit.prevent="submit" class="space-y-5">
                    <!-- Email -->
                    <div>
                        <label for="email" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Correo electrónico
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M21.75 6.75v10.5a2.25 2.25 0 01-2.25 2.25h-15a2.25 2.25 0 01-2.25-2.25V6.75m19.5 0A2.25 2.25 0 0019.5 4.5h-15a2.25 2.25 0 00-2.25 2.25m19.5 0v.243a2.25 2.25 0 01-1.07 1.916l-7.5 4.615a2.25 2.25 0 01-2.36 0L3.32 8.91a2.25 2.25 0 01-1.07-1.916V6.75" />
                                </svg>
                            </span>
                            <input
                                id="email"
                                v-model="form.email"
                                type="email"
                                autocomplete="username"
                                required
                                autofocus
                                placeholder="tu@correo.com"
                                class="block w-full pl-11 pr-4 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"
                                :class="{ 'border-red-400 focus:ring-red-400': form.errors.email }"
                            />
                        </div>
                        <p v-if="form.errors.email" class="mt-1.5 text-xs text-red-600">
                            {{ form.errors.email }}
                        </p>
                    </div>

                    <!-- Password -->
                    <div>
                        <label for="password" class="block text-sm font-medium text-gray-700 mb-1.5">
                            Contraseña
                        </label>
                        <div class="relative">
                            <span class="absolute inset-y-0 left-0 pl-3.5 flex items-center pointer-events-none">
                                <svg class="w-5 h-5 text-gray-400" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M16.5 10.5V6.75a4.5 4.5 0 10-9 0v3.75m-.75 11.25h10.5a2.25 2.25 0 002.25-2.25v-6.75a2.25 2.25 0 00-2.25-2.25H6.75a2.25 2.25 0 00-2.25 2.25v6.75a2.25 2.25 0 002.25 2.25z" />
                                </svg>
                            </span>
                            <input
                                id="password"
                                v-model="form.password"
                                :type="showPassword ? 'text' : 'password'"
                                autocomplete="current-password"
                                required
                                placeholder="••••••••"
                                class="block w-full pl-11 pr-11 py-3 border border-gray-300 rounded-xl text-sm bg-white text-gray-900 placeholder-gray-400 focus:outline-none focus:ring-2 focus:ring-accent focus:border-transparent transition"
                                :class="{ 'border-red-400 focus:ring-red-400': form.errors.password }"
                            />
                            <button
                                type="button"
                                @click="showPassword = !showPassword"
                                class="absolute inset-y-0 right-0 pr-3.5 flex items-center text-gray-400 hover:text-gray-600 transition"
                                :title="showPassword ? 'Ocultar contraseña' : 'Mostrar contraseña'"
                            >
                                <svg v-if="!showPassword" class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M2.036 12.322a1.012 1.012 0 010-.639C3.423 7.51 7.36 4.5 12 4.5c4.638 0 8.573 3.007 9.963 7.178.07.207.07.431 0 .639C20.577 16.49 16.64 19.5 12 19.5c-4.638 0-8.573-3.007-9.963-7.178z" />
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M15 12a3 3 0 11-6 0 3 3 0 016 0z" />
                                </svg>
                                <svg v-else class="w-5 h-5" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                                    <path stroke-linecap="round" stroke-linejoin="round" d="M3.98 8.223A10.477 10.477 0 001.934 12C3.226 16.338 7.244 19.5 12 19.5c.993 0 1.953-.138 2.863-.395M6.228 6.228A10.451 10.451 0 0112 4.5c4.756 0 8.773 3.162 10.065 7.498a10.522 10.522 0 01-4.293 5.774M6.228 6.228L3 3m3.228 3.228l3.65 3.65m7.894 7.894L21 21m-3.228-3.228l-3.65-3.65m0 0a3 3 0 10-4.243-4.243m4.242 4.242L9.88 9.88" />
                                </svg>
                            </button>
                        </div>
                        <p v-if="form.errors.password" class="mt-1.5 text-xs text-red-600">
                            {{ form.errors.password }}
                        </p>
                    </div>

                    <!-- Remember -->
                    <label class="flex items-center cursor-pointer select-none">
                        <input
                            v-model="form.remember"
                            type="checkbox"
                            class="w-4 h-4 rounded border-gray-300 text-accent focus:ring-accent focus:ring-offset-0"
                        />
                        <span class="ml-2 text-sm text-gray-700">Recordarme en este dispositivo</span>
                    </label>

                    <!-- Submit -->
                    <button
                        type="submit"
                        :disabled="form.processing"
                        class="w-full flex items-center justify-center py-3 px-4 rounded-xl bg-accent hover:bg-accent-hover text-white text-sm font-semibold shadow-sm hover:shadow-md focus:outline-none focus:ring-2 focus:ring-accent focus:ring-offset-2 disabled:opacity-60 disabled:cursor-not-allowed transition-all duration-200"
                    >
                        <svg v-if="form.processing" class="w-5 h-5 mr-2 animate-spin" fill="none" viewBox="0 0 24 24">
                            <circle class="opacity-25" cx="12" cy="12" r="10" stroke="currentColor" stroke-width="4"></circle>
                            <path class="opacity-75" fill="currentColor" d="M4 12a8 8 0 018-8V0C5.373 0 0 5.373 0 12h4zm2 5.291A7.962 7.962 0 014 12H0c0 3.042 1.135 5.824 3 7.938l3-2.647z"></path>
                        </svg>
                        {{ form.processing ? 'Ingresando…' : 'Iniciar sesión' }}
                    </button>
                </form>

                <p class="mt-8 text-center text-xs text-gray-400">
                    ¿Problemas para acceder? Contacta al administrador.
                </p>
            </div>
        </main>
    </div>
</template>

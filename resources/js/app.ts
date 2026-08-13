import { createInertiaApp, router, usePage } from '@inertiajs/vue3';
import { createI18n } from 'vue-i18n';
import { initializeTheme } from '@/composables/useAppearance';
import en from '@/lang/en';
import es from '@/lang/es';
import it from '@/lang/it';
import AppLayout from '@/layouts/AppLayout.vue';
import AuthLayout from '@/layouts/AuthLayout.vue';
import SettingsLayout from '@/layouts/settings/Layout.vue';
import { initializeFlashToast } from '@/lib/flashToast';

const appName = import.meta.env.VITE_APP_NAME || 'Laravel';

function createAppI18n(locale: string) {
    return createI18n<typeof en, string, false>({
        legacy: false,
        globalInjection: true,
        locale,
        fallbackLocale: 'en',
        messages: { en, it, es },
    });
}

let i18n: ReturnType<typeof createAppI18n> | undefined;

createInertiaApp({
    title: (title) => (title ? `${title} - ${appName}` : appName),
    layout: (name) => {
        switch (true) {
            case name === 'Welcome':
                return null;
            case name.startsWith('auth/'):
                return AuthLayout;
            case name.startsWith('settings/'):
                return [AppLayout, SettingsLayout];
            default:
                return AppLayout;
        }
    },
    withApp(app, { page }) {
        i18n = createAppI18n(page.props.locale);

        app.use(i18n);
    },
    progress: {
        color: '#4B5563',
    },
});

// This will set light / dark mode on page load...
initializeTheme();

// This will listen for flash toast data from the server...
initializeFlashToast();

// The Vue app instance (and therefore the i18n instance) stays alive across
// Inertia SPA navigations, so re-sync the active locale from the latest page
// props after every navigation (e.g. logging out and back in as a user with
// a different stored locale).
router.on('navigate', () => {
    const locale = usePage().props.locale;

    if (i18n && locale) {
        i18n.global.locale.value = locale;
    }
});

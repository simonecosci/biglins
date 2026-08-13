import type { MessageSchema } from '@/lang/en';

const messages: MessageSchema = {
    common: {
        actions: {
            save: 'Guardar',
            cancel: 'Cancelar',
            edit: 'Editar',
            addRow: 'Añadir fila',
            previous: 'Anterior',
            next: 'Siguiente',
        },
        fields: {
            name: 'Nombre',
            email: 'Correo electrónico',
            address: 'Dirección',
            city: 'Ciudad',
            zip: 'Código postal',
            country: 'País',
            selectCountry: 'Selecciona un país',
            phone: 'Número de teléfono',
        },
    },
    appearance: {
        light: 'Claro',
        dark: 'Oscuro',
        system: 'Sistema',
    },
    settings: {
        title: 'Configuración',
        description: 'Gestiona tu perfil y la configuración de la cuenta',
        nav: {
            profile: 'Perfil',
            security: 'Seguridad',
            appearance: 'Apariencia',
            language: 'Idioma',
        },
        language: {
            title: 'Configuración de idioma',
            description: 'Elige el idioma utilizado en toda la aplicación',
            label: 'Idioma',
            options: {
                en: 'English',
                it: 'Italiano',
                es: 'Español',
            },
        },
    },
};

export default messages;

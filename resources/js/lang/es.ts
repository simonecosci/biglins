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
};

export default messages;

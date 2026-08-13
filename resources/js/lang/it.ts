import type { MessageSchema } from '@/lang/en';

const messages: MessageSchema = {
    common: {
        actions: {
            save: 'Salva',
            cancel: 'Annulla',
            edit: 'Modifica',
            addRow: 'Aggiungi riga',
            previous: 'Precedente',
            next: 'Successivo',
        },
        fields: {
            name: 'Nome',
            email: 'Indirizzo email',
            address: 'Indirizzo',
            city: 'Città',
            zip: 'CAP',
            country: 'Paese',
            selectCountry: 'Seleziona un paese',
            phone: 'Numero di telefono',
        },
    },
    appearance: {
        light: 'Chiaro',
        dark: 'Scuro',
        system: 'Sistema',
    },
};

export default messages;

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
    settings: {
        title: 'Impostazioni',
        description: "Gestisci il profilo e le impostazioni dell'account",
        nav: {
            profile: 'Profilo',
            security: 'Sicurezza',
            appearance: 'Aspetto',
            language: 'Lingua',
        },
        language: {
            title: 'Impostazioni lingua',
            description: "Scegli la lingua utilizzata in tutta l'applicazione",
            label: 'Lingua',
            options: {
                en: 'English',
                it: 'Italiano',
                es: 'Español',
            },
        },
    },
};

export default messages;

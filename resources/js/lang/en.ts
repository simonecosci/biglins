const messages = {
    common: {
        actions: {
            save: 'Save',
            cancel: 'Cancel',
            edit: 'Edit',
            addRow: 'Add row',
            previous: 'Previous',
            next: 'Next',
        },
        fields: {
            name: 'Name',
            email: 'Email address',
            address: 'Address',
            city: 'City',
            zip: 'ZIP code',
            country: 'Country',
            selectCountry: 'Select a country',
            phone: 'Phone number',
        },
    },
    appearance: {
        light: 'Light',
        dark: 'Dark',
        system: 'System',
    },
};

export default messages;
export type MessageSchema = typeof messages;

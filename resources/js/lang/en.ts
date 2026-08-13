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
    settings: {
        title: 'Settings',
        description: 'Manage your profile and account settings',
        nav: {
            profile: 'Profile',
            security: 'Security',
            appearance: 'Appearance',
            language: 'Language',
        },
        language: {
            title: 'Language settings',
            description: 'Choose the language used across the application',
            label: 'Language',
            options: {
                en: 'English',
                it: 'Italiano',
                es: 'Español',
            },
        },
    },
};

export default messages;
export type MessageSchema = typeof messages;

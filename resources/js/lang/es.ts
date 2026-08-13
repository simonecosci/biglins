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
        profile: {
            title: 'Perfil',
            pageTitle: 'Configuración de perfil',
            description:
                'Actualiza tu nombre y dirección de correo electrónico',
            namePlaceholder: 'Nombre completo',
            emailPlaceholder: 'Correo electrónico',
            unverified:
                'Tu dirección de correo electrónico no está verificada.',
            resendLink:
                'Haz clic aquí para reenviar el correo de verificación.',
            verificationSent:
                'Se ha enviado un nuevo enlace de verificación a tu dirección de correo electrónico.',
        },
        security: {
            title: 'Actualizar contraseña',
            pageTitle: 'Configuración de seguridad',
            description:
                'Asegúrate de que tu cuenta use una contraseña larga y aleatoria para mantenerse segura',
            currentPassword: 'Contraseña actual',
            currentPasswordPlaceholder: 'Contraseña actual',
            newPassword: 'Nueva contraseña',
            newPasswordPlaceholder: 'Nueva contraseña',
            confirmPassword: 'Confirmar contraseña',
            confirmPasswordPlaceholder: 'Confirmar contraseña',
        },
        deleteAccount: {
            title: 'Eliminar cuenta',
            description: 'Elimina tu cuenta y todos sus recursos',
            warningTitle: 'Advertencia',
            warningBody:
                'Procede con precaución, esta acción no se puede deshacer.',
            deleteButton: 'Eliminar cuenta',
            confirmTitle: '¿Seguro que quieres eliminar tu cuenta?',
            confirmDescription:
                'Una vez eliminada tu cuenta, todos sus recursos y datos también se eliminarán de forma permanente. Introduce tu contraseña para confirmar que deseas eliminar tu cuenta de forma permanente.',
            passwordLabel: 'Contraseña',
            passwordPlaceholder: 'Contraseña',
        },
        twoFactor: {
            title: 'Autenticación de dos factores',
            description:
                'Gestiona la configuración de tu autenticación de dos factores',
            disabledIntro:
                'Cuando actives la autenticación de dos factores, se te pedirá un PIN seguro durante el inicio de sesión. Este PIN se puede obtener desde una aplicación TOTP en tu teléfono.',
            continueSetup: 'Continuar configuración',
            enable: 'Activar 2FA',
            enabledIntro:
                'Se te pedirá un PIN seguro y aleatorio durante el inicio de sesión, que puedes obtener desde la aplicación TOTP en tu teléfono.',
            disable: 'Desactivar 2FA',
        },
        passkeys: {
            title: 'Passkeys',
            description:
                'Gestiona tus passkeys para iniciar sesión sin contraseña',
            emptyTitle: 'Aún no hay passkeys',
            emptyDescription:
                'Añade una passkey para iniciar sesión sin contraseña',
        },
    },
    passwordInput: {
        show: 'Mostrar contraseña',
        hide: 'Ocultar contraseña',
    },
    nav: {
        dashboard: 'Panel',
        customers: 'Clientes',
        companies: 'Empresas',
        invoices: 'Facturas',
        products: 'Productos',
        countries: 'Países',
        repository: 'Repositorio',
        documentation: 'Documentación',
    },
    dashboard: {
        title: 'Panel',
    },
    welcome: {
        description:
            'Aplicación de facturación para autónomos: registro de clientes, emisión de facturas con líneas/IVA, numeración secuencial automática, vista previa y generación de PDF, duplicación de facturas.',
        readDocsPrefix: 'Lee la',
        documentation: 'Documentación',
        dashboard: 'Panel',
        logIn: 'Iniciar sesión',
        register: 'Registrarse',
    },
    auth: {
        login: {
            layoutTitle: 'Inicia sesión en tu cuenta',
            layoutDescription:
                'Introduce tu correo y contraseña para iniciar sesión',
            headTitle: 'Iniciar sesión',
            email: 'Correo electrónico',
            password: 'Contraseña',
            forgotPassword: '¿Olvidaste tu contraseña?',
            remember: 'Recuérdame',
            submit: 'Iniciar sesión',
            noAccount: '¿No tienes una cuenta?',
            signUp: 'Regístrate',
            passkey: 'Iniciar sesión con una passkey',
            passkeyLoading: 'Autenticando...',
            passkeySeparator: 'O continúa con el correo electrónico',
        },
        register: {
            layoutTitle: 'Crea una cuenta',
            layoutDescription: 'Introduce tus datos para crear tu cuenta',
            headTitle: 'Registrarse',
            name: 'Nombre',
            namePlaceholder: 'Nombre completo',
            email: 'Correo electrónico',
            password: 'Contraseña',
            confirmPassword: 'Confirmar contraseña',
            submit: 'Crear cuenta',
            haveAccount: '¿Ya tienes una cuenta?',
            logIn: 'Iniciar sesión',
        },
        forgotPassword: {
            layoutTitle: 'Contraseña olvidada',
            layoutDescription:
                'Introduce tu correo para recibir el enlace de restablecimiento',
            headTitle: 'Contraseña olvidada',
            email: 'Correo electrónico',
            submit: 'Enviar enlace de restablecimiento',
            returnPrefix: 'O, vuelve a',
            logIn: 'iniciar sesión',
        },
        resetPassword: {
            layoutTitle: 'Restablecer contraseña',
            layoutDescription: 'Introduce tu nueva contraseña a continuación',
            headTitle: 'Restablecer contraseña',
            email: 'Correo electrónico',
            password: 'Contraseña',
            confirmPassword: 'Confirmar contraseña',
            submit: 'Restablecer contraseña',
        },
        confirmPassword: {
            layoutTitle: 'Confirmar contraseña',
            layoutDescription:
                'Esta es un área segura de la aplicación. Confirma tu contraseña antes de continuar.',
            headTitle: 'Confirmar contraseña',
            passkeyLabel: 'Confirmar con passkey',
            passkeyLoading: 'Confirmando...',
            passkeySeparator: 'O confirma con la contraseña',
            password: 'Contraseña',
            submit: 'Confirmar contraseña',
        },
        twoFactorChallenge: {
            headTitle: 'Autenticación de dos factores',
            recoveryTitle: 'Código de recuperación',
            recoveryDescription:
                'Confirma el acceso a tu cuenta introduciendo uno de tus códigos de recuperación de emergencia.',
            recoveryButton: 'iniciar sesión con un código de autenticación',
            authTitle: 'Código de autenticación',
            authDescription:
                'Introduce el código de autenticación proporcionado por tu aplicación autenticadora.',
            authButton: 'iniciar sesión con un código de recuperación',
            recoveryPlaceholder: 'Introduce el código de recuperación',
            continue: 'Continuar',
            orYouCan: 'o puedes',
        },
        verifyEmail: {
            layoutTitle: 'Verificación de correo',
            layoutDescription:
                'Verifica tu dirección de correo haciendo clic en el enlace que te acabamos de enviar.',
            headTitle: 'Verificación de correo',
            sent: 'Se ha enviado un nuevo enlace de verificación a la dirección de correo que proporcionaste durante el registro.',
            resend: 'Reenviar correo de verificación',
            logout: 'Cerrar sesión',
        },
    },
    companies: {
        index: {
            title: 'Empresas',
            description: 'Gestiona las empresas que pueden emitir facturas',
            newButton: 'Nueva empresa',
            searchPlaceholder: 'Buscar por nombre...',
            columns: {
                name: 'Nombre',
                city: 'Ciudad',
                country: 'País',
                email: 'Correo electrónico',
                default: 'Predeterminada',
            },
            yes: 'Sí',
            empty: 'No se encontraron empresas.',
        },
        create: {
            title: 'Nueva empresa',
            description: 'Añade una empresa emisora al registro',
            namePlaceholder: 'Nombre de la empresa',
            taxId: 'NIF',
            taxIdPlaceholder: 'Número de identificación fiscal',
            addressPlaceholder: 'Dirección',
            zipPlaceholder: 'Código postal',
            cityPlaceholder: 'Ciudad',
            emailPlaceholder: 'Correo electrónico',
            phonePlaceholder: 'Número de teléfono',
            iban: 'IBAN',
            ibanPlaceholder: 'IBAN de la cuenta bancaria',
            logo: 'Logotipo',
            defaultCompany: 'Empresa predeterminada para nuevas facturas',
        },
        edit: {
            title: 'Editar empresa',
            description: 'Actualizar {name}',
            currentLogoAlt: 'Logotipo actual',
            removeLogo: 'Eliminar logotipo actual',
            confirmDelete:
                '¿Eliminar esta empresa? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar empresa',
        },
    },
    countries: {
        index: {
            title: 'Países',
            description: 'Gestiona los países disponibles para los clientes',
            newButton: 'Nuevo país',
            searchPlaceholder: 'Buscar países...',
            column: 'Nombre',
            empty: 'No se encontraron países.',
        },
        create: {
            title: 'Nuevo país',
            description: 'Añade un país a la lista disponible para los clientes',
            namePlaceholder: 'Nombre del país',
        },
        edit: {
            title: 'Editar país',
            description: 'Actualizar {name}',
            confirmDelete: '¿Eliminar este país? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar país',
        },
    },
    customers: {
        index: {
            title: 'Clientes',
            description: 'Gestiona tu registro de clientes',
            newButton: 'Nuevo cliente',
            searchPlaceholder: 'Buscar por nombre o correo...',
            columns: { name: 'Nombre', city: 'Ciudad', country: 'País', email: 'Correo electrónico' },
            empty: 'No se encontraron clientes.',
        },
        create: {
            title: 'Nuevo cliente',
            description: 'Añade un cliente al registro',
            namePlaceholder: 'Nombre del cliente',
            addressPlaceholder: 'Dirección',
            zipPlaceholder: 'Código postal',
            cityPlaceholder: 'Ciudad',
            stateProvince: 'Estado / Provincia',
            stateProvincePlaceholder: 'Estado o provincia',
            emailPlaceholder: 'Correo electrónico',
            website: 'Sitio web',
            phonePlaceholder: 'Número de teléfono',
            taxId: 'NIF',
            taxIdPlaceholder: 'Número de identificación fiscal',
        },
        edit: {
            title: 'Editar cliente',
            description: 'Actualizar {name}',
            confirmDelete: '¿Eliminar este cliente? Esta acción no se puede deshacer.',
            deleteButton: 'Eliminar cliente',
        },
    },
};

export default messages;

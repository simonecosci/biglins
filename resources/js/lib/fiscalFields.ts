export type FiscalField = {
    key: string;
    type: 'text' | 'email' | 'number' | 'select';
    options?: string[];
};

const italianTaxRegimes = [
    'RF01',
    'RF02',
    'RF04',
    'RF05',
    'RF06',
    'RF07',
    'RF08',
    'RF09',
    'RF10',
    'RF11',
    'RF12',
    'RF13',
    'RF14',
    'RF15',
    'RF16',
    'RF17',
    'RF18',
    'RF19',
];

const fields: Record<
    string,
    { company: FiscalField[]; customer: FiscalField[] }
> = {
    IT: {
        company: [
            { key: 'tax_regime', type: 'select', options: italianTaxRegimes },
            { key: 'rea_office', type: 'text' },
            { key: 'rea_number', type: 'text' },
            { key: 'share_capital', type: 'number' },
            {
                key: 'liquidation_status',
                type: 'select',
                options: ['LS', 'LN'],
            },
        ],
        customer: [
            { key: 'recipient_code', type: 'text' },
            { key: 'pec', type: 'email' },
        ],
    },
    ES: {
        company: [{ key: 'special_regime', type: 'text' }],
        customer: [
            {
                key: 'id_type',
                type: 'select',
                options: ['02', '03', '04', '05', '06', '07'],
            },
        ],
    },
};

export function fiscalFieldsFor(
    isoCode: string | null | undefined,
    kind: 'company' | 'customer',
): FiscalField[] {
    return (isoCode && fields[isoCode]?.[kind]) || [];
}

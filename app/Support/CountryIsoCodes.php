<?php

namespace App\Support;

/**
 * ISO 3166-1 alpha-2 codes for the country names shipped by CountrySeeder.
 */
class CountryIsoCodes
{
    /**
     * @var array<string, string>
     */
    public const BY_NAME = [
        'Afghanistan' => 'AF', 'Albania' => 'AL', 'Algeria' => 'DZ', 'Andorra' => 'AD', 'Angola' => 'AO',
        'Antigua and Barbuda' => 'AG', 'Argentina' => 'AR', 'Armenia' => 'AM', 'Australia' => 'AU', 'Austria' => 'AT',
        'Azerbaijan' => 'AZ', 'Bahamas' => 'BS', 'Bahrain' => 'BH', 'Bangladesh' => 'BD', 'Barbados' => 'BB',
        'Belarus' => 'BY', 'Belgium' => 'BE', 'Belize' => 'BZ', 'Benin' => 'BJ', 'Bhutan' => 'BT',
        'Bolivia' => 'BO', 'Bosnia and Herzegovina' => 'BA', 'Botswana' => 'BW', 'Brazil' => 'BR', 'Brunei' => 'BN',
        'Bulgaria' => 'BG', 'Burkina Faso' => 'BF', 'Burundi' => 'BI', 'Cambodia' => 'KH', 'Cameroon' => 'CM',
        'Canada' => 'CA', 'Cape Verde' => 'CV', 'Central African Republic' => 'CF', 'Chad' => 'TD', 'Chile' => 'CL',
        'China' => 'CN', 'Colombia' => 'CO', 'Comoros' => 'KM', 'Costa Rica' => 'CR', 'Croatia' => 'HR',
        'Cuba' => 'CU', 'Cyprus' => 'CY', 'Czech Republic' => 'CZ', 'Democratic Republic of the Congo' => 'CD', 'Denmark' => 'DK',
        'Djibouti' => 'DJ', 'Dominica' => 'DM', 'Dominican Republic' => 'DO', 'East Timor' => 'TL', 'Ecuador' => 'EC',
        'Egypt' => 'EG', 'El Salvador' => 'SV', 'Equatorial Guinea' => 'GQ', 'Eritrea' => 'ER', 'Estonia' => 'EE',
        'Eswatini' => 'SZ', 'Ethiopia' => 'ET', 'Fiji' => 'FJ', 'Finland' => 'FI', 'France' => 'FR',
        'Gabon' => 'GA', 'Gambia' => 'GM', 'Georgia' => 'GE', 'Germany' => 'DE', 'Ghana' => 'GH',
        'Greece' => 'GR', 'Grenada' => 'GD', 'Guatemala' => 'GT', 'Guinea' => 'GN', 'Guinea-Bissau' => 'GW',
        'Guyana' => 'GY', 'Haiti' => 'HT', 'Honduras' => 'HN', 'Hungary' => 'HU', 'Iceland' => 'IS',
        'India' => 'IN', 'Indonesia' => 'ID', 'Iran' => 'IR', 'Iraq' => 'IQ', 'Ireland' => 'IE',
        'Israel' => 'IL', 'Italy' => 'IT', 'Ivory Coast' => 'CI', 'Jamaica' => 'JM', 'Japan' => 'JP',
        'Jordan' => 'JO', 'Kazakhstan' => 'KZ', 'Kenya' => 'KE', 'Kiribati' => 'KI', 'Kosovo' => 'XK',
        'Kuwait' => 'KW', 'Kyrgyzstan' => 'KG', 'Laos' => 'LA', 'Latvia' => 'LV', 'Lebanon' => 'LB',
        'Lesotho' => 'LS', 'Liberia' => 'LR', 'Libya' => 'LY', 'Liechtenstein' => 'LI', 'Lithuania' => 'LT',
        'Luxembourg' => 'LU', 'Madagascar' => 'MG', 'Malawi' => 'MW', 'Malaysia' => 'MY', 'Maldives' => 'MV',
        'Mali' => 'ML', 'Malta' => 'MT', 'Marshall Islands' => 'MH', 'Mauritania' => 'MR', 'Mauritius' => 'MU',
        'Mexico' => 'MX', 'Micronesia' => 'FM', 'Moldova' => 'MD', 'Monaco' => 'MC', 'Mongolia' => 'MN',
        'Montenegro' => 'ME', 'Morocco' => 'MA', 'Mozambique' => 'MZ', 'Myanmar (Burma)' => 'MM', 'Namibia' => 'NA',
        'Nauru' => 'NR', 'Nepal' => 'NP', 'Netherlands' => 'NL', 'New Zealand' => 'NZ', 'Nicaragua' => 'NI',
        'Niger' => 'NE', 'Nigeria' => 'NG', 'North Korea' => 'KP', 'North Macedonia' => 'MK', 'Norway' => 'NO',
        'Oman' => 'OM', 'Pakistan' => 'PK', 'Palau' => 'PW', 'Palestine' => 'PS', 'Panama' => 'PA',
        'Papua New Guinea' => 'PG', 'Paraguay' => 'PY', 'Peru' => 'PE', 'Philippines' => 'PH', 'Poland' => 'PL',
        'Portugal' => 'PT', 'Qatar' => 'QA', 'Republic of the Congo' => 'CG', 'Romania' => 'RO', 'Rwanda' => 'RW',
        'Russia' => 'RU', 'Saint Kitts and Nevis' => 'KN', 'Saint Lucia' => 'LC', 'Saint Vincent and the Grenadines' => 'VC', 'Samoa' => 'WS',
        'San Marino' => 'SM', 'São Tomé and Príncipe' => 'ST', 'Saudi Arabia' => 'SA', 'Senegal' => 'SN', 'Serbia' => 'RS',
        'Seychelles' => 'SC', 'Sierra Leone' => 'SL', 'Singapore' => 'SG', 'Slovakia' => 'SK', 'Slovenia' => 'SI',
        'Somalia' => 'SO', 'South Africa' => 'ZA', 'South Korea' => 'KR', 'South Sudan' => 'SS', 'Spain' => 'ES',
        'Sri Lanka' => 'LK', 'Sudan' => 'SD', 'Suriname' => 'SR', 'Sweden' => 'SE', 'Switzerland' => 'CH',
        'Syria' => 'SY', 'Tajikistan' => 'TJ', 'Tanzania' => 'TZ', 'Thailand' => 'TH', 'Togo' => 'TG',
        'Tonga' => 'TO', 'Trinidad and Tobago' => 'TT', 'Tunisia' => 'TN', 'Turkey' => 'TR', 'Turkmenistan' => 'TM',
        'Tuvalu' => 'TV', 'Uganda' => 'UG', 'Ukraine' => 'UA', 'United Arab Emirates' => 'AE', 'United Kingdom' => 'GB',
        'United States of America' => 'US', 'Uruguay' => 'UY', 'Uzbekistan' => 'UZ', 'Vanuatu' => 'VU', 'Vatican City' => 'VA',
        'Venezuela' => 'VE', 'Vietnam' => 'VN', 'Yemen' => 'YE', 'Zambia' => 'ZM', 'Zimbabwe' => 'ZW',
    ];

    public static function forName(string $name): ?string
    {
        return self::BY_NAME[$name] ?? null;
    }
}

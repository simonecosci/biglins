<?php

namespace Database\Seeders;

use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class CountrySeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the countries table with the standard list of world countries.
     */
    public function run(): void
    {
        $names = [
            'Afghanistan', 'Albania', 'Algeria', 'Andorra', 'Angola',
            'Antigua and Barbuda', 'Argentina', 'Armenia', 'Australia', 'Austria',
            'Azerbaijan', 'Bahamas', 'Bahrain', 'Bangladesh', 'Barbados',
            'Belarus', 'Belgium', 'Belize', 'Benin', 'Bhutan',
            'Bolivia', 'Bosnia and Herzegovina', 'Botswana', 'Brazil', 'Brunei',
            'Bulgaria', 'Burkina Faso', 'Burundi', 'Cambodia', 'Cameroon',
            'Canada', 'Cape Verde', 'Central African Republic', 'Chad', 'Chile',
            'China', 'Colombia', 'Comoros', 'Costa Rica', 'Croatia',
            'Cuba', 'Cyprus', 'Czech Republic', 'Democratic Republic of the Congo', 'Denmark',
            'Djibouti', 'Dominica', 'Dominican Republic', 'East Timor', 'Ecuador',
            'Egypt', 'El Salvador', 'Equatorial Guinea', 'Eritrea', 'Estonia',
            'Eswatini', 'Ethiopia', 'Fiji', 'Finland', 'France',
            'Gabon', 'Gambia', 'Georgia', 'Germany', 'Ghana',
            'Greece', 'Grenada', 'Guatemala', 'Guinea', 'Guinea-Bissau',
            'Guyana', 'Haiti', 'Honduras', 'Hungary', 'Iceland',
            'India', 'Indonesia', 'Iran', 'Iraq', 'Ireland',
            'Israel', 'Italy', 'Ivory Coast', 'Jamaica', 'Japan',
            'Jordan', 'Kazakhstan', 'Kenya', 'Kiribati', 'Kosovo',
            'Kuwait', 'Kyrgyzstan', 'Laos', 'Latvia', 'Lebanon',
            'Lesotho', 'Liberia', 'Libya', 'Liechtenstein', 'Lithuania',
            'Luxembourg', 'Madagascar', 'Malawi', 'Malaysia', 'Maldives',
            'Mali', 'Malta', 'Marshall Islands', 'Mauritania', 'Mauritius',
            'Mexico', 'Micronesia', 'Moldova', 'Monaco', 'Mongolia',
            'Montenegro', 'Morocco', 'Mozambique', 'Myanmar (Burma)', 'Namibia',
            'Nauru', 'Nepal', 'Netherlands', 'New Zealand', 'Nicaragua',
            'Niger', 'Nigeria', 'North Korea', 'North Macedonia', 'Norway',
            'Oman', 'Pakistan', 'Palau', 'Palestine', 'Panama',
            'Papua New Guinea', 'Paraguay', 'Peru', 'Philippines', 'Poland',
            'Portugal', 'Qatar', 'Republic of the Congo', 'Romania', 'Rwanda',
            'Russia', 'Saint Kitts and Nevis', 'Saint Lucia', 'Saint Vincent and the Grenadines', 'Samoa',
            'San Marino', 'São Tomé and Príncipe', 'Saudi Arabia', 'Senegal', 'Serbia',
            'Seychelles', 'Sierra Leone', 'Singapore', 'Slovakia', 'Slovenia',
            'Somalia', 'South Africa', 'South Korea', 'South Sudan', 'Spain',
            'Sri Lanka', 'Sudan', 'Suriname', 'Sweden', 'Switzerland',
            'Syria', 'Tajikistan', 'Tanzania', 'Thailand', 'Togo',
            'Tonga', 'Trinidad and Tobago', 'Tunisia', 'Turkey', 'Turkmenistan',
            'Tuvalu', 'Uganda', 'Ukraine', 'United Arab Emirates', 'United Kingdom',
            'United States of America', 'Uruguay', 'Uzbekistan', 'Vanuatu', 'Vatican City',
            'Venezuela', 'Vietnam', 'Yemen', 'Zambia', 'Zimbabwe',
        ];

        $now = now();

        DB::table('countries')->insert(array_map(
            static fn (string $name): array => [
                'id' => (string) Str::uuid(),
                'name' => $name,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $names,
        ));
    }
}

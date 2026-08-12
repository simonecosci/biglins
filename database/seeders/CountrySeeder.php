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
            'Antigua e Barbuda', 'Arabia Saudita', 'Argentina', 'Armenia', 'Australia',
            'Austria', 'Azerbaigian', 'Bahamas', 'Bahrein', 'Bangladesh',
            'Barbados', 'Belgio', 'Belize', 'Benin', 'Bhutan',
            'Bielorussia', 'Birmania (Myanmar)', 'Bolivia', 'Bosnia ed Erzegovina', 'Botswana',
            'Brasile', 'Brunei', 'Bulgaria', 'Burkina Faso', 'Burundi',
            'Cambogia', 'Camerun', 'Canada', 'Capo Verde', 'Ciad',
            'Cile', 'Cina', 'Cipro', 'Città del Vaticano', 'Colombia',
            'Comore', 'Corea del Nord', 'Corea del Sud', "Costa d'Avorio", 'Costa Rica',
            'Croazia', 'Cuba', 'Danimarca', 'Dominica', 'Ecuador',
            'Egitto', 'El Salvador', 'Emirati Arabi Uniti', 'Eritrea', 'Estonia',
            'Eswatini', 'Etiopia', 'Figi', 'Filippine', 'Finlandia',
            'Francia', 'Gabon', 'Gambia', 'Georgia', 'Germania',
            'Ghana', 'Giamaica', 'Giappone', 'Gibuti', 'Giordania',
            'Grecia', 'Grenada', 'Guatemala', 'Guinea', 'Guinea-Bissau',
            'Guinea Equatoriale', 'Guyana', 'Haiti', 'Honduras', 'India',
            'Indonesia', 'Iran', 'Iraq', 'Irlanda', 'Islanda',
            'Israele', 'Italia', 'Kazakhstan', 'Kenya', 'Kirghizistan',
            'Kiribati', 'Kosovo', 'Kuwait', 'Laos', 'Lesotho',
            'Lettonia', 'Libano', 'Liberia', 'Libia', 'Liechtenstein',
            'Lituania', 'Lussemburgo', 'Macedonia del Nord', 'Madagascar', 'Malawi',
            'Malaysia', 'Maldive', 'Mali', 'Malta', 'Marocco',
            'Isole Marshall', 'Mauritania', 'Mauritius', 'Messico', 'Micronesia',
            'Moldavia', 'Monaco', 'Mongolia', 'Montenegro', 'Mozambico',
            'Namibia', 'Nauru', 'Nepal', 'Nicaragua', 'Niger',
            'Nigeria', 'Norvegia', 'Nuova Zelanda', 'Oman', 'Paesi Bassi',
            'Pakistan', 'Palau', 'Palestina', 'Panama', 'Papua Nuova Guinea',
            'Paraguay', 'Perù', 'Polonia', 'Portogallo', 'Qatar',
            'Regno Unito', 'Repubblica Ceca', 'Repubblica Centrafricana', 'Repubblica del Congo', 'Repubblica Democratica del Congo',
            'Repubblica Dominicana', 'Romania', 'Ruanda', 'Russia', 'Saint Kitts e Nevis',
            'Saint Vincent e Grenadine', 'Saint Lucia', 'Samoa', 'San Marino', 'São Tomé e Príncipe',
            'Senegal', 'Serbia', 'Seychelles', 'Sierra Leone', 'Singapore',
            'Siria', 'Slovacchia', 'Slovenia', 'Somalia', 'Spagna',
            'Sri Lanka', "Stati Uniti d'America", 'Sudafrica', 'Sudan', 'Sudan del Sud',
            'Suriname', 'Svezia', 'Svizzera', 'Tagikistan', 'Tanzania',
            'Thailandia', 'Timor Est', 'Togo', 'Tonga', 'Trinidad e Tobago',
            'Tunisia', 'Turchia', 'Turkmenistan', 'Tuvalu', 'Ucraina',
            'Uganda', 'Ungheria', 'Uruguay', 'Uzbekistan', 'Vanuatu',
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

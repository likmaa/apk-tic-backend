<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Neighborhood;

class NeighborhoodSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        // Porto-Novo default coordinates (city center approximation)
        $defaultLat = 6.4969;
        $defaultLng = 2.6283;

        $neighborhoods = [
            // 1er Arrondissement
            ['name' => 'Accron-Gogankomey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Adjègounlè', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Adomey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Ahouantikomey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Akpassa Odo Oba', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Avassa Bagoro Agbokomey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Ayétoro', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Ayimlonfidé', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Déguèkomè', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Dota-Attingbansa-Azonzakomey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Ganto', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Gbassou-Itabodo', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Gbêcon', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Guévié-Zinkomey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Hondji-Honnou Filla', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Houègbo-Hlinkomey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Houéyogbé-Gbèdji', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Houèzounmey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Idi-Araba', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Iléfiè', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Kpota Sandodo', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Lokossa', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Oganla-Gare-Est', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Sadognon-Adjégounlè', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Sadognon-Woussa', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Sagbo Kossoukodé', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Sokomey-Toffinkomey', 'arrondissement' => '1er Arrondissement'],
            ['name' => 'Togoh – Adankomey', 'arrondissement' => '1er Arrondissement', 'aliases' => 'Togoh,Adankomey'],
            ['name' => 'Vêkpa', 'arrondissement' => '1er Arrondissement'],

            // 2e Arrondissement
            ['name' => 'Agbokou Aga', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Agbokou Bassodji Mairie', 'arrondissement' => '2e Arrondissement', 'aliases' => 'Agbokou Mairie,Bassodji'],
            ['name' => 'Agbokou Centre social', 'arrondissement' => '2e Arrondissement', 'aliases' => 'Agbokou Centre'],
            ['name' => 'Agbokou Odo', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Attakè Olory-Togbé', 'arrondissement' => '2e Arrondissement', 'aliases' => 'Attakè,Olory Togbé'],
            ['name' => 'Attakè Yidi', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Djègan Daho', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Donoukin Lissèssa', 'arrondissement' => '2e Arrondissement', 'aliases' => 'Donoukin,Lissèssa'],
            ['name' => 'Gbèzounkpa', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Guévié Djèganto', 'arrondissement' => '2e Arrondissement', 'aliases' => 'Guévié,Djèganto'],
            ['name' => 'Hinkoudé', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Kandévié Radio Hokon', 'arrondissement' => '2e Arrondissement', 'aliases' => 'Kandévié Radio,Radio Hokon'],
            ['name' => 'Koutongbé', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Sèdjèko', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Tchinvié', 'arrondissement' => '2e Arrondissement'],
            ['name' => 'Zounkpa Houèto', 'arrondissement' => '2e Arrondissement', 'aliases' => 'Zounkpa,Houèto'],

            // 3e Arrondissement
            ['name' => 'Adjina Nord', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Adjina Sud', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Avakpa Kpodji', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Avakpa,Kpodji'],
            ['name' => 'Avakpa-Tokpa', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Avakpa Tokpa'],
            ['name' => 'Djassin Daho', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Djassin Zounmè', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Foun-Foun Djaguidi', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Foun Foun,Djaguidi'],
            ['name' => 'Foun-Foun Gbègo', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Foun Foun Gbègo'],
            ['name' => 'Foun-Foun Sodji', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Foun Foun Sodji'],
            ['name' => 'Foun-Foun Tokpa', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Foun Foun Tokpa'],
            ['name' => 'Hassou Agué', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Oganla Atakpamè', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Oganla Nord', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Oganla Poste', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Oganla Sokè', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Oganla Sud', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Ouinlinda Aholoukomey', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Ouinlinda,Aholoukomey'],
            ['name' => 'Ouinlinda Hôpital', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Ouinlinda Hopital'],
            ['name' => 'Zèbou Aga', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Zèbou Ahouangbo', 'arrondissement' => '3e Arrondissement'],
            ['name' => 'Zèbou–Itatigri', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Zèbou Itatigri'],
            ['name' => 'Zèbou–Massè', 'arrondissement' => '3e Arrondissement', 'aliases' => 'Zèbou Massè'],

            // 4e Arrondissement
            ['name' => 'Anavié', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Anavié Voirie', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Djègan kpèvi', 'arrondissement' => '4e Arrondissement', 'aliases' => 'Djègan Kpèvi'],
            ['name' => 'Dodji', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Gbèdjromèdé Fusion', 'arrondissement' => '4e Arrondissement', 'aliases' => 'Gbèdjromèdé'],
            ['name' => 'Gbodjè', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Guévié', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Hlogou', 'arrondissement' => '4e Arrondissement', 'aliases' => 'Hlongou'],
            ['name' => 'Houinmè Château d\'eau', 'arrondissement' => '4e Arrondissement', 'aliases' => 'Houinmè Chateau d eau'],
            ['name' => 'Houinmè Djaguidi', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Houinmè Ganto', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Houinmè Gbèdjromèdé', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Hounsa', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Hounsouko', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Kandévié Missogbé', 'arrondissement' => '4e Arrondissement', 'aliases' => 'Kandévié,Missogbé'],
            ['name' => 'Kandévié Owodé', 'arrondissement' => '4e Arrondissement', 'aliases' => 'Kandévié,Owodé'],
            ['name' => 'Kpogbonmè', 'arrondissement' => '4e Arrondissement'],
            ['name' => 'Sèto–Gbodjè', 'arrondissement' => '4e Arrondissement', 'aliases' => 'Sèto Gbodjè,Sèto'],

            // 5e Arrondissement
            ['name' => 'Akonaboè', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Djlado', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Dowa', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Dowa Aliogbogo', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Dowa Dédomè', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Houinvié', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Louho', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Ouando', 'arrondissement' => '5e Arrondissement', 'lat' => 6.4850, 'lng' => 2.6350],
            ['name' => 'Ouando Clékanmè', 'arrondissement' => '5e Arrondissement', 'aliases' => 'Ouando Clekanme'],
            ['name' => 'Ouando Kotin', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Tokpota Dadjrougbé', 'arrondissement' => '5e Arrondissement', 'aliases' => 'Tokpota,Dadjrougbé'],
            ['name' => 'Tokpota Davo', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Tokpota Vèdo', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Tokpota Zèbè', 'arrondissement' => '5e Arrondissement'],
            ['name' => 'Tokpota Zinlivali', 'arrondissement' => '5e Arrondissement'],
        ];

        foreach ($neighborhoods as $data) {
            Neighborhood::updateOrCreate(
                ['name' => $data['name'], 'city' => 'Porto-Novo'],
                array_merge([
                    'city' => 'Porto-Novo',
                    'country' => 'Bénin',
                    'lat' => $data['lat'] ?? $defaultLat,
                    'lng' => $data['lng'] ?? $defaultLng,
                    'aliases' => $data['aliases'] ?? null,
                    'is_active' => true,
                ], $data)
            );
        }

        $this->command->info('Seeded ' . count($neighborhoods) . ' neighborhoods for Porto-Novo.');
    }
}

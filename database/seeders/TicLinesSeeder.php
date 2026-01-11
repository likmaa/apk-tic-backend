<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Stop;
use App\Models\Line;
use App\Models\LineStop;

class TicLinesSeeder extends Seeder
{
    public function run(): void
    {
        // 1) Créer / mettre à jour les STOPS (points TIC)

        $stops = [
            'FIN_PAVE_AGBOKOU' => ['name' => 'Fin pavé Agbokou', 'lat' => 6.4900, 'lng' => 2.6240],
            'ECOLE_NORMALE_SUP' => ['name' => 'Ecole Normale Supérieure', 'lat' => 6.4833, 'lng' => 2.6167],
            'PLACE_BAYOLE' => ['name' => 'Place Bayole', 'lat' => 6.4778, 'lng' => 2.6240],
            'OUANDO' => ['name' => 'Ouando', 'lat' => 6.5109, 'lng' => 2.6120],
            'OUANDO_PISCINE' => ['name' => 'Ouando (Piscine municipale)', 'lat' => 6.5120, 'lng' => 2.6130],
            'BEAU_RIVAGE' => ['name' => 'Beau Rivage (Carrefour)', 'lat' => 6.5150, 'lng' => 2.6100],
            'OUANDO_MARCHE' => ['name' => 'Ouando (Marché)', 'lat' => 6.5105, 'lng' => 2.6125],
            'AGATA_MARCHE' => ['name' => 'Agata (Marché)', 'lat' => 6.4930, 'lng' => 2.6350],
            'AGATA_CARREFOUR' => ['name' => 'Agata (Carrefour)', 'lat' => 6.4940, 'lng' => 2.6360],
            'ADJARA_MARCHE' => ['name' => 'Adjara (Marché)', 'lat' => 6.4914, 'lng' => 2.6810],
            'AVAKPA' => ['name' => 'Avakpa', 'lat' => 6.4805, 'lng' => 2.6075],
            'MISSERETE_CARREFOUR' => ['name' => 'Misserete (Carrefour)', 'lat' => 6.5333, 'lng' => 2.5870],
            'AWANA_CARREFOUR' => ['name' => 'Awana (Carrefour)', 'lat' => 6.5150, 'lng' => 2.6400],
            'GRAND_MARCHE' => ['name' => 'Grand Marché', 'lat' => 6.4775, 'lng' => 2.6263],
            'BABA_IYABO_CARREFOUR' => ['name' => 'Baba Iyabo (Carrefour)', 'lat' => 6.5050, 'lng' => 2.6050],
            'AHOUANGBO_MARCHE' => ['name' => 'Ahouangbo (Marché)', 'lat' => 6.4850, 'lng' => 2.6200],
            'AGBOKOU' => ['name' => 'Agbokou', 'lat' => 6.4880, 'lng' => 2.6220],
        ];

        $stopIds = [];

        foreach ($stops as $code => $info) {
            $stop = Stop::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $info['name'],
                    'type' => 'both',
                    'lat' => $info['lat'],
                    'lng' => $info['lng'],
                ]
            );

            $stopIds[$code] = $stop->id;
        }

        // 2) Créer les LINES et leurs STOPS dans l’ordre

        $createLine = function (string $code, string $name, array $stopCodes) use ($stopIds) {
            /** @var \App\Models\Line $line */
            $line = Line::updateOrCreate(
                ['code' => $code],
                ['name' => $name],
            );

            LineStop::where('line_id', $line->id)->delete();

            foreach ($stopCodes as $index => $stopCode) {
                if (!isset($stopIds[$stopCode])) {
                    continue;
                }

                LineStop::create([
                    'line_id' => $line->id,
                    'stop_id' => $stopIds[$stopCode],
                    'position' => $index,
                ]);
            }

            return $line;
        };

        // LIGNE 1 : Fin pavé Agbokou -> Ecole Normale Supérieure
        $createLine('L1', 'Ligne 1', [
            'FIN_PAVE_AGBOKOU',
            'ECOLE_NORMALE_SUP',
        ]);

        // LIGNE 2 : Place Bayole -> Ouando
        $createLine('L2', 'Ligne 2', [
            'PLACE_BAYOLE',
            'OUANDO',
        ]);

        // LIGNE 3 : Ouando (Piscine) -> Beau Rivage
        $createLine('L3', 'Ligne 3', [
            'OUANDO_PISCINE',
            'BEAU_RIVAGE',
        ]);

        // LIGNE 4 : Ouando (Marché) -> Agata (Marché)
        $createLine('L4', 'Ligne 4', [
            'OUANDO_MARCHE',
            'AGATA_MARCHE',
        ]);

        // LIGNE 5 : Agata (Carrefour) -> Adjara (Marché)
        $createLine('L5', 'Ligne 5', [
            'AGATA_CARREFOUR',
            'ADJARA_MARCHE',
        ]);

        // LIGNE 6 : Ouando -> Avakpa
        $createLine('L6', 'Ligne 6', [
            'OUANDO',
            'AVAKPA',
        ]);

        // LIGNE 7 : Misserete (Carrefour) -> Beau Rivage
        $createLine('L7', 'Ligne 7', [
            'MISSERETE_CARREFOUR',
            'BEAU_RIVAGE',
        ]);

        // LIGNE 8 : Ouando (Marché) -> Misserete (Carrefour)
        $createLine('L8', 'Ligne 8', [
            'OUANDO_MARCHE',
            'MISSERETE_CARREFOUR',
        ]);

        // LIGNE 9 : Awana (Carrefour) -> Grand Marché
        $createLine('L9', 'Ligne 9', [
            'AWANA_CARREFOUR',
            'GRAND_MARCHE',
        ]);

        // LIGNE 10 : Baba Iyabo (Carrefour) -> Adjara (Marché)
        $createLine('L10', 'Ligne 10', [
            'BABA_IYABO_CARREFOUR',
            'ADJARA_MARCHE',
        ]);

        // LIGNE 11 : Ahouangbo (Marché) -> Ouando
        $createLine('L11', 'Ligne 11', [
            'AHOUANGBO_MARCHE',
            'OUANDO',
        ]);

        // LIGNE 12 : Place Bayole -> Agbokou
        $createLine('L12', 'Ligne 12', [
            'PLACE_BAYOLE',
            'AGBOKOU',
        ]);
    }
}

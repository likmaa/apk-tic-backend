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
            'FIN_PAVE_AGBOKOU'      => 'Fin pavé Agbokou',
            'ECOLE_NORMALE_SUP'     => 'Ecole Normale Supérieure',
            'PLACE_BAYOLE'          => 'Place Bayole',
            'OUANDO'                => 'Ouando',
            'OUANDO_PISCINE'        => 'Ouando (Piscine municipale)',
            'BEAU_RIVAGE'           => 'Beau Rivage (Carrefour)',
            'OUANDO_MARCHE'         => 'Ouando (Marché)',
            'AGATA_MARCHE'          => 'Agata (Marché)',
            'AGATA_CARREFOUR'       => 'Agata (Carrefour)',
            'ADJARA_MARCHE'         => 'Adjara (Marché)',
            'AVAKPA'                => 'Avakpa',
            'MISSERETE_CARREFOUR'   => 'Misserete (Carrefour)',
            'AWANA_CARREFOUR'       => 'Awana (Carrefour)',
            'GRAND_MARCHE'          => 'Grand Marché',
            'BABA_IYABO_CARREFOUR'  => 'Baba Iyabo (Carrefour)',
            'AHOUANGBO_MARCHE'      => 'Ahouangbo (Marché)',
            'AGBOKOU'               => 'Agbokou',
        ];

        $stopIds = []; // code => id

        foreach ($stops as $code => $name) {
            $stop = Stop::updateOrCreate(
                ['code' => $code],
                [
                    'name' => $name,
                    'type' => 'both',
                    'lat'  => null,
                    'lng'  => null,
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
                if (! isset($stopIds[$stopCode])) {
                    continue;
                }

                LineStop::create([
                    'line_id'  => $line->id,
                    'stop_id'  => $stopIds[$stopCode],
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

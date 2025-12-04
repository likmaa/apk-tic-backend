<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;

class ModerationController extends Controller
{
    public function queue(Request $request)
    {
        $items = [
            [
                'id' => 'case_001',
                'type' => 'report',
                'subject_type' => 'driver',
                'subject_name' => 'Carlos Rodriguez',
                'reporter_name' => 'Alice Martin',
                'reason' => 'Conduite dangereuse',
                'date' => '2023-10-28T14:00:00Z',
                'status' => 'pending_review',
            ],
            [
                'id' => 'case_002',
                'type' => 'report',
                'subject_type' => 'passenger',
                'subject_name' => 'Claire Durand',
                'reporter_name' => 'Jean Dupont',
                'reason' => 'Comportement irrespectueux',
                'date' => '2023-10-27T20:15:00Z',
                'status' => 'pending_review',
            ],
        ];

        return response()->json([
            'data' => $items,
        ]);
    }

    public function logs(Request $request)
    {
        $items = [
            [
                'id' => 'log_501',
                'date' => '2023-10-26T11:00:00Z',
                'moderator' => 'Admin',
                'action' => 'suspended',
                'target_name' => 'Carlos Rodriguez',
                'target_type' => 'driver',
                'reason' => 'Multiples signalements pour conduite dangereuse.',
            ],
            [
                'id' => 'log_500',
                'date' => '2023-10-25T09:30:00Z',
                'moderator' => 'Admin',
                'action' => 'warned',
                'target_name' => 'Bob Lefebvre',
                'target_type' => 'passenger',
                'reason' => 'Annulation tardive répétée.',
            ],
        ];

        return response()->json([
            'data' => $items,
        ]);
    }
}

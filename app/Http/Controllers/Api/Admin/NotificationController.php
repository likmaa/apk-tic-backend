<?php

namespace App\Http\Controllers\Api\Admin;

use App\Http\Controllers\Controller;
use App\Models\Notification;
use Illuminate\Http\Request;

class NotificationController extends Controller
{
    /**
     * Store a newly created notification in storage.
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string',
            'target' => 'required|string',
            'channels' => 'required|array',
        ]);

        $notification = Notification::create([
            'title' => $request->title,
            'message' => $request->message,
            'target' => $request->target,
            'channels' => $request->channels,
            'type' => 'system', // Default type for now, or add selector in frontend
        ]);

        // Here you would trigger the actual Push/Email sending logic (Firebase, Mailgun, etc.)

        return response()->json($notification, 201);
    }

    /**
     * Display a list of notifications sent (History).
     */
    public function index()
    {
        $notifications = Notification::orderBy('created_at', 'desc')->limit(50)->get();
        return response()->json($notifications);
    }
}

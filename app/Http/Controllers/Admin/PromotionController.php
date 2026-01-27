<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Promotion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

class PromotionController extends Controller
{
    public function index()
    {
        $promotions = Promotion::orderBy('created_at', 'desc')->get();
        return response()->json($promotions);
    }

    public function indexPublic()
    {
        $promotions = Promotion::where('is_active', true)
            ->orderBy('created_at', 'desc')
            ->get();
        return response()->json($promotions);
    }

    public function store(Request $request)
    {
        $request->validate([
            'title' => 'required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'required|image|max:2048', // 2MB Max
            'link_url' => 'nullable|url',
            'is_active' => 'boolean',
        ]);

        $promotion = new Promotion($request->only(['title', 'description', 'link_url', 'is_active']));

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('promotions', 'public');
            $promotion->image_url = Storage::url($path);
        }

        $promotion->save();

        return response()->json($promotion, 201);
    }

    public function update(Request $request, $id)
    {
        $promotion = Promotion::findOrFail($id);

        $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'description' => 'nullable|string',
            'image' => 'nullable|image|max:2048',
            'link_url' => 'nullable|url',
            'is_active' => 'boolean',
        ]);

        $promotion->fill($request->only(['title', 'description', 'link_url', 'is_active']));

        if ($request->hasFile('image')) {
            // Delete old image if exists
            if ($promotion->image_url) {
                $oldPath = str_replace('/storage/', '', $promotion->image_url);
                Storage::disk('public')->delete($oldPath);
            }

            $path = $request->file('image')->store('promotions', 'public');
            $promotion->image_url = Storage::url($path);
        }

        $promotion->save();

        return response()->json($promotion);
    }

    public function destroy($id)
    {
        $promotion = Promotion::findOrFail($id);

        if ($promotion->image_url) {
            $oldPath = str_replace('/storage/', '', $promotion->image_url);
            Storage::disk('public')->delete($oldPath);
        }

        $promotion->delete();

        return response()->json(['message' => 'Promotion deleted successfully']);
    }
}

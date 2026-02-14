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
        try {
            $request->validate([
                'title' => 'required|string|max:255',
                'description' => 'nullable|string',
                'image' => 'required|image|mimes:jpeg,png,jpg,gif,webp|max:5120', // 5MB Max
                'link_url' => 'nullable|url',
                'is_active' => 'boolean',
            ], [
                'image.required' => 'L\'image est obligatoire.',
                'image.image' => 'Le fichier doit être une image valide.',
                'image.mimes' => 'L\'image doit être au format jpeg, png, jpg, gif ou webp.',
                'image.max' => 'L\'image ne doit pas dépasser 5 Mo.',
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);
        }

        $promotion = new Promotion($request->only(['title', 'description', 'link_url', 'is_active']));

        if ($request->hasFile('image')) {
            $path = $request->file('image')->store('promotions', 'public');
            $promotion->image_url = url(Storage::url($path));
        }

        $promotion->save();

        return response()->json($promotion, 201);
    }

    public function update(Request $request, $id)
    {
        try {
            $promotion = Promotion::findOrFail($id);

            $request->validate([
                'title' => 'sometimes|required|string|max:255',
                'description' => 'nullable|string',
                'image' => 'nullable|image|mimes:jpeg,png,jpg,gif,webp|max:5120',
                'link_url' => 'nullable',
                'is_active' => 'nullable',
            ]);

            $promotion->fill($request->only(['title', 'description', 'link_url']));

            // Handle is_active (can come as string "true"/"false" from FormData)
            if ($request->has('is_active')) {
                $promotion->is_active = filter_var($request->is_active, FILTER_VALIDATE_BOOLEAN);
            }

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
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'message' => 'Erreur de validation',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            return response()->json([
                'message' => 'Erreur serveur',
                'error' => $e->getMessage(),
            ], 500);
        }
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

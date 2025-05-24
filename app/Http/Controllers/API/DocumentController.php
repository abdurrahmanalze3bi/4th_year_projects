<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PhotoRepositoryInterface;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;

class DocumentController extends Controller
{
    private PhotoRepositoryInterface $photoRepo;

    public function __construct(PhotoRepositoryInterface $photoRepo)
    {
        $this->photoRepo = $photoRepo;
    }

    public function store(Request $request)
    {
        $user = $request->user();

        // Block if verification is pending (numeric or string, depending on your column type)
        if ($user->verification_status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'Cannot modify documents while verification is pending'
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            // The 'type' field must be exactly one of these four enum members:
            'type' => 'required|in:face_id,back_id,license,mechanic_card',
            'file' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors()
            ], 422);
        }

        // Delete existing document(s) of that same type
        $this->photoRepo->deleteDocumentsByType($user->id, $request->type);

        $path = $request->file('file')->store('documents', 'public');

        $photo = $this->photoRepo->storeDocument(
            $user->id,
            $request->type,   // must be 'face_id', 'back_id', 'license', or 'mechanic_card'
            $path
        );

        // Only reset status if not already pending
        if ($user->verification_status !== 'pending') {
            $user->update(['verification_status' => 'none']);
        }

        return response()->json([
            'success' => true,
            'data'    => [
                'id'   => $photo->id,
                'url'  => asset("storage/{$path}"),
                'type' => $photo->type,
            ]
        ]);
    }
}

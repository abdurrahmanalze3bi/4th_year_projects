<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\ProfileRepositoryInterface;
use App\Interfaces\PhotoRepositoryInterface;
use App\Models\ProfileComment;
use App\Models\Profile;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    private ProfileRepositoryInterface $profileRepo;
    private PhotoRepositoryInterface $photoRepo;

    public function __construct(
        ProfileRepositoryInterface $profileRepo,
        PhotoRepositoryInterface $photoRepo
    ) {
        $this->profileRepo = $profileRepo;
        $this->photoRepo   = $photoRepo;
    }

    /**
     * Get user profile
     * GET /api/profile
     */
    public function show(Request $request)
    {
        try {
            $user    = $request->user();
            $profile = $this->profileRepo->getProfileWithUser($user->id);

            return response()->json([
                'success' => true,
                'data'    => $this->formatProfileData($profile, $user),
            ], 200);

        } catch (\Exception $e) {
            Log::error("Profile fetch error: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve profile',
            ], 500);
        }
    }

    /**
     * Update user profile (including preferences & documents)
     * POST /api/profile
     */
    public function update(Request $request)
    {
        $user = $request->user();

        $validator = Validator::make($request->all(), [
            'first_name'           => 'sometimes|string|max:255',
            'last_name'            => 'sometimes|string|max:255',
            'description'          => 'nullable|string|max:500',
            'address'              => 'nullable|in:دمشق,درعا,القنيطرة,السويداء,ريف دمشق,حمص,حماة,اللاذقية,طرطوس,حلب,ادلب,الحسكة,الرقة,دير الزور',
            'gender'               => 'nullable|in:M,F',
            'type_of_car'          => 'nullable|string|max:255',
            'color_of_car'         => 'nullable|string|max:50',
            'number_of_seats'      => 'nullable|integer|min:1|max:12',
            'radio'                => 'nullable|boolean',
            'smoking'              => 'nullable|boolean',
            'number_of_rides'      => 'nullable|integer|min:0',
            'profile_photo'        => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'car_pic'              => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'face_id_pic'          => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'back_id_pic'          => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'driving_license_pic'  => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'mechanic_card_pic'    => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        // Collect validated data
        $data = $validator->validated();
        // Normalize preference & ride count
        $data['radio'] = $request->has('radio') ? (bool) $request->input('radio') : ($data['radio'] ?? null);
        $data['smoking'] = $request->has('smoking') ? (bool) $request->input('smoking') : ($data['smoking'] ?? null);
        if ($request->has('number_of_rides')) {
            $data['number_of_rides'] = (int) $request->input('number_of_rides');
        }

        // Prevent critical changes during pending verification
        $critical = ['first_name', 'last_name', 'type_of_car', 'color_of_car', 'number_of_seats'];
        if ($user->verification_status === 'pending' && count(array_intersect(array_keys($data), $critical)) > 0) {
            return response()->json([
                'success' => false,
                'message' => 'Cannot change name or vehicle details while verification is pending',
            ], 403);
        }

        // Setup document mapping
        $docTypeMap = [
            'face_id_pic'         => 'face_id',
            'back_id_pic'         => 'back_id',
            'driving_license_pic' => 'license',
            'mechanic_card_pic'   => 'mechanic_card',
        ];
        $fileFields = array_merge(['profile_photo', 'car_pic'], array_keys($docTypeMap));

        // Handle file uploads
        foreach ($fileFields as $field) {
            if (! $request->hasFile($field)) {
                continue;
            }

            $disk = isset($docTypeMap[$field]) ? 'verifications' : 'profiles';
            $path = $request->file($field)->store($disk, 'public');

            // Store in profiles table data
            $data[$field] = $path;

            // Sync into verifications if needed
            if (isset($docTypeMap[$field])) {
                $type = $docTypeMap[$field];
                $this->photoRepo->deleteDocumentsByType($user->id, $type);
                $this->photoRepo->storeDocument($user->id, $type, $path);
            }
        }

        try {
            $this->profileRepo->updateProfile($user->id, $data);
            $profile = $this->profileRepo->getProfileWithUser($user->id);

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data'    => $this->formatProfileData($profile, $user),
            ], 200);

        } catch (\Exception $e) {
            Log::error("Profile update error: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Profile update failed',
            ], 500);
        }
    }

    /**
     * Add comment to profile
     * POST /api/profile/{userId}/comments
     */
    public function comment(Request $request, int $userId)
    {
        $user = $request->user();

        if ($user->id === $userId) {
            return response()->json([
                'success' => false,
                'message' => "You can't comment on your own profile.",
            ], 403);
        }

        $validator = Validator::make($request->all(), ['comment' => 'required|string']);
        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $profile = $this->profileRepo->getProfileByUserId($userId);
        if (! $profile) {
            return response()->json(['success' => false, 'message' => 'Profile not found'], 404);
        }

        $comment = ProfileComment::create([
            'profile_id' => $profile->id,
            'user_id'    => $user->id,
            'comment'    => $request->input('comment'),
        ]);
        $comment->load('commenter');

        return response()->json([
            'success' => true,
            'message' => 'Comment added',
            'data'    => $this->formatComment($comment),
        ], 201);
    }

    /**
     * Format a single comment
     */
    private function formatComment(ProfileComment $comment): array
    {
        $user    = $comment->commenter;
        $profile = Profile::where('user_id', $user->id)->first();
        $photo   = $profile && $profile->profile_photo
            ? asset('storage/' . $profile->profile_photo)
            : null;

        return [
            'id'         => $comment->id,
            'comment'    => $comment->comment,
            'commenter'  => [
                'id'            => $user->id,
                'name'          => trim(($user->first_name ?? '') . ' ' . ($user->last_name ?? '')),
                'profile_photo' => $photo,
            ],
            'created_at' => $comment->created_at->toDateTimeString(),
        ];
    }

    /**
     * Shape profile JSON
     */
    private function formatProfileData($profile, $user): array
    {
        $docs = $this->photoRepo
            ->getUserDocumentsByType($user->id, ['face_id', 'back_id', 'license', 'mechanic_card'])
            ->mapWithKeys(fn($d) => ["{$d->type}_pic" => asset("storage/{$d->path}")]);

        $comments = collect($profile->comments ?? [])
            ->map(fn($c) => $this->formatComment($c))
            ->all();

        return [
            'user_id'            => $user->id,
            'full_name'          => trim("{$user->first_name} {$user->last_name}"),
            'verification_status'=> $user->verification_status,
            'address'            => $profile->address,
            'gender'             => $profile->gender,
            'profile_photo'      => $profile->profile_photo
                ? asset("storage/{$profile->profile_photo}")
                : null,
            'description'        => $profile->description,
            'type_of_car'        => $profile->type_of_car,
            'color_of_car'       => $profile->color_of_car,
            'number_of_seats'    => $profile->number_of_seats,
            'car_pic'            => $profile->car_pic
                ? asset("storage/{$profile->car_pic}")
                : null,
            'radio'              => $profile->radio,
            'smoking'            => $profile->smoking,
            'number_of_rides'    => $profile->number_of_rides,
            'documents'          => $docs,
            'comments'           => $comments,
        ];
    }
}

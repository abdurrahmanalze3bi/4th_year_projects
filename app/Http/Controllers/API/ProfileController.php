<?php
namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\ProfileRepositoryInterface;
use App\Models\ProfileComment;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Log;

class ProfileController extends Controller
{
    private ProfileRepositoryInterface $profileRepo;

    public function __construct(ProfileRepositoryInterface $profileRepo)
    {
        $this->profileRepo = $profileRepo;
    }

    /**
     * GET /api/profile
     */
    public function show(Request $request)
    {
        $user   = $request->user();
        $profile = $this->profileRepo
            ->getProfileWithUser($user->id)
            ->load('comments.commenter');

        return response()->json([
            'success' => true,
            'data'    => $this->formatProfileData($profile),
        ]);
    }

    /**
     * POST /api/profile
     */
    public function update(Request $request)
    {
        $user = $request->user();

        // 1) Validate including enum constraints
        $validator = Validator::make($request->all(), [
            'number_of_rides'     => 'nullable|integer|min:0',
            'description'         => 'nullable|string',
            'type_of_car'         => 'nullable|string',
            'color_of_car'        => 'nullable|string',
            'number_of_seats'     => 'nullable|integer|min:1',
            'radio'               => 'nullable|boolean',
            'smoking'             => 'nullable|boolean',
            'address'             => 'nullable|in:دمشق,درعا,القنيطرة,السويداء,ريف دمشق,حمص,حماة,اللاذقية,طرطوس,حلب,ادلب,الحسكة,الرقة,دير الزور',
            'gender'              => 'nullable|in:M,F',
            'profile_photo'       => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'car_pic'             => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'face_id_pic'         => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'back_id_pic'         => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'driving_license_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'mechanic_card_pic'   => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $data = $validator->validated();

        // 2) Handle any file uploads
        foreach ([
                     'profile_photo', 'car_pic', 'face_id_pic',
                     'back_id_pic', 'driving_license_pic', 'mechanic_card_pic',
                 ] as $field) {
            if ($request->hasFile($field)) {
                $data[$field] = $request->file($field)
                    ->store('profiles', 'public');
            }
        }

        // 3) Update or create the profile record
        try {
            $profile = $this->profileRepo
                ->updateOrCreateProfile($user->id, $data)
                ->fresh();

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data'    => $this->formatProfileData($profile),
            ]);
        } catch (\Throwable $e) {
            Log::error("Profile update error: {$e->getMessage()}");
            return response()->json([
                'success' => false,
                'message' => 'Update failed. Check server logs.',
            ], 500);
        }
    }

    /**
     * POST /api/profile/{userId}/comments
     */
    public function comment(Request $request, int $userId)
    {
        $user = $request->user();

        // Prevent self-commenting
        if ($user->id === $userId) {
            return response()->json([
                'success' => false,
                'message' => "You can't comment on your own profile.",
            ], 403);
        }

        $validator = Validator::make($request->all(), [
            'comment' => 'required|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        $profile = $this->profileRepo->getProfileByUserId($userId);
        if (! $profile) {
            return response()->json([
                'success' => false,
                'message' => 'Profile not found',
            ], 404);
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
            'data'    => [
                'id'         => $comment->id,
                'comment'    => $comment->comment,
                'commenter'  => [
                    'id'   => $comment->commenter->id,
                    'name' => trim("{$comment->commenter->first_name} {$comment->commenter->last_name}"),
                ],
                'created_at' => $comment->created_at->toDateTimeString(),
            ],
        ], 201);
    }

    /**
     * Format Profile model into API response, preferring profile fields
     */
    private function formatProfileData($p): array
    {
        return [
            'user_id'      => $p->user_id,
            'full_name'    => trim("{$p->user->first_name} {$p->user->last_name}"),
            'address'      => $p->address ?? $p->user->address,
            'gender'       => $p->gender  ?? $p->user->gender,
            'profile_photo'=> $p->profile_photo
                ? asset("storage/{$p->profile_photo}")
                : null,
            'description'  => $p->description,
            'rides'        => ['total' => $p->number_of_rides],
            'vehicle'      => [
                'type'  => $p->type_of_car,
                'color' => $p->color_of_car,
                'seats' => $p->number_of_seats,
                'image' => $p->car_pic
                    ? asset("storage/{$p->car_pic}")
                    : null,
            ],
            'preferences'  => [
                'radio'   => $p->radio   ? 'ok' : 'not ok',
                'smoking' => $p->smoking ? 'ok' : 'not ok',
            ],
            'documents'    => [
                'face_id'       => $p->face_id_pic
                    ? asset("storage/{$p->face_id_pic}")
                    : null,
                'back_id'       => $p->back_id_pic
                    ? asset("storage/{$p->back_id_pic}")
                    : null,
                'license'       => $p->driving_license_pic
                    ? asset("storage/{$p->driving_license_pic}")
                    : null,
                'mechanic_card' => $p->mechanic_card_pic
                    ? asset("storage/{$p->mechanic_card_pic}")
                    : null,
            ],
            'comments'     => collect($p->comments)
                ->map(fn($c) => [
                    'id'        => $c->id,
                    'comment'   => $c->comment,
                    'commenter' => [
                        'id'   => $c->commenter->id,
                        'name' => trim("{$c->commenter->first_name} {$c->commenter->last_name}"),
                    ],
                    'created_at'=> $c->created_at->toDateTimeString(),
                ])
                ->toArray(),
        ];
    }
}

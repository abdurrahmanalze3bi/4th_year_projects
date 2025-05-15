<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\ProfileRepositoryInterface;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\Storage;

class ProfileController extends Controller
{
    private $profileRepository;

    public function __construct(ProfileRepositoryInterface $profileRepository)
    {
        $this->profileRepository = $profileRepository;
    }

    public function show($userId)
    {
        try {
            $profile = $this->profileRepository->getProfileWithUser($userId);

            return response()->json([
                'success' => true,
                'data' => $this->formatProfileData($profile)
            ]);

        } catch (\Exception $e) {
            try {
                $user = User::findOrFail($userId);
                $profile = $this->profileRepository->createFromUser($user);

                return response()->json([
                    'success' => true,
                    'data' => $this->formatProfileData($profile)
                ]);

            } catch (\Exception $e) {
                return response()->json([
                    'success' => false,
                    'message' => 'Profile not found'
                ], 404);
            }
        }
    }

    public function update(Request $request, $userId)
    {
        $validator = Validator::make($request->all(), [
            'profile_photo' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'description' => 'nullable|string',
            'car_pic' => 'nullable|string',
            'type_of_car' => 'nullable|string|max:255',
            'color_of_car' => 'nullable|string|max:50',
            'number_of_seats' => 'nullable|integer|min:1',
            'comments' => 'nullable|string',
            'radio' => 'nullable|boolean',
            'smoking' => 'nullable|boolean',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $data = $validator->validated();

            // Handle file upload
            if ($request->hasFile('profile_photo')) {
                $path = $request->file('profile_photo')->store('public/profiles');
                $data['profile_photo'] = Storage::url($path);
            }

            $profile = $this->profileRepository->updateOrCreateProfile(
                $userId,
                $data
            );

            return response()->json([
                'success' => true,
                'message' => 'Profile updated successfully',
                'data' => $this->formatProfileData($profile)
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Update failed: ' . $e->getMessage()
            ], 500);
        }
    }

    private function formatProfileData($profile)
    {
        return [
            'user_id' => $profile->user_id,
            'full_name' => $profile->full_name,
            'address' => $profile->address,
            'gender' => $profile->gender,
            'profile_photo' => $profile->profile_photo ? asset($profile->profile_photo) : null,
            'description' => $profile->description,
            'rides' => [
                'total' => $profile->number_of_rides,
            ],
            'vehicle' => [
                'type' => $profile->type_of_car,
                'color' => $profile->color_of_car,
                'seats' => $profile->number_of_seats,
                'image' => $profile->car_pic
            ],
            'preferences' => [
                'radio' => $profile->radio,
                'smoking' => $profile->smoking
            ],
            'documents' => [
                'face_id' => $profile->face_id_pic,
                'back_id' => $profile->back_id_pic,
                'license' => $profile->driving_license_pic,
                'mechanic_card' => $profile->mechanic_card_pic
            ]
        ];
    }
}

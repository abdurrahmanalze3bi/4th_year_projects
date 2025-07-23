<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Interfaces\PhotoRepositoryInterface;
use App\Interfaces\ProfileRepositoryInterface;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Validator;

class VerificationController extends Controller
{
    private PhotoRepositoryInterface $photoRepo;
    private ProfileRepositoryInterface $profileRepo;

    public function __construct(
        PhotoRepositoryInterface $photoRepo,
        ProfileRepositoryInterface $profileRepo
    ) {
        $this->photoRepo   = $photoRepo;
        $this->profileRepo = $profileRepo;
    }

    /**
     * Submit passenger verification request
     * POST /api/profile/verify/passenger
     */
    public function verifyPassenger(Request $request)
    {
        return DB::transaction(function () use ($request) {
            $user = User::lockForUpdate()->findOrFail($request->user()->id);

            if ($user->verification_status === 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending verification request'
                ], 409);
            }

            $validator = Validator::make($request->all(), [
                'face_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
                'back_id_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
            }

            try {
                $profile = $this->profileRepo->getProfileByUserId($user->id);
                if (!$profile) {
                    $this->profileRepo->updateProfile($user->id, []);
                    $profile = $this->profileRepo->getProfileByUserId($user->id);
                }

                // exact ENUM values
                $map = [
                    'face_id_pic' => 'face_id',
                    'back_id_pic' => 'back_id',
                ];

                $profileData = [];

                foreach ($map as $inputName => $enumType) {
                    if ($request->hasFile($inputName)) {
                        $ext      = $request->file($inputName)->getClientOriginalExtension();
                        $filename = $user->id . '_' . time() . '.' . $ext;
                        $path     = $request->file($inputName)
                            ->storeAs("verifications/{$enumType}", $filename, 'public');

                        $this->photoRepo->deleteDocumentsByType($user->id, $enumType);
                        $this->photoRepo->storeDocument($user->id, $enumType, $path);

                        $profileData[$inputName] = $path;
                    }
                }

                if (!empty($profileData)) {
                    $this->profileRepo->updateProfile($profile->id, $profileData);
                }

                $user->update(['verification_status' => 'pending']);

                return response()->json([
                    'success' => true,
                    'message' => 'Verification request submitted',
                    'status'  => $user->fresh()->verification_status
                ], 201);

            } catch (\Exception $e) {
                DB::rollBack();
                return response()->json([
                    'success' => false,
                    'message' => 'Verification failed: ' . $e->getMessage()
                ], 500);
            }
        });
    }

    /**
     * Submit driver verification request
     * POST /api/profile/verify/driver
     */
    public function verifyDriver(Request $request)
    {
        $user = $request->user();

        if ($user->verification_status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending verification request',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'face_id_pic'         => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'back_id_pic'         => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'driving_license_pic' => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'mechanic_card_pic'   => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'car_pic'             => 'nullable|image|mimes:jpeg,png,jpg,gif|max:2048',
            'type_of_car'         => 'nullable|string|max:255',
            'color_of_car'        => 'nullable|string|max:50',
            'number_of_seats'     => 'nullable|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json(['success' => false, 'errors' => $validator->errors()], 422);
        }

        return DB::transaction(function () use ($request, $user) {
            $userId  = $user->id;
            $profile = $this->profileRepo->getProfileByUserId($userId)
                ?: $this->profileRepo->updateProfile($userId, []);

            // exact ENUM values
            $map = [
                'face_id_pic'         => 'face_id',
                'back_id_pic'         => 'back_id',
                'driving_license_pic' => 'license',
                'mechanic_card_pic'   => 'mechanic_card',
                'car_pic'             => 'car_pic', // folder only, not stored in photos table
            ];

            $profileData = [];

            foreach ($map as $inputName => $folder) {
                if ($request->hasFile($inputName)) {
                    $ext      = $request->file($inputName)->getClientOriginalExtension();
                    $filename = $userId . '_' . time() . '.' . $ext;
                    $path     = $request->file($inputName)
                        ->storeAs("verifications/{$folder}", $filename, 'public');

                    // store in photos table (except car_pic)
                    if (in_array($inputName, ['face_id_pic', 'back_id_pic', 'driving_license_pic', 'mechanic_card_pic'])) {
                        $this->photoRepo->deleteDocumentsByType($userId, $map[$inputName]);
                        $this->photoRepo->storeDocument($userId, $map[$inputName], $path);
                    }

                    $profileData[$inputName] = $path;
                }
            }

            $vehicleData = [];
            foreach (['type_of_car', 'color_of_car', 'number_of_seats'] as $key) {
                if ($request->filled($key)) {
                    $vehicleData[$key] = $request->input($key);
                }
            }

            $this->profileRepo->updateProfile($profile->id, array_merge($profileData, $vehicleData));

            $user->update(['verification_status' => 'pending']);

            return response()->json([
                'success' => true,
                'message' => 'Driver verification request submitted for review'
            ], 201);
        });
    }

    /**
     * Check verification status
     * GET /api/profile/verify/status/{userId}
     */
    public function status(int $userId)
    {
        try {
            $user = User::findOrFail($userId);

            $statusLabels = [
                'none'     => 'not_verified',
                'pending'  => 'pending',
                'rejected' => 'rejected',
                'approved' => 'approved',
            ];

            $documents = $this->photoRepo
                ->getUserDocumentsByType($userId, ['face_id', 'back_id', 'license', 'mechanic_card'])
                ->mapWithKeys(fn($doc) => [$doc->type => asset("storage/{$doc->path}")]);

            $profile = $this->profileRepo->getProfileByUserId($userId);

            return response()->json([
                'success'   => true,
                'status'    => $statusLabels[$user->verification_status] ?? 'unknown',
                'documents' => $documents,
                'vehicle'   => $profile ? [
                    'type'  => $profile->type_of_car,
                    'color' => $profile->color_of_car,
                    'seats' => $profile->number_of_seats,
                    'photo' => $profile->car_pic ? asset("storage/{$profile->car_pic}") : null
                ] : null,
                'verified'  => [
                    'passenger' => (bool) $user->is_verified_passenger,
                    'driver'    => (bool) $user->is_verified_driver,
                ],
            ]);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Failed to retrieve verification status: ' . $e->getMessage()
            ], 500);
        }
    }
}

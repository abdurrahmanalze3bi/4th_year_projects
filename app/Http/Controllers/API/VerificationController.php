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

            // Block if already pending
            if ($user->verification_status === 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'You already have a pending verification request'
                ], 409);
            }

            $validator = Validator::make($request->all(), [
                'face_id_pic' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
                'back_id_pic' => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            ]);

            if ($validator->fails()) {
                return response()->json([
                    'success' => false,
                    'errors'  => $validator->errors()
                ], 422);
            }

            try {
                // ─── EXACTLY match your ENUM('face_id','back_id','license','mechanic_card') ───
                $allowedTypes = [
                    'face_id_pic' => 'face_id',
                    'back_id_pic' => 'back_id',
                ];

                foreach (['face_id_pic', 'back_id_pic'] as $field) {
                    $path    = $request->file($field)->store('verifications', 'public');
                    $docType = $allowedTypes[$field]; // 'face_id' or 'back_id'

                    // Delete any existing document of that ENUM-type
                    $this->photoRepo->deleteDocumentsByType($user->id, $docType);
                    // Store new row: type will be exactly 'face_id' or 'back_id'
                    $this->photoRepo->storeDocument($user->id, $docType, $path);
                }

                // Mark user as “pending” passenger verification
                $user->update(['verification_status' => 'pending']);
                $user = $user->fresh();

                return response()->json([
                    'success' => true,
                    'message' => 'Verification request submitted',
                    'status'  => $user->verification_status
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

        // Block if already pending
        if ($user->verification_status === 'pending') {
            return response()->json([
                'success' => false,
                'message' => 'You already have a pending verification request',
            ], 409);
        }

        $validator = Validator::make($request->all(), [
            'face_id_pic'          => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'back_id_pic'          => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'driving_license_pic'  => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'mechanic_card_pic'    => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'car_pic'              => 'required|image|mimes:jpeg,png,jpg,gif|max:2048',
            'type_of_car'          => 'required|string|max:255',
            'color_of_car'         => 'required|string|max:50',
            'number_of_seats'      => 'required|integer|min:1|max:12',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'success' => false,
                'errors'  => $validator->errors(),
            ], 422);
        }

        try {
            $userId  = $user->id;
            $profile = $this->profileRepo->getProfileByUserId($userId);

            if (! $profile) {
                return response()->json([
                    'success' => false,
                    'message' => 'Complete your profile before driver verification'
                ], 400);
            }

            // ─── EXACTLY match the ENUM('face_id','back_id','license','mechanic_card') ───
            $allowedTypes = [
                'face_id_pic'         => 'face_id',
                'back_id_pic'         => 'back_id',
                'driving_license_pic' => 'license',
                'mechanic_card_pic'   => 'mechanic_card',
            ];

            foreach (
                ['face_id_pic', 'back_id_pic', 'driving_license_pic', 'mechanic_card_pic']
                as $field
            ) {
                $path    = $request->file($field)->store('verifications', 'public');
                $docType = $allowedTypes[$field]; // one of 'face_id', 'back_id', 'license', 'mechanic_card'

                // Remove any existing photo rows with that EXACT type
                $this->photoRepo->deleteDocumentsByType($userId, $docType);
                // Save new row: photos.type = 'license' or 'mechanic_card', etc.
                $this->photoRepo->storeDocument($userId, $docType, $path);
            }

            // Vehicle info (unchanged)
            $vehicleData = [
                'car_pic'         => $request->file('car_pic')->store('verifications', 'public'),
                'type_of_car'     => $request->input('type_of_car'),
                'color_of_car'    => $request->input('color_of_car'),
                'number_of_seats'=> $request->input('number_of_seats'),
            ];
            $this->profileRepo->updateProfile($profile->id, $vehicleData);

            // Mark user as “pending” driver verification
            $user->update(['verification_status' => 'pending']);

            return response()->json([
                'success' => true,
                'message' => 'Driver verification request submitted for review'
            ], 201);

        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Driver verification submission failed: ' . $e->getMessage()
            ], 500);
        }
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

            // Request exactly the ENUM values: face_id, back_id, license, mechanic_card
            $enumTypes = ['face_id', 'back_id', 'license', 'mechanic_card'];

            $documents = $this->photoRepo
                ->getUserDocumentsByType($userId, $enumTypes)
                ->mapWithKeys(fn($doc) => [
                    // For each Photo row, $doc->type is exactly 'face_id' or 'license', etc.
                    $doc->type => asset("storage/{$doc->path}")
                ]);

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

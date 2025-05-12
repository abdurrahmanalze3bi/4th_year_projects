<?php

namespace App\Http\Controllers\API\Auth;

use App\Http\Controllers\Controller;
use App\Interfaces\UserRepositoryInterface;
use Illuminate\Http\Request; // Ensure this is imported
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Laravel\Socialite\Facades\Socialite;
use App\Models\User;

class GoogleController extends Controller
{
    private UserRepositoryInterface $userRepo;

    public function __construct(UserRepositoryInterface $userRepo)
    {
        $this->userRepo = $userRepo;
    }


    public function redirect() {
        return Socialite::driver('google')->redirect();
    }

    // Inject Illuminate\Http\Request to inspect it
    public function callback(Request $request)
    {
        // Log all incoming request data (query parameters and POST body)
        Log::info('Google Callback - Incoming Request Data:', $request->all());
        Log::info('Google Callback - Query Param "code":', ['code_param' => $request->query('code')]);
        Log::info('Google Callback - Query Param "state":', ['state_param' => $request->query('state')]);

        try {
            $guzzleClientOptions = [];
            if (config('app.env') === 'local' || config('app.env') === 'testing') {
                Log::warning('Google OAuth: SSL verification is DISABLED for Guzzle client. FOR TESTING ONLY.');
                $guzzleClientOptions['verify'] = false;
            }
            $client = new \GuzzleHttp\Client($guzzleClientOptions);

            // Socialite should automatically pick up the 'code' from the $request
            $googleUser = Socialite::driver('google')
                ->setHttpClient($client)
                ->user();


            // ... (rest of your existing logic from the previous version)
            if (!$googleUser || !$googleUser->getEmail()) {
                Log::error('Google OAuth Callback: Google user data or email not received.', ['google_user_dump' => $googleUser]);
                return response()->json(['error' => 'Could not retrieve user information from Google.'], 401);
            }

            $user = $this->userRepo->findByGoogleId($googleUser->getId());

            if (!$user) {
                $user = $this->userRepo->findByEmail($googleUser->getEmail());

                if ($user) {
                    $this->userRepo->updateGoogleId($user->id, $googleUser->getId());
                    if (empty($user->avatar) && $googleUser->getAvatar()) {
                        $userModel = User::find($user->id);
                        if ($userModel) {
                            $userModel->avatar = $googleUser->getAvatar();
                            $userModel->save();
                            $user = $userModel;
                        }
                    }
                } else {
                    $firstName = $googleUser->user['given_name'] ?? null;
                    $lastName = $googleUser->user['family_name'] ?? null;

                    if (is_null($firstName) && !is_null($googleUser->getName())) {
                        $nameParts = explode(' ', $googleUser->getName(), 2);
                        $firstName = $nameParts[0];
                        $lastName = $nameParts[1] ?? '';
                    }

                    $userData = [
                        'first_name' => $firstName,
                        'last_name' => $lastName,
                        'email' => $googleUser->getEmail(),
                        'password' => bcrypt(Str::random(24)),
                        'google_id' => $googleUser->getId(),
                        'avatar' => $googleUser->getAvatar(),
                        'email_verified_at' => now(),
                        'status' => 1
                    ];
                    $user = $this->userRepo->createUser($userData);
                }
            } else {
                $updateData = [];
                if ($user->avatar !== $googleUser->getAvatar() && $googleUser->getAvatar()) {
                    $updateData['avatar'] = $googleUser->getAvatar();
                }
                if (!empty($updateData)) {
                    $userModel = User::find($user->id);
                    if ($userModel) {
                        $userModel->update($updateData);
                        $user = $userModel;
                    }
                }
            }

            if (!$user) {
                Log::error('Google OAuth Callback: User object is null after create/find.', ['google_user_id' => $googleUser->getId()]);
                return response()->json(['error' => 'User processing failed after Google authentication.'], 500);
            }

            $token = $user->createToken('google-auth-token')->plainTextToken;

            return response()->json([
                'message' => 'Authentication successful.',
                'user' => $user,
                'token' => $token,
                'token_type' => 'Bearer',
            ]);


        } catch (\Laravel\Socialite\Two\InvalidStateException $e) {
            Log::warning('Google OAuth Callback Invalid State: ' . $e->getMessage(), [
                'exception' => $e,
                'incoming_state' => $request->input('state') // Log the state Socialite received
            ]);
            return response()->json(['error' => 'Invalid state. Please try logging in again.'], 401);
        } catch (\GuzzleHttp\Exception\ClientException $e) {
            $responseBody = $e->hasResponse() ? $e->getResponse()->getBody()->getContents() : 'No response body';
            Log::error('Google OAuth Callback Guzzle Client Error: ' . $e->getMessage(), [
                'exception' => $e,
                'response_body' => $responseBody
            ]);
            $errorMessage = 'Authentication failed due to a communication error with Google.';
            if (config('app.debug')) {
                $errorMessage .= ' Guzzle Error: ' . $e->getMessage() . ' | Response: ' . $responseBody;
            }
            return response()->json(['error' => $errorMessage], 401);
        } catch (\GuzzleHttp\Exception\RequestException $e) {
            Log::error('Google OAuth Callback Guzzle Request (cURL) Error: ' . $e->getMessage(), [
                'exception' => $e,
                'handler_context' => method_exists($e, 'getHandlerContext') ? $e->getHandlerContext() : 'N/A'
            ]);
            $errorMessage = 'Authentication failed due to a network issue (cURL).';
            if (config('app.debug')) {
                $errorMessage .= ' Details: ' . $e->getMessage();
            }
            return response()->json(['error' => $errorMessage], 401);
        }
        catch (\Exception $e) {
            Log::error('Google OAuth Callback General Error: ' . $e->getMessage(), [
                'exception' => $e,
                'trace' => $e->getTraceAsString()
            ]);
            $errorMessage = 'Authentication failed. Please try again later.';
            if (config('app.debug')) {
                $errorMessage .= ' Details: ' . get_class($e) . ' - ' . $e->getMessage();
            }
            return response()->json(['error' => $errorMessage], 401);
        }
    }
}

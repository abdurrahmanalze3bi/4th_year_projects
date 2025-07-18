<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class AdminDashboardController extends Controller
{
    // Admin credentials
    const ADMIN_EMAIL = 'twisrmann2002@gmail.com';
    const ADMIN_PASSWORD = 'arayaz8152002';

    /**
     * Admin login
     * POST /admin/login
     */
    public function login(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'email' => 'required|email',
            'password' => 'required|string'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()
            ], 422);
        }

        if ($request->email !== self::ADMIN_EMAIL || $request->password !== self::ADMIN_PASSWORD) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Invalid admin credentials'
            ], 401);
        }

        // Set admin session
        Session::put('admin_logged_in', true);
        Session::put('admin_email', self::ADMIN_EMAIL);
        Session::save();

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'session_id' => Session::getId()
        ]);
    }

    /**
     * Admin logout
     * GET /admin/logout
     */
    public function logout()
    {
        Session::forget('admin_logged_in');
        Session::forget('admin_email');

        return response()->json([
            'status' => 'success',
            'message' => 'Logout successful'
        ]);
    }

    /**
     * Validate admin session
     */
    private function validateAdminSession()
    {
        if (!Session::get('admin_logged_in')) {
            Log::warning('Admin auth: No active session');
            return false;
        }
        return true;
    }

    /**
     * Charge wallet
     * POST /admin/wallet/charge
     */
    public function chargeWallet(Request $request)
    {
        if (!$this->validateAdminSession()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        // Validate input
        $validator = Validator::make($request->all(), [
            'phone_number' => 'required|string|min:10|max:15',
            'amount' => 'required|numeric|min:1|max:1000000'
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()
            ], 422);
        }

        // Find wallet
        $wallet = Wallet::where('phone_number', $request->phone_number)
            ->with('user:id,first_name,last_name')
            ->first();

        if (!$wallet) {
            return response()->json([
                'status' => 'error',
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'No wallet associated with this phone number'
            ], 404);
        }

        DB::beginTransaction();

        try {
            // Calculate new balance
            $previousBalance = $wallet->balance;
            $newBalance = $previousBalance + $request->amount;

            // Update wallet
            $wallet->balance = $newBalance;
            $wallet->save();

            // Create transaction
            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'type' => 'admin_credit',
                'amount' => $request->amount,
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
                'description' => 'Admin wallet charge',
                'transaction_id' => 'ADM_'.time().'_'.Str::random(8),
                'status' => 'completed',
                'metadata' => [
                    'admin_email' => Session::get('admin_email'),
                    'ip_address' => $request->ip()
                ]
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet charged successfully',
                'transaction_id' => $transaction->transaction_id,
                'wallet' => [
                    'id' => $wallet->id,
                    'phone_number' => $wallet->phone_number,
                    'previous_balance' => $previousBalance,
                    'new_balance' => $newBalance,
                    'owner' => $wallet->user->first_name.' '.$wallet->user->last_name
                ]
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Wallet charge failure: '.$e->getMessage(), [
                'phone' => $request->phone_number,
                'amount' => $request->amount
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 'PROCESSING_ERROR',
                'message' => 'Failed to complete wallet charge',
                'system_message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }

    /**
     * Get all wallets
     * GET /admin/wallets
     */
    public function showWallets()
    {
        if (!$this->validateAdminSession()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        $wallets = Wallet::with('user:id,first_name,last_name,email')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'wallets' => $wallets
        ]);
    }

    /**
     * Get wallet transactions
     * GET /admin/wallet/{wallet_id}/transactions
     */
    public function showWalletTransactions($walletId)
    {
        if (!$this->validateAdminSession()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        $wallet = Wallet::find($walletId);

        if (!$wallet) {
            return response()->json([
                'status' => 'error',
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Wallet not found'
            ], 404);
        }

        $transactions = WalletTransaction::where('wallet_id', $walletId)
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'status' => 'success',
            'wallet' => $wallet,
            'transactions' => $transactions
        ]);
    }

    /**
     * Get dashboard statistics
     * GET /admin/dashboard
     */
    public function showDashboard()
    {
        if (!$this->validateAdminSession()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        $stats = [
            'total_wallets' => Wallet::count(),
            'total_users' => User::count(),
            'total_balance' => Wallet::sum('balance'),
            'total_transactions' => WalletTransaction::count(),
            'recent_transactions' => WalletTransaction::with('wallet:id,wallet_number,phone_number')
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get()
        ];

        return response()->json([
            'status' => 'success',
            'stats' => $stats
        ]);
    }

    /**
     * Session debug endpoint
     * GET /session-debug
     */
    public function sessionDebug()
    {
        return response()->json([
            'session' => Session::all(),
            'cookies' => request()->cookies->all(),
            'admin_logged_in' => Session::get('admin_logged_in', false)
        ]);
    }
}

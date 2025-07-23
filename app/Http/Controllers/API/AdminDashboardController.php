<?php

namespace App\Http\Controllers\API;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Ride;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Session;
use Illuminate\Support\Str;

class AdminDashboardController extends Controller
{
    // Admin configurations
    const ADMIN_CONFIGS = [
        'primary' => [
            'email' => 'twisrmann2002@gmail.com',
            'password' => 'arayaz8152002',
            'phone' => '0912345678',
            'first_name' => 'Admin',
            'last_name' => 'User',
            'wallet_prefix' => 'ADM'
        ],
        'sycash' => [
            'email' => 'sycash-sim@gmail.com',
            'password' => 'sycash123456',
            'phone' => '0987654321',
            'first_name' => 'SyCash',
            'last_name' => 'Admin',
            'wallet_prefix' => 'SYC'
        ]
    ];

    /**
     * Admin login
     * POST /admin/login
     */
    private function isPrimaryAdmin(): bool
{
    return Session::get('admin_email') === self::ADMIN_CONFIGS['primary']['email'];
}
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

        // Find which admin is logging in
        $adminConfig = $this->getAdminConfig($request->email, $request->password);

        if (!$adminConfig) {
            return response()->json([
                'status' => 'error',
                'code' => 'INVALID_CREDENTIALS',
                'message' => 'Invalid admin credentials'
            ], 401);
        }

        // Set admin session
        Session::put('admin_logged_in', true);
        Session::put('admin_email', $adminConfig['email']);
        Session::put('admin_type', $adminConfig['type']);
        Session::save();

        // Create admin wallet if it doesn't exist
        $this->createAdminWalletIfNotExists($adminConfig);

        return response()->json([
            'status' => 'success',
            'message' => 'Login successful',
            'admin_type' => $adminConfig['type'],
            'session_id' => Session::getId()
        ]);
    }

    /**
     * Get admin configuration by email and password
     */
    private function getAdminConfig($email, $password)
    {
        foreach (self::ADMIN_CONFIGS as $type => $config) {
            if ($config['email'] === $email && $config['password'] === $password) {
                return array_merge($config, ['type' => $type]);
            }
        }
        return null;
    }

    /**
     * Get current admin configuration
     */
    private function getCurrentAdminConfig()
    {
        $adminEmail = Session::get('admin_email');
        $adminType = Session::get('admin_type');

        if (!$adminEmail || !$adminType || !isset(self::ADMIN_CONFIGS[$adminType])) {
            return null;
        }

        return array_merge(self::ADMIN_CONFIGS[$adminType], ['type' => $adminType]);
    }

    /**
     * Create admin wallet if it doesn't exist
     */
    private function createAdminWalletIfNotExists($adminConfig)
    {
        try {
            // Check if admin wallet already exists
            $adminWallet = Wallet::where('phone_number', $adminConfig['phone'])->first();

            if ($adminWallet) {
                Log::info('Admin wallet already exists', [
                    'wallet_id' => $adminWallet->id,
                    'phone' => $adminConfig['phone'],
                    'admin_type' => $adminConfig['type']
                ]);
                return;
            }

            DB::beginTransaction();

            // Create or find admin user
            $adminUser = User::firstOrCreate(
                ['email' => $adminConfig['email']],
                [
                    'first_name' => $adminConfig['first_name'],
                    'last_name' => $adminConfig['last_name'],
                    'phone_number' => $adminConfig['phone'],
                    'email_verified_at' => now(),
                    'password' => bcrypt($adminConfig['password'])
                ]
            );

            // Generate unique wallet number
            $walletNumber = $this->generateWalletNumber($adminConfig['wallet_prefix']);

            // Create admin wallet
            $adminWallet = Wallet::create([
                'user_id' => $adminUser->id,
                'wallet_number' => $walletNumber,
                'phone_number' => $adminConfig['phone'],
                'balance' => 0.00
            ]);

            // Update user with wallet_id (if your schema supports it)
            if ($adminUser->wallet_id === null) {
                $adminUser->wallet_id = $adminWallet->id;
                $adminUser->save();
            }

            // Create initial transaction record
            WalletTransaction::create([
                'wallet_id' => $adminWallet->id,
                'user_id' => $adminUser->id,
                'type' => 'admin_credit',
                'amount' => 0.00,
                'previous_balance' => 0.00,
                'new_balance' => 0.00,
                'description' => ucfirst($adminConfig['type']) . ' admin wallet creation',
                'transaction_id' => $adminConfig['wallet_prefix'].'_INIT_'.time().'_'.Str::random(8),
                'status' => 'completed',
                'metadata' => [
                    'admin_email' => $adminConfig['email'],
                    'admin_type' => $adminConfig['type'],
                    'creation_type' => 'auto_generated',
                    'created_at' => now()
                ]
            ]);

            DB::commit();

            Log::info('Admin wallet created successfully', [
                'wallet_id' => $adminWallet->id,
                'wallet_number' => $walletNumber,
                'phone' => $adminConfig['phone'],
                'user_id' => $adminUser->id,
                'admin_type' => $adminConfig['type']
            ]);

        } catch (\Exception $e) {
            DB::rollBack();
            Log::error('Failed to create admin wallet: '.$e->getMessage(), [
                'phone' => $adminConfig['phone'],
                'email' => $adminConfig['email'],
                'admin_type' => $adminConfig['type'],
                'trace' => $e->getTraceAsString()
            ]);
        }
    }

    /**
     * Generate unique wallet number
     */
    private function generateWalletNumber($prefix = 'ADM')
    {
        do {
            $walletNumber = $prefix.str_pad(rand(0, 9999999999), 10, '0', STR_PAD_LEFT);
        } while (Wallet::where('wallet_number', $walletNumber)->exists());

        return $walletNumber;
    }

    /**
     * Admin logout
     * GET /admin/logout
     */
    public function logout()
    {
        Session::forget('admin_logged_in');
        Session::forget('admin_email');
        Session::forget('admin_type');

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
     * Get current admin info
     * GET /admin/info
     */
    public function getAdminInfo()
    {
        if (!$this->validateAdminSession()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        $adminConfig = $this->getCurrentAdminConfig();

        if (!$adminConfig) {
            return response()->json([
                'status' => 'error',
                'code' => 'ADMIN_CONFIG_ERROR',
                'message' => 'Admin configuration not found'
            ], 500);
        }

        return response()->json([
            'status' => 'success',
            'admin' => [
                'email' => $adminConfig['email'],
                'type' => $adminConfig['type'],
                'name' => $adminConfig['first_name'] . ' ' . $adminConfig['last_name'],
                'phone' => $adminConfig['phone']
            ]
        ]);
    }

    /**
     * Get current admin wallet info
     * GET /admin/wallet
     */
    public function getAdminWallet()
    {
        if (!$this->validateAdminSession()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        $adminConfig = $this->getCurrentAdminConfig();

        if (!$adminConfig) {
            return response()->json([
                'status' => 'error',
                'code' => 'ADMIN_CONFIG_ERROR',
                'message' => 'Admin configuration not found'
            ], 500);
        }

        $adminWallet = Wallet::where('phone_number', $adminConfig['phone'])
            ->with('user:id,first_name,last_name,email')
            ->first();

        if (!$adminWallet) {
            return response()->json([
                'status' => 'error',
                'code' => 'WALLET_NOT_FOUND',
                'message' => 'Admin wallet not found'
            ], 404);
        }

        return response()->json([
            'status' => 'success',
            'wallet' => [
                'id' => $adminWallet->id,
                'wallet_number' => $adminWallet->wallet_number,
                'phone_number' => $adminWallet->phone_number,
                'balance' => $adminWallet->balance,
                'owner' => $adminWallet->user->first_name.' '.$adminWallet->user->last_name,
                'admin_type' => $adminConfig['type'],
                'created_at' => $adminWallet->created_at,
                'updated_at' => $adminWallet->updated_at
            ]
        ]);
    }

    /**
     * Get all admin wallets (for super admin view)
     * GET /admin/wallets/admins
     */
    public function getAdminWallets()
    {
        if (!$this->validateAdminSession()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        $adminPhones = array_column(self::ADMIN_CONFIGS, 'phone');

        $adminWallets = Wallet::whereIn('phone_number', $adminPhones)
            ->with('user:id,first_name,last_name,email')
            ->get();

        $walletsWithType = $adminWallets->map(function ($wallet) {
            $adminType = null;
            foreach (self::ADMIN_CONFIGS as $type => $config) {
                if ($config['phone'] === $wallet->phone_number) {
                    $adminType = $type;
                    break;
                }
            }

            return [
                'id' => $wallet->id,
                'wallet_number' => $wallet->wallet_number,
                'phone_number' => $wallet->phone_number,
                'balance' => $wallet->balance,
                'owner' => $wallet->user->first_name.' '.$wallet->user->last_name,
                'admin_type' => $adminType,
                'created_at' => $wallet->created_at,
                'updated_at' => $wallet->updated_at
            ];
        });

        return response()->json([
            'status' => 'success',
            'admin_wallets' => $walletsWithType
        ]);
    }

    /**
     * Charge wallet
     * POST /admin/wallet/charge
     */
    public function chargeWallet(Request $request)
    {if (!$this->isPrimaryAdmin()) {
        return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
    }
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

            // Get current admin config
            $adminConfig = $this->getCurrentAdminConfig();
            $adminType = $adminConfig ? $adminConfig['type'] : 'unknown';

            // Create transaction
            $transaction = WalletTransaction::create([
                'wallet_id' => $wallet->id,
                'user_id' => $wallet->user_id,
                'type' => 'admin_credit',
                'amount' => $request->amount,
                'previous_balance' => $previousBalance,
                'new_balance' => $newBalance,
                'description' => 'Admin wallet charge by ' . $adminType,
                'transaction_id' => strtoupper($adminType).'_'.time().'_'.Str::random(8),
                'status' => 'completed',
                'metadata' => [
                    'admin_email' => Session::get('admin_email'),
                    'admin_type' => $adminType,
                    'ip_address' => $request->ip()
                ]
            ]);

            DB::commit();

            return response()->json([
                'status' => 'success',
                'message' => 'Wallet charged successfully',
                'transaction_id' => $transaction->transaction_id,
                'charged_by' => $adminType,
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
                'amount' => $request->amount,
                'admin_type' => $adminType ?? 'unknown'
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

        $adminConfig = $this->getCurrentAdminConfig();

        $stats = [
            'total_wallets' => Wallet::count(),
            'total_users' => User::count(),
            'total_balance' => Wallet::sum('balance'),
            'total_transactions' => WalletTransaction::count(),
            'current_admin' => [
                'type' => $adminConfig['type'],
                'name' => $adminConfig['first_name'] . ' ' . $adminConfig['last_name'],
                'wallet' => Wallet::where('phone_number', $adminConfig['phone'])
                    ->with('user:id,first_name,last_name')
                    ->first()
            ],
            'all_admin_wallets' => Wallet::whereIn('phone_number', array_column(self::ADMIN_CONFIGS, 'phone'))
                ->with('user:id,first_name,last_name')
                ->get(),
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
            'admin_logged_in' => Session::get('admin_logged_in', false),
            'admin_configs' => array_map(function($config) {
                return array_merge($config, ['password' => '***hidden***']);
            }, self::ADMIN_CONFIGS)
        ]);
    }
    public function showReport(Request $request)
    {if (!$this->isPrimaryAdmin()) {
        return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
    }
        if (!$this->validateAdminSession()) {
            return response()->json([
                'status' => 'error',
                'code' => 'AUTH_REQUIRED',
                'message' => 'Admin session expired or invalid'
            ], 401);
        }

        // Validate date inputs
        $validator = Validator::make($request->all(), [
            'start_date' => 'nullable|date_format:Y-m-d',
            'end_date' => 'nullable|date_format:Y-m-d|after_or_equal:start_date',
        ]);

        if ($validator->fails()) {
            return response()->json([
                'status' => 'error',
                'code' => 'VALIDATION_FAILED',
                'errors' => $validator->errors()
            ], 422);
        }

        try {
            $startDate = $request->input('start_date');
            $endDate = $request->input('end_date');

            // Convert to Carbon if provided
            $startDateObj = $startDate ? Carbon::parse($startDate)->startOfDay() : null;
            $endDateObj = $endDate ? Carbon::parse($endDate)->endOfDay() : null;

            // Get admin wallets
            $syCashWallet = Wallet::where('phone_number', self::ADMIN_CONFIGS['sycash']['phone'])->first();
            $adminWallet = Wallet::where('phone_number', self::ADMIN_CONFIGS['primary']['phone'])->first();

            if (!$syCashWallet || !$adminWallet) {
                throw new \Exception('Admin wallets not found');
            }

            // Ride statistics
            $ridesQuery = Ride::query();

            if ($startDateObj && $endDateObj) {
                $ridesQuery->whereBetween('created_at', [$startDateObj, $endDateObj]);
            }

            $rideStats = [
                'total' => $ridesQuery->count(),
                'canceled' => (clone $ridesQuery)->where('status', 'canceled')->count(),
                'active' => (clone $ridesQuery)->where('status', 'active')->count(),
                'awaiting_confirmation' => (clone $ridesQuery)->where('status', 'awaiting_confirmation')->count(),
                'completed' => (clone $ridesQuery)->where('status', 'finished')->count(),
            ];

            // Financial statistics - COMPREHENSIVE FIX
            $syCashFeeQuery = WalletTransaction::where('wallet_id', $syCashWallet->id)
                ->where('type', 'ride_creation_fee');

            $adminCollectionQuery = WalletTransaction::where('wallet_id', $adminWallet->id)
                ->where('type', 'ride_booking_received')
                ->where('amount', '>', 0);

            // FIXED: More comprehensive query for admin transfers
            // Check for multiple possible transaction types that represent driver payments
            $adminTransferQuery = WalletTransaction::where('wallet_id', $adminWallet->id)
                ->where(function($query) {
                    $query->where('type', 'ride_completion_payment')
                        ->orWhere('type', 'payment_to_driver')
                        ->orWhere('type', 'driver_payment')
                        ->orWhere('type', 'ride_payout')
                        ->orWhere('type', 'driver_payout')
                        ->orWhere('type', 'admin_debit');
                })
                ->where('amount', '<', 0);

            // Alternative approach: Look for transfers by description pattern
            $adminTransferByDescQuery = WalletTransaction::where('wallet_id', $adminWallet->id)
                ->where(function($query) {
                    $query->where('description', 'LIKE', '%payment to driver%')
                        ->orWhere('description', 'LIKE', '%driver payout%')
                        ->orWhere('description', 'LIKE', '%ride completion%')
                        ->orWhere('description', 'LIKE', '%transfer to driver%');
                })
                ->where('amount', '<', 0);

            // Apply date filters if provided
            if ($startDateObj && $endDateObj) {
                $syCashFeeQuery->whereBetween('created_at', [$startDateObj, $endDateObj]);
                $adminCollectionQuery->whereBetween('created_at', [$startDateObj, $endDateObj]);
                $adminTransferQuery->whereBetween('created_at', [$startDateObj, $endDateObj]);
                $adminTransferByDescQuery->whereBetween('created_at', [$startDateObj, $endDateObj]);
            }

            // Calculate transfer amounts using both methods
            $transferByType = abs((float) $adminTransferQuery->sum('amount'));
            $transferByDesc = abs((float) $adminTransferByDescQuery->sum('amount'));

            // Use the higher value (more comprehensive result)
            $totalTransferred = max($transferByType, $transferByDesc);

            // If still zero, try a different approach - calculate from driver wallets
            if ($totalTransferred == 0) {
                // Look for payments received by drivers from admin
                $driverPaymentsQuery = WalletTransaction::where('type', 'ride_completion_received')
                    ->orWhere('type', 'driver_payment_received')
                    ->orWhere('type', 'ride_payout_received')
                    ->where('amount', '>', 0);

                if ($startDateObj && $endDateObj) {
                    $driverPaymentsQuery->whereBetween('created_at', [$startDateObj, $endDateObj]);
                }

                $totalTransferred = (float) $driverPaymentsQuery->sum('amount');
            }

            // Debug information - get all admin wallet transactions for analysis
            $allAdminTransactions = WalletTransaction::where('wallet_id', $adminWallet->id);

            if ($startDateObj && $endDateObj) {
                $allAdminTransactions->whereBetween('created_at', [$startDateObj, $endDateObj]);
            }

            $transactionTypes = $allAdminTransactions->selectRaw('type, COUNT(*) as count, SUM(amount) as total_amount')
                ->groupBy('type')
                ->get()
                ->keyBy('type');

            // Log debug information
            Log::info('Admin wallet transaction analysis:', [
                'wallet_id' => $adminWallet->id,
                'transaction_types' => $transactionTypes->toArray(),
                'transfer_by_type' => $transferByType,
                'transfer_by_desc' => $transferByDesc,
                'final_transferred' => $totalTransferred
            ]);

            $financialStats = [
                'sycash' => [
                    'current_balance' => (float) $syCashWallet->balance,
                    'total_ride_creation_fees' => (float) $syCashFeeQuery->sum('amount'),
                ],
                'admin_wallet' => [
                    'current_balance' => (float) $adminWallet->balance,
                    'total_booking_collected' => (float) $adminCollectionQuery->sum('amount'),
                    'total_booking_transferred' => $totalTransferred,
                ]
            ];

            // Active ride amounts calculation
            $activeRideAmount = Booking::whereHas('ride', function ($query) {
                $query->whereIn('status', ['active', 'awaiting_confirmation']);
            })
                ->join('rides', 'bookings.ride_id', '=', 'rides.id')
                ->sum(DB::raw('bookings.seats * rides.price_per_seat'));

            $financialStats['active_rides_amount'] = (float) $activeRideAmount;

            // Add debug information to response (remove in production)
            if (config('app.debug')) {
                $financialStats['debug_info'] = [
                    'transfer_calculation_methods' => [
                        'by_transaction_type' => $transferByType,
                        'by_description' => $transferByDesc,
                        'final_used' => $totalTransferred
                    ],
                    'admin_transaction_types' => $transactionTypes->map(function($type) {
                        return [
                            'count' => $type->count,
                            'total_amount' => (float) $type->total_amount
                        ];
                    })
                ];
            }

            // Format date range for response
            $dateRange = [
                'start' => $startDateObj ? $startDateObj->format('Y-m-d H:i:s') : null,
                'end' => $endDateObj ? $endDateObj->format('Y-m-d H:i:s') : null
            ];

            return response()->json([
                'status' => 'success',
                'report_data' => [
                    'ride_stats' => $rideStats,
                    'financial_stats' => $financialStats,
                    'date_range' => $dateRange
                ]
            ]);

        } catch (\Exception $e) {
            Log::error('Admin report generation failed: '.$e->getMessage(), [
                'start_date' => $startDate ?? null,
                'end_date' => $endDate ?? null,
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'status' => 'error',
                'code' => 'REPORT_ERROR',
                'message' => 'Failed to generate report',
                'system_message' => config('app.debug') ? $e->getMessage() : null
            ], 500);
        }
    }
    /**
     * List pending passenger & driver verification requests
     * GET /admin/verifications/pending
     */
    public function pendingVerifications(Request $request)
    {
        if (!$this->isPrimaryAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
        }
        if (!$this->validateAdminSession()) {
            return response()->json(['status' => 'error', 'message' => 'Admin session expired'], 401);
        }

        $pending = User::with(['photos', 'profile'])
            ->where('verification_status', 'pending')
            ->get()
            ->map(function ($u) {
                // Determine verification type based on submitted documents
                $documentTypes = $u->photos->pluck('type')->toArray();
                $isDriver = in_array('license', $documentTypes) ||
                    in_array('mechanic_card', $documentTypes) ||
                    !empty($u->profile->car_pic);

                return [
                    'user_id'     => $u->id,
                    'name'        => trim($u->first_name . ' ' . $u->last_name),
                    'email'       => $u->email,
                    'type'        => $isDriver ? 'driver' : 'passenger',
                    'documents'   => $u->photos->map(fn ($p) => [
                        'type' => $p->type,
                        'url'  => asset("storage/{$p->path}")
                    ]),
                    'vehicle'     => optional($u->profile)->only([
                        'type_of_car', 'color_of_car', 'number_of_seats', 'car_pic'
                    ]),
                    'created_at'  => $u->updated_at->toIso8601String()
                ];
            });

        return response()->json(['success' => true, 'data' => $pending]);
    }
    /**
     * Approve a passenger or driver verification
     * POST /admin/verifications/{userId}/approve
     */
    public function approveVerification(Request $request, int $userId)
    {
        if (!$this->isPrimaryAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
        }
        if (!$this->validateAdminSession()) {
            return response()->json(['status' => 'error', 'message' => 'Admin session expired'], 401);
        }

        try {
            $user = User::with(['photos', 'profile'])->findOrFail($userId);
            $repo = app(\App\Interfaces\VerificationRepositoryInterface::class);

            // Automatically determine verification type
            $documentTypes = $user->photos->pluck('type')->toArray();
            $isDriver = in_array('license', $documentTypes) ||
                in_array('mechanic_card', $documentTypes) ||
                !empty($user->profile->car_pic);

            $type = $isDriver ? 'driver' : 'passenger';

            $verifiedUser = $type === 'passenger'
                ? $repo->verifyPassenger($userId)
                : $repo->verifyDriver($userId);

            return response()->json([
                'success' => true,
                'message' => ucfirst($type) . ' verification approved',
                'user'    => [
                    'id' => $verifiedUser->id,
                    'verification_status' => $verifiedUser->verification_status,
                    'is_verified_passenger' => $verifiedUser->is_verified_passenger,
                    'is_verified_driver' => $verifiedUser->is_verified_driver,
                ]
            ]);
        } catch (\Exception $e) {
            return response()->json(['success' => false, 'message' => $e->getMessage()], 422);
        }
    }
    /**
     * Reject a verification request
     * POST /admin/verifications/{userId}/reject
     */
    public function rejectVerification(Request $request, int $userId)
    {
        if (!$this->isPrimaryAdmin()) {
            return response()->json(['status' => 'error', 'message' => 'Access denied'], 403);
        }
        if (!$this->validateAdminSession()) {
            return response()->json(['status' => 'error', 'message' => 'Admin session expired'], 401);
        }

        $user = User::with(['photos', 'profile'])->findOrFail($userId);

        // Automatically determine verification type
        $documentTypes = $user->photos->pluck('type')->toArray();
        $isDriver = in_array('license', $documentTypes) ||
            in_array('mechanic_card', $documentTypes) ||
            !empty($user->profile->car_pic);

        $type = $isDriver ? 'driver' : 'passenger';

        $user->update([
            'verification_status' => 'rejected',
            'is_verified_passenger' => false,
            'is_verified_driver' => false,
        ]);

        return response()->json([
            'success' => true,
            'message' => ucfirst($type) . ' verification rejected',
            'user'    => [
                'id' => $user->id,
                'verification_status' => $user->verification_status,
                'is_verified_passenger' => $user->is_verified_passenger,
                'is_verified_driver' => $user->is_verified_driver,
            ]
        ]);
    }
}

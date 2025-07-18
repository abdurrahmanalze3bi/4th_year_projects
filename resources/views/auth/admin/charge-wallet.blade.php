@extends('layouts.admin')

@section('content')
    <div class="container">
        <h2>Charge Wallet</h2>

        @if(session('success'))
            <div class="alert alert-success">
                {{ session('success') }}
                @if(session('wallet'))
                    <div class="mt-3">
                        <strong>Wallet Number:</strong> {{ session('wallet')->wallet_number }}<br>
                        <strong>Phone:</strong> {{ session('wallet')->phone_number }}<br>
                        <strong>Charged Amount:</strong> {{ number_format(session('chargedAmount'), 2) }}<br>
                        <strong>New Balance:</strong> {{ number_format(session('wallet')->balance, 2) }}
                    </div>
                @endif
            </div>
        @endif

        @if(session('error'))
            <div class="alert alert-danger">{{ session('error') }}</div>
        @endif

        <form method="POST" action="{{ route('admin.charge.submit') }}">
            @csrf

            <div class="form-group">
                <label>Phone Number</label>
                <input type="text" name="phone_number" class="form-control" required>
            </div>

            <div class="form-group">
                <label>Amount</label>
                <input type="number" step="0.01" min="1" name="amount" class="form-control" required>
            </div>

            <button type="submit" class="btn btn-primary">Charge Wallet</button>
        </form>
    </div>
@endsection

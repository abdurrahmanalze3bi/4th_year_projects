<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reset Password</title>
    <style>
        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background-color: #f8f9fa;
            margin: 0;
            padding: 0;
            display: flex;
            justify-content: center;
            align-items: center;
            min-height: 100vh;
        }

        .reset-container {
            background: white;
            padding: 2rem;
            border-radius: 8px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.1);
            width: 100%;
            max-width: 400px;
        }

        h1 {
            color: #2d3748;
            text-align: center;
            margin-bottom: 1.5rem;
            font-size: 1.5rem;
        }

        .form-group {
            margin-bottom: 1.5rem;
        }

        label {
            display: block;
            margin-bottom: 0.5rem;
            color: #4a5568;
            font-weight: 500;
        }

        input {
            width: 100%;
            padding: 0.75rem;
            border: 1px solid #e2e8f0;
            border-radius: 4px;
            font-size: 1rem;
        }

        input:focus {
            outline: none;
            border-color: #4299e1;
            box-shadow: 0 0 0 3px rgba(66,153,225,0.1);
        }

        button {
            width: 100%;
            padding: 0.75rem;
            background-color: #4299e1;
            color: white;
            border: none;
            border-radius: 4px;
            font-size: 1rem;
            font-weight: 500;
            cursor: pointer;
            transition: background-color 0.2s;
        }

        button:hover {
            background-color: #3182ce;
        }

        .alert {
            padding: 1rem;
            border-radius: 4px;
            margin-bottom: 1.5rem;
            display: none;
        }

        .alert-success {
            background-color: #f0fff4;
            color: #38a169;
            border: 1px solid #c6f6d5;
        }

        .alert-error {
            background-color: #fff5f5;
            color: #e53e3e;
            border: 1px solid #fed7d7;
        }

        .loading {
            position: relative;
            pointer-events: none;
            opacity: 0.7;
        }

        .loading::after {
            content: "";
            position: absolute;
            right: 1rem;
            top: 50%;
            transform: translateY(-50%);
            width: 1.25rem;
            height: 1.25rem;
            border: 3px solid rgba(255,255,255,0.3);
            border-top-color: white;
            border-radius: 50%;
            animation: spin 1s linear infinite;
        }

        @keyframes spin {
            to { transform: rotate(360deg); }
        }
    </style>
</head>
<body>
<div class="reset-container">
    <h1>Reset Your Password</h1>

    <div id="alert" class="alert"></div>

    <form id="resetForm">
        <input type="hidden" name="token" value="{{ $token }}">

        <div class="form-group">
            <label for="email">Email</label>
            <input
                type="email"
                id="email"
                name="email"
                value="{{ $email }}"
                readonly
                aria-readonly="true"
            >
        </div>

        <div class="form-group">
            <label for="password">New Password</label>
            <input
                type="password"
                id="password"
                name="password"
                required
                minlength="8"
                placeholder="Enter new password"
            >
        </div>

        <div class="form-group">
            <label for="password_confirmation">Confirm Password</label>
            <input
                type="password"
                id="password_confirmation"
                name="password_confirmation"
                required
                minlength="8"
                placeholder="Confirm new password"
            >
        </div>

        <button type="submit" id="submitBtn">
            Reset Password
        </button>
    </form>
</div>

<script>
    const form = document.getElementById('resetForm');
    const alertDiv = document.getElementById('alert');
    const submitBtn = document.getElementById('submitBtn');

    form.addEventListener('submit', async (e) => {
        e.preventDefault();
        submitBtn.classList.add('loading');
        alertDiv.style.display = 'none';

        try {
            const response = await fetch('/api/reset-password', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/json',
                    'Accept': 'application/json'
                },
                body: JSON.stringify({
                    token: form.token.value,
                    email: form.email.value,
                    password: form.password.value,
                    password_confirmation: form.password_confirmation.value
                })
            });

            const data = await response.json();

            if (response.ok) {
                showAlert('Password reset successfully! Redirecting to login...', 'success');
                setTimeout(() => {
                    window.location.href = '/login';
                }, 2000);
            } else {
                const errorMessage = data.errors
                    ? Object.values(data.errors).join(' ')
                    : data.message || 'Password reset failed';
                showAlert(errorMessage, 'error');
            }
        } catch (error) {
            showAlert('Network error. Please check your connection and try again.', 'error');
            console.error('Reset error:', error);
        } finally {
            submitBtn.classList.remove('loading');
        }
    });

    function showAlert(message, type = 'error') {
        alertDiv.textContent = message;
        alertDiv.className = `alert alert-${type}`;
        alertDiv.style.display = 'block';

        // Auto-hide success messages after 5 seconds
        if (type === 'success') {
            setTimeout(() => {
                alertDiv.style.display = 'none';
            }, 5000);
        }
    }
</script>
</body>
</html>

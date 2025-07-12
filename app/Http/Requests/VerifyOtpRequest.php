<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class VerifyOtpRequest extends FormRequest
{
    /**
     * Determine if the user is authorized to make this request.
     */
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Get the validation rules that apply to the request.
     */
    public function rules(): array
    {
        return [
            'phone_number' => [
                'required',
                'string',
                'regex:/^(\+963|963|0)?9[0-9]{8}$/',
            ],
            'otp_code' => [
                'required',
                'string',
                'size:6',
                'regex:/^[0-9]{6}$/'
            ]
        ];
    }

    /**
     * Get custom messages for validator errors.
     */
    public function messages(): array
    {
        return [
            'phone_number.required' => 'Phone number is required',
            'phone_number.regex' => 'Invalid Syrian phone number format. Use format: 09XXXXXXXX or +96309XXXXXXXX',
            'otp_code.required' => 'OTP code is required',
            'otp_code.size' => 'OTP code must be exactly 6 digits',
            'otp_code.regex' => 'OTP code must contain only numbers'
        ];
    }
}

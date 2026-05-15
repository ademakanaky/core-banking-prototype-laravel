<?php

declare(strict_types=1);

namespace App\Http\Requests\MobilePayment;

use App\Domain\MobilePayment\Enums\PaymentAsset;
use App\Domain\MobilePayment\Enums\PaymentNetwork;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class CreatePaymentIntentRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, mixed>
     */
    public function rules(): array
    {
        return [
            'merchantId'       => ['required', 'string', 'max:64'],
            'amount'           => ['required', 'numeric', 'gt:0', 'max:999999999'],
            'asset'            => ['required', 'string', Rule::in(PaymentAsset::values())],
            'preferredNetwork' => ['required', 'string', Rule::in(PaymentNetwork::values())],
            'shield'           => ['sometimes', 'boolean'],
            'idempotencyKey'   => ['sometimes', 'string', 'max:128'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return [
            'asset.in'            => 'Supported assets: ' . implode(', ', PaymentAsset::values()) . '.',
            'preferredNetwork.in' => 'Supported networks: ' . implode(', ', PaymentNetwork::values()) . '.',
            'amount.gt'           => 'Payment amount must be greater than zero.',
        ];
    }
}

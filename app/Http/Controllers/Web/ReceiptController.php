<?php

declare(strict_types=1);

namespace App\Http\Controllers\Web;

use App\Domain\MobilePayment\Models\PaymentReceipt;
use App\Http\Controllers\Controller;
use Illuminate\Contracts\View\View;

/**
 * Public, shareable payment-receipt page.
 *
 * The URL embeds the receipt's unguessable `share_token` (64 random chars),
 * so the page needs no authentication — it is the target of the
 * `sharePayload` link returned by the mobile receipt endpoint.
 */
class ReceiptController extends Controller
{
    public function show(string $shareToken): View
    {
        $receipt = PaymentReceipt::where('share_token', $shareToken)->first();

        if ($receipt === null) {
            abort(404);
        }

        return view('receipts.show', [
            'receipt' => $receipt,
            'forPdf'  => false,
        ]);
    }
}

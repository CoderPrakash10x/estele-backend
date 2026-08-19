<?php

namespace App\Http\Controllers;

use App\Models\Order;
use App\Models\Payment;
use Illuminate\Http\Request;
use Razorpay\Api\Api;

class PaymentController extends Controller
{
    // Step 1: Razorpay order create karo (order create hone ke baad frontend ye call karega)
    public function createOrder(Request $request)
    {
        $request->validate(['order_id' => 'required|exists:orders,id']);

        $order = Order::find($request->order_id);

        $api = new Api(config('services.razorpay.key'), config('services.razorpay.secret'));

        // Razorpay paise "paise" me leta hai (rupee x 100), not rupees directly
        $razorpayOrder = $api->order->create([
            'receipt' => 'order_' . $order->id,
            'amount' => $order->total_amount * 100,
            'currency' => 'INR',
        ]);

        Payment::create([
            'order_id' => $order->id,
            'razorpay_order_id' => $razorpayOrder['id'],
            'amount' => $order->total_amount,
            'status' => 'pending',
        ]);

        return response()->json([
            'razorpay_order_id' => $razorpayOrder['id'],
            'amount' => $order->total_amount * 100,
            'currency' => 'INR',
            'key' => config('services.razorpay.key'),
        ]);
    }

    // Step 2: Payment ke baad verify karo (Razorpay checkout popup se React ye data bhejega)
    public function verify(Request $request)
    {
        $request->validate([
            'razorpay_order_id' => 'required|string',
            'razorpay_payment_id' => 'required|string',
            'razorpay_signature' => 'required|string',
        ]);

        $api = new Api(config('services.razorpay.key'), config('services.razorpay.secret'));

        try {
            $api->utility->verifyPaymentSignature([
                'razorpay_order_id' => $request->razorpay_order_id,
                'razorpay_payment_id' => $request->razorpay_payment_id,
                'razorpay_signature' => $request->razorpay_signature,
            ]);

            $payment = Payment::where('razorpay_order_id', $request->razorpay_order_id)->first();

            if (!$payment) {
                return response()->json(['message' => 'Payment record not found'], 404);
            }

            $payment->update([
                'razorpay_payment_id' => $request->razorpay_payment_id,
                'razorpay_signature' => $request->razorpay_signature,
                'status' => 'success',
            ]);

            $payment->order->update(['status' => 'paid']);

            return response()->json(['message' => 'Payment verified successfully']);

        } catch (\Exception $e) {
            return response()->json(['message' => 'Payment verification failed'], 400);
        }
    }
}
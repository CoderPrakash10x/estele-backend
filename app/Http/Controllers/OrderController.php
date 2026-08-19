<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\Order;
use App\Models\OrderItem;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class OrderController extends Controller
{
    // Checkout: cart -> order
    public function checkout(Request $request)
    {
        $request->validate([
            'name' => 'required|string|max:255',
            'phone' => 'required|string|max:15',
            'address' => 'required|string',
            'city' => 'required|string',
            'state' => 'required|string',
            'pincode' => 'required|string',
        ]);

        $sessionId = $request->header('X-Session-Id');
        if (!$sessionId) {
            return response()->json(['message' => 'Session ID required'], 400);
        }

        $cart = Cart::where('session_id', $sessionId)->with('items.product')->first();

        if (!$cart || $cart->items->isEmpty()) {
            return response()->json(['message' => 'Cart is empty'], 400);
        }

        // Stock check
        foreach ($cart->items as $item) {
            if ($item->product->stock < $item->quantity) {
                return response()->json([
                    'message' => "Insufficient stock for {$item->product->name}"
                ], 400);
            }
        }

        $totalAmount = $cart->items->sum(function ($item) {
            $price = $item->product->discount_price ?? $item->product->price;
            return $price * $item->quantity;
        });

        // DB transaction: sab ek saath success ho ya sab fail ho
        $order = DB::transaction(function () use ($cart, $totalAmount, $request, $sessionId) {
            $order = Order::create([
                  'session_id' => $sessionId,
                'total_amount' => $totalAmount,
                'status' => 'pending',
                'shipping_address' => [
                    'name' => $request->name,
                    'phone' => $request->phone,
                    'address' => $request->address,
                    'city' => $request->city,
                    'state' => $request->state,
                    'pincode' => $request->pincode,
                ],
            ]);

            foreach ($cart->items as $item) {
                $price = $item->product->discount_price ?? $item->product->price;

                OrderItem::create([
                    
                    'order_id' => $order->id,
                    'product_id' => $item->product_id,
                    'quantity' => $item->quantity,
                    'price' => $price,
                ]);

                // stock reduce karo
                $item->product->decrement('stock', $item->quantity);
            }

            // cart clear karo
            $cart->items()->delete();

            return $order;
        });

        return response()->json($order->load('items.product'), 201);
    }

    // Order details (payment success ke baad ya order history dekhne ke liye)
    public function show($id)
    {
        $order = Order::with('items.product', 'payment')->find($id);

        if (!$order) {
            return response()->json(['message' => 'Order not found'], 404);
        }

        return response()->json($order);
    }

    public function myOrders(Request $request)
{
    $sessionId = $request->header('X-Session-Id');
    if (!$sessionId) {
        return response()->json(['message' => 'Session ID required'], 400);
    }

    $orders = Order::where('session_id', $sessionId)
        ->with('items.product', 'payment')
        ->latest()
        ->get();

    return response()->json($orders);
}
}
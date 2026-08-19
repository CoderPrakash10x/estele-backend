<?php

namespace App\Http\Controllers;

use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Product;
use Illuminate\Http\Request;

class CartController extends Controller
{
    // Helper: cart find karo ya naya banao session_id ke basis pe
    private function getOrCreateCart($sessionId)
    {
        return Cart::firstOrCreate(['session_id' => $sessionId]);
    }

    // View cart
    public function index(Request $request)
    {
        $sessionId = $request->header('X-Session-Id');

        if (!$sessionId) {
            return response()->json(['message' => 'Session ID required'], 400);
        }

        $cart = Cart::where('session_id', $sessionId)
            ->with('items.product')
            ->first();

        if (!$cart) {
            return response()->json(['items' => [], 'total' => 0]);
        }

        $total = $cart->items->sum(function ($item) {
            $price = $item->product->discount_price ?? $item->product->price;
            return $price * $item->quantity;
        });

        return response()->json([
            'cart_id' => $cart->id,
            'items' => $cart->items,
            'total' => $total,
        ]);
    }

    // Add to cart
    public function add(Request $request)
    {
        $request->validate([
            'product_id' => 'required|exists:products,id',
            'quantity' => 'required|integer|min:1',
        ]);

        $sessionId = $request->header('X-Session-Id');
        if (!$sessionId) {
            return response()->json(['message' => 'Session ID required'], 400);
        }

        $cart = $this->getOrCreateCart($sessionId);

        $cartItem = CartItem::where('cart_id', $cart->id)
            ->where('product_id', $request->product_id)
            ->first();

        if ($cartItem) {
            $cartItem->quantity += $request->quantity;
            $cartItem->save();
        } else {
            $cartItem = CartItem::create([
                'cart_id' => $cart->id,
                'product_id' => $request->product_id,
                'quantity' => $request->quantity,
            ]);
        }

        return response()->json($cartItem->load('product'), 201);
    }

    // Update quantity
    public function update(Request $request, $id)
    {
        $request->validate(['quantity' => 'required|integer|min:1']);

        $cartItem = CartItem::find($id);
        if (!$cartItem) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $cartItem->update(['quantity' => $request->quantity]);
        return response()->json($cartItem->load('product'));
    }

    // Remove item
    public function remove($id)
    {
        $cartItem = CartItem::find($id);
        if (!$cartItem) {
            return response()->json(['message' => 'Item not found'], 404);
        }

        $cartItem->delete();
        return response()->json(['message' => 'Item removed']);
    }
}
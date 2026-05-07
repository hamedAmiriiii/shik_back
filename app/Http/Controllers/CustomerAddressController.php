<?php

namespace App\Http\Controllers;

use App\Models\CustomerAddress;
use App\Models\State;
use App\Models\City;
use Illuminate\Http\Request;

class CustomerAddressController extends Controller
{
    /**
     * لیست تمام آدرس‌های مشتری
     */
    public function index(Request $request)
    {
        $customer = $request->user();
        
        $addresses = CustomerAddress::where('customer_id', $customer->id)
            ->with(['state', 'city'])
            ->orderBy('is_default', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response([
            'message' => 'لیست آدرس‌های مشتری',
            'addresses' => $addresses,
            'count' => $addresses->count()
        ]);
    }

    /**
     * ذخیره آدرس جدید
     */
    public function store(Request $request)
    {
        $request->validate([
            'title' => 'sometimes|string|max:255',
            'name' => 'required|string|max:255',
            'last_name' => 'required|string|max:255',
            'phone' => 'required|string|digits:11',
            'address' => 'required|string',
            'state_id' => 'required|exists:states,id',
            'city_id' => 'required|exists:cities,id',
            'postal_code' => 'required|string|max:10',
            'is_default' => 'sometimes|boolean'
        ]);

        $customer = $request->user();

        // اگر این آدرس به عنوان پیش‌فرض تعیین شد، آدرس پیش‌فرض قبلی را بروزرسانی کن
        if ($request->input('is_default', false)) {
            CustomerAddress::where('customer_id', $customer->id)
                ->update(['is_default' => false]);
        }

        // اگر این اولین آدرس است، آن را به عنوان پیش‌فرض تعیین کن
        $isFirstAddress = !CustomerAddress::where('customer_id', $customer->id)->exists();

        $state = State::find($request->input('state_id'));
        $city = City::find($request->input('city_id'));

        $address = CustomerAddress::create([
            'customer_id' => $customer->id,
            'title' => $request->input('title'),
            'name' => $request->input('name'),
            'last_name' => $request->input('last_name'),
            'phone' => $request->input('phone'),
            'address' => $request->input('address'),
            'state_id' => $request->input('state_id'),
            'state_name' => $state->name ?? null,
            'city_id' => $request->input('city_id'),
            'city_name' => $city->name ?? null,
            'postal_code' => $request->input('postal_code'),
            'is_default' => $request->input('is_default', $isFirstAddress)
        ]);

        $address->load(['state', 'city']);

        return response([
            'message' => 'آدرس با موفقیت ذخیره شد',
            'address' => $address
        ], 201);
    }

    /**
     * نمایش یک آدرس
     */
    public function show(Request $request, CustomerAddress $address)
    {
        $customer = $request->user();

        // بررسی اینکه این آدرس متعلق به مشتری است
        if ($address->customer_id !== $customer->id) {
            return response(['error' => 'شما دسترسی به این آدرس ندارید'], 403);
        }

        $address->load(['state', 'city']);

        return response([
            'message' => 'جزئیات آدرس',
            'address' => $address
        ]);
    }

    /**
     * بروزرسانی آدرس
     */
    public function update(Request $request, CustomerAddress $address)
    {
        $customer = $request->user();

        // بررسی اینکه این آدرس متعلق به مشتری است
        if ($address->customer_id !== $customer->id) {
            return response(['error' => 'شما دسترسی به این آدرس ندارید'], 403);
        }

        $request->validate([
            'title' => 'sometimes|string|max:255',
            'name' => 'sometimes|string|max:255',
            'last_name' => 'sometimes|string|max:255',
            'phone' => 'sometimes|string|digits:11',
            'address' => 'sometimes|string',
            'state_id' => 'sometimes|exists:states,id',
            'city_id' => 'sometimes|exists:cities,id',
            'postal_code' => 'sometimes|string|max:10',
            'is_default' => 'sometimes|boolean'
        ]);

        // اگر این آدرس به عنوان پیش‌فرض تعیین شد، آدرس پیش‌فرض قبلی را بروزرسانی کن
        if ($request->input('is_default') === true) {
            CustomerAddress::where('customer_id', $customer->id)
                ->where('id', '!=', $address->id)
                ->update(['is_default' => false]);
        }

        $updateData = $request->only(['title', 'name', 'last_name', 'phone', 'address', 'state_id', 'city_id', 'postal_code', 'is_default']);

        if ($request->has('state_id')) {
            $state = State::find($request->input('state_id'));
            $updateData['state_name'] = $state->name ?? null;
        }

        if ($request->has('city_id')) {
            $city = City::find($request->input('city_id'));
            $updateData['city_name'] = $city->name ?? null;
        }

        $address->update($updateData);
        $address->load(['state', 'city']);

        return response([
            'message' => 'آدرس با موفقیت بروزرسانی شد',
            'address' => $address
        ]);
    }

    /**
     * حذف آدرس
     */
    public function destroy(Request $request, CustomerAddress $address)
    {
        $customer = $request->user();

        // بررسی اینکه این آدرس متعلق به مشتری است
        if ($address->customer_id !== $customer->id) {
            return response(['error' => 'شما دسترسی به این آدرس ندارید'], 403);
        }

        // بررسی اینکه این آدرس در سبد خرید استفاده نشود
        if ($address->carts()->exists()) {
            return response([
                'error' => 'این آدرس در سبد خریدی استفاده می‌شود. ابتدا سبد را تغییر دهید'
            ], 400);
        }

        // اگر این آدرس پیش‌فرض بود، آدرس دیگری را به عنوان پیش‌فرض تعیین کن
        if ($address->is_default) {
            $nextAddress = CustomerAddress::where('customer_id', $customer->id)
                ->where('id', '!=', $address->id)
                ->first();
            
            if ($nextAddress) {
                $nextAddress->update(['is_default' => true]);
            }
        }

        $address->delete();

        return response(['message' => 'آدرس با موفقیت حذف شد']);
    }

    /**
     * تعیین آدرس پیش‌فرض
     */
    public function setDefault(Request $request, CustomerAddress $address)
    {
        $customer = $request->user();

        // بررسی اینکه این آدرس متعلق به مشتری است
        if ($address->customer_id !== $customer->id) {
            return response(['error' => 'شما دسترسی به این آدرس ندارید'], 403);
        }

        // آدرس پیش‌فرض قبلی را بروزرسانی کن
        CustomerAddress::where('customer_id', $customer->id)
            ->update(['is_default' => false]);

        // این آدرس را به عنوان پیش‌فرض تعیین کن
        $address->update(['is_default' => true]);

        return response([
            'message' => 'آدرس به عنوان پیش‌فرض تعیین شد',
            'address' => $address->load(['state', 'city'])
        ]);
    }
}

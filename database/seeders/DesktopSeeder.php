<?php

namespace Database\Seeders;

use App\Models\Atelier;
use App\Models\Role;
use App\Models\User;
use App\Support\ProjectType;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;

class DesktopSeeder extends Seeder
{
    public const SHOP_PHONE = '09120000000';

    public const OIL_PHONE = '09121111111';

    public const DEFAULT_PASSWORD = 'Admin@12345';

    public function run()
    {
        $this->ensureRoles();

        $shopAtelier = $this->ensureAtelier([
            'name' => 'فروشگاه دسکتاپ',
            'code' => 'desktop-shop',
            'address' => 'Local Desktop',
            'project_type' => ProjectType::SHOP,
        ]);

        $oilAtelier = $this->ensureAtelier([
            'name' => 'تعویض روغن دسکتاپ',
            'code' => 'desktop-oil',
            'address' => 'Local Desktop',
            'project_type' => ProjectType::OIL,
            'oil_interval_km' => 5000,
        ]);

        $this->ensureUser([
            'name' => 'Admin',
            'last_name' => 'Shop',
            'phone' => self::SHOP_PHONE,
            'national_code' => '9000000001',
            'atelier_id' => $shopAtelier->id,
            'project_type' => ProjectType::SHOP,
            'role_id' => User::USER_TYPE_KEY['فروشگاه'],
        ]);

        $this->ensureUser([
            'name' => 'Admin',
            'last_name' => 'Oil',
            'phone' => self::OIL_PHONE,
            'national_code' => '9000000002',
            'atelier_id' => $oilAtelier->id,
            'project_type' => ProjectType::OIL,
            'role_id' => User::USER_TYPE_KEY['فروشگاه'],
        ]);
    }

    private function ensureRoles(): void
    {
        if (! Schema::hasTable('roles')) {
            return;
        }

        foreach (User::USER_TYPE as $id => $name) {
            Role::query()->firstOrCreate(
                ['id' => $id],
                ['name' => $name]
            );
        }
    }

    private function ensureAtelier(array $attrs): Atelier
    {
        $payload = [
            'name' => $attrs['name'],
            'code' => $attrs['code'],
            'address' => $attrs['address'] ?? '—',
            'business_license' => 'desktop-placeholder',
            'shop_access_starts_at' => now()->subDay(),
            'shop_access_ends_at' => null,
            'shop_access_suspended' => false,
            'subscription_status' => Atelier::SUBSCRIPTION_PAID,
            'paid_plan_activated_at' => now(),
        ];

        if (Schema::hasColumn('ateliers', 'project_type')) {
            $payload['project_type'] = $attrs['project_type'] ?? ProjectType::SHOP;
        }
        if (Schema::hasColumn('ateliers', 'oil_interval_km') && isset($attrs['oil_interval_km'])) {
            $payload['oil_interval_km'] = $attrs['oil_interval_km'];
        }

        $atelier = Atelier::query()->where('code', $attrs['code'])->first();
        if ($atelier) {
            $atelier->fill($payload)->save();

            return $atelier;
        }

        return Atelier::create($payload);
    }

    private function ensureUser(array $attrs): User
    {
        $user = User::query()->where('phone', $attrs['phone'])->first();
        $payload = [
            'name' => $attrs['name'],
            'last_name' => $attrs['last_name'],
            'phone' => $attrs['phone'],
            'national_code' => $attrs['national_code'],
            'password' => Hash::make(self::DEFAULT_PASSWORD),
            'atelier_id' => $attrs['atelier_id'],
            'gender' => 'مرد',
            'national_cart' => 'desktop-placeholder',
        ];
        if (Schema::hasColumn('users', 'shop_staff_role')) {
            $payload['shop_staff_role'] = 'owner';
        }
        if (Schema::hasColumn('users', 'project_type')) {
            $payload['project_type'] = $attrs['project_type'];
        }

        if ($user) {
            // Keep existing password if user already exists; only refresh links
            unset($payload['password']);
            $user->fill($payload)->save();
        } else {
            $user = User::create($payload);
        }

        if (Schema::hasTable('roles')) {
            $roleId = (int) $attrs['role_id'];
            if (! $user->roles()->where('roles.id', $roleId)->exists()) {
                $user->roles()->syncWithoutDetaching([$roleId]);
            }
        }

        return $user;
    }
}

<?php

namespace App\Http\Controllers;

use App\Models\ShopCampaign;
use App\Models\ShopCampaignAction;
use App\Models\ShopCampaignLog;
use App\Models\ShopCampaignRule;
use App\Models\ShopCampaignRun;
use App\Services\SmartCustomer\CampaignRunner;
use App\Services\SmartCustomer\ProductCampaignService;
use App\Services\ShopFeatureFlags;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

class SmartCampaignController extends Controller
{
    public function index(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        if (! Schema::hasTable('shop_campaigns')) {
            return response(['campaigns' => []], 200);
        }

        $campaigns = ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->with(['rule', 'actions'])
            ->orderByDesc('id')
            ->get()
            ->map(fn (ShopCampaign $c) => $this->serialize($c));

        return response(['campaigns' => $campaigns], 200);
    }

    public function store(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $validated = $this->validatePayload($request);

        $campaign = DB::transaction(function () use ($atelierId, $validated) {
            $campaign = ShopCampaign::create([
                'atelier_id' => $atelierId,
                'name' => $validated['name'],
                'status' => $validated['status'] ?? ShopCampaign::STATUS_DRAFT,
                'trigger' => 'manual',
                'cooldown_days' => $validated['cooldown_days'] ?? 4,
                'max_recipients_per_run' => $validated['max_recipients_per_run'] ?? null,
                'daily_sms_budget' => $validated['daily_sms_budget'] ?? null,
                'require_manual_approve' => true,
                'description' => $validated['description'] ?? null,
            ]);

            ShopCampaignRule::create([
                'campaign_id' => $campaign->id,
                'conditions' => $validated['conditions'],
            ]);

            foreach ($validated['actions'] as $i => $action) {
                ShopCampaignAction::create([
                    'campaign_id' => $campaign->id,
                    'sort' => $i,
                    'type' => $action['type'],
                    'config' => $action['config'] ?? [],
                ]);
            }

            return $campaign->load(['rule', 'actions']);
        });

        return response([
            'message' => 'کمپین ساخته شد.',
            'campaign' => $this->serialize($campaign),
        ], 201);
    }

    public function show(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $row = ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->with(['rule', 'actions'])
            ->findOrFail($campaign);

        return response(['campaign' => $this->serialize($row)], 200);
    }

    public function update(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $row = ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->findOrFail($campaign);

        $validated = $this->validatePayload($request, false);

        DB::transaction(function () use ($row, $validated) {
            if (array_key_exists('name', $validated)) {
                $row->name = $validated['name'];
            }
            if (array_key_exists('status', $validated)) {
                $row->status = $validated['status'];
            }
            if (array_key_exists('cooldown_days', $validated)) {
                $row->cooldown_days = $validated['cooldown_days'];
            }
            if (array_key_exists('max_recipients_per_run', $validated)) {
                $row->max_recipients_per_run = $validated['max_recipients_per_run'];
            }
            if (array_key_exists('description', $validated)) {
                $row->description = $validated['description'];
            }
            $row->save();

            if (isset($validated['conditions'])) {
                ShopCampaignRule::updateOrCreate(
                    ['campaign_id' => $row->id],
                    ['conditions' => $validated['conditions']]
                );
            }

            if (isset($validated['actions'])) {
                ShopCampaignAction::query()->where('campaign_id', $row->id)->delete();
                foreach ($validated['actions'] as $i => $action) {
                    ShopCampaignAction::create([
                        'campaign_id' => $row->id,
                        'sort' => $i,
                        'type' => $action['type'],
                        'config' => $action['config'] ?? [],
                    ]);
                }
            }
        });

        return response([
            'message' => 'کمپین به‌روز شد.',
            'campaign' => $this->serialize($row->fresh(['rule', 'actions'])),
        ], 200);
    }

    public function destroy(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $row = ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->findOrFail($campaign);
        $row->delete();

        return response(['message' => 'کمپین حذف شد.'], 200);
    }

    public function preview(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $row = ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->with('rule')
            ->findOrFail($campaign);

        return response(CampaignRunner::preview($row), 200);
    }

    public function run(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $row = ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->with(['rule', 'actions'])
            ->findOrFail($campaign);

        $result = CampaignRunner::runManual($row);
        $code = ($result['ok'] ?? false) ? 200 : 422;

        return response($result, $code);
    }

    public function runs(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->findOrFail($campaign);

        $runs = ShopCampaignRun::query()
            ->where('campaign_id', $campaign)
            ->where('atelier_id', $atelierId)
            ->orderByDesc('id')
            ->limit(50)
            ->get();

        return response(['runs' => $runs], 200);
    }

    /**
     * بررسی نتیجه کمپین: از گیرندگان چند نفر بعد از اولین ارسال خرید کرده‌اند و چقدر.
     */
    public function report(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $row = ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->findOrFail($campaign);

        $sentPerPhone = DB::table('shop_campaign_logs')
            ->where('campaign_id', $row->id)
            ->where('atelier_id', $atelierId)
            ->where('status', 'sent')
            ->groupBy('phone')
            ->select(['phone', DB::raw('MIN(created_at) as first_sent_at')]);

        $perPhone = DB::query()
            ->fromSub($sentPerPhone, 'l')
            ->leftJoin('purchases as p', function ($join) use ($atelierId) {
                $join->on('p.phone', '=', 'l.phone')
                    ->where('p.atelier_id', '=', $atelierId)
                    ->where('p.total_amount', '>', 0)
                    ->on('p.created_at', '>=', 'l.first_sent_at');
            })
            ->groupBy('l.phone', 'l.first_sent_at')
            ->select([
                'l.phone',
                'l.first_sent_at',
                DB::raw('COUNT(p.id) as orders'),
                DB::raw('COALESCE(SUM(p.total_amount), 0) as revenue'),
                DB::raw('MIN(p.created_at) as first_purchase_at'),
            ])
            ->get();

        $recipients = $perPhone->count();
        $returned = $perPhone->filter(fn ($r) => (int) $r->orders > 0);
        $revenue = (float) $returned->sum(fn ($r) => (float) $r->revenue);
        $orders = (int) $returned->sum(fn ($r) => (int) $r->orders);

        $daysToReturn = $returned
            ->map(function ($r) {
                $sent = \Carbon\Carbon::parse($r->first_sent_at);
                $bought = \Carbon\Carbon::parse($r->first_purchase_at);

                return max(0, $sent->diffInHours($bought) / 24);
            })
            ->values();

        $creditGiven = 0.0;
        $creditUsed = null;
        if (Schema::hasTable('user_credit_grants') && Schema::hasColumn('user_credit_grants', 'campaign_id')) {
            $grants = DB::table('user_credit_grants')
                ->where('atelier_id', $atelierId)
                ->where('campaign_id', $row->id)
                ->select([
                    DB::raw('COALESCE(SUM(amount), 0) as given'),
                    Schema::hasColumn('user_credit_grants', 'remaining')
                        ? DB::raw('COALESCE(SUM(amount - COALESCE(remaining, 0)), 0) as used')
                        : DB::raw('NULL as used'),
                ])
                ->first();
            $creditGiven = (float) ($grants->given ?? 0);
            $creditUsed = $grants && $grants->used !== null ? (float) $grants->used : null;
        }
        if ($creditGiven <= 0) {
            $creditGiven = (float) ShopCampaignLog::query()
                ->where('campaign_id', $row->id)
                ->where('atelier_id', $atelierId)
                ->where('status', 'sent')
                ->get(['actions_result'])
                ->sum(fn (ShopCampaignLog $l) => (float) ($l->actions_result['credit']['added'] ?? 0));
        }

        $runs = ShopCampaignRun::query()
            ->where('campaign_id', $row->id)
            ->where('atelier_id', $atelierId)
            ->selectRaw('COUNT(*) as runs, MIN(created_at) as first_run_at, MAX(created_at) as last_run_at')
            ->first();

        $topCustomers = $returned
            ->sortByDesc(fn ($r) => (float) $r->revenue)
            ->take(20)
            ->map(fn ($r) => [
                'phone' => (string) $r->phone,
                'orders' => (int) $r->orders,
                'revenue' => round((float) $r->revenue, 0),
                'first_sent_at' => (string) $r->first_sent_at,
                'first_purchase_at' => (string) $r->first_purchase_at,
            ])
            ->values();

        $productIds = ProductCampaignService::productIdsFromActions(
            ShopCampaignAction::query()->where('campaign_id', $row->id)->get()
        );
        $productConversion = $productIds !== [] && ProductCampaignService::ready()
            ? ProductCampaignService::conversion($atelierId, (int) $row->id, $productIds)
            : null;

        return response([
            'campaign_id' => $row->id,
            'product_conversion' => $productConversion,
            'recipients' => $recipients,
            'returned' => $returned->count(),
            'conversion_pct' => $recipients > 0 ? round($returned->count() * 100 / $recipients, 1) : 0,
            'orders' => $orders,
            'revenue' => round($revenue, 0),
            'avg_order_value' => $orders > 0 ? round($revenue / $orders, 0) : 0,
            'avg_days_to_return' => $daysToReturn->count() > 0 ? round($daysToReturn->avg(), 1) : null,
            'credit_given' => round($creditGiven, 0),
            'credit_used' => $creditUsed !== null ? round($creditUsed, 0) : null,
            'runs' => (int) ($runs->runs ?? 0),
            'first_run_at' => $runs->first_run_at ?? null,
            'last_run_at' => $runs->last_run_at ?? null,
            'top_customers' => $topCustomers,
        ], 200);
    }

    public function products(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $filter = (string) $request->query('filter', 'discounted');
        if (! in_array($filter, ['discounted', 'top', 'slow', 'all'], true)) {
            $filter = 'discounted';
        }

        return response([
            'products' => ProductCampaignService::products(
                $atelierId,
                $filter,
                trim((string) $request->query('search', ''))
            ),
            'sales_window_days' => ProductCampaignService::SALES_WINDOW_DAYS,
        ], 200);
    }

    public function productAudience(Request $request)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        $validated = $request->validate([
            'product_ids' => 'required|array|min:1|max:50',
            'product_ids.*' => 'integer|min:1',
            'mode' => 'required|string|in:bought,not_bought,repurchase_due',
            'days' => 'nullable|integer|min:1|max:3650',
            'exclude_product_ids' => 'nullable|array|max:50',
            'exclude_product_ids.*' => 'integer|min:1',
            'segment' => 'nullable|string|max:32',
        ]);

        return response(ProductCampaignService::audience($atelierId, $validated), 200);
    }

    public function logs(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::SMART_CUSTOMER_CLUB,
            'باشگاه هوشمند برای این فروشگاه فعال نیست.'
        );

        ShopCampaign::query()
            ->where('atelier_id', $atelierId)
            ->findOrFail($campaign);

        $runId = $request->query('run_id');
        $logs = ShopCampaignLog::query()
            ->where('campaign_id', $campaign)
            ->where('atelier_id', $atelierId)
            ->when($runId, fn ($q) => $q->where('run_id', (int) $runId))
            ->orderByDesc('id')
            ->limit(200)
            ->get();

        return response(['logs' => $logs], 200);
    }

    /**
     * @return array<string, mixed>
     */
    protected function validatePayload(Request $request, bool $requireAll = true): array
    {
        $rules = [
            'name' => ($requireAll ? 'required' : 'sometimes').'|string|max:120',
            'status' => 'nullable|string|in:draft,active,paused',
            'cooldown_days' => 'nullable|integer|min:1|max:3650',
            'max_recipients_per_run' => 'nullable|integer|min:1|max:10000',
            'daily_sms_budget' => 'nullable|integer|min:1|max:100000',
            'description' => 'nullable|string|max:2000',
            'conditions' => ($requireAll ? 'required' : 'sometimes').'|array',
            'conditions.all' => 'required_with:conditions|array|min:1',
            'conditions.all.*.field' => 'required_with:conditions.all|string|max:64',
            'conditions.all.*.op' => 'required_with:conditions.all|string|in:=,!=,>,>=,<,<=,in,not_in,contains',
            'conditions.all.*.value' => 'required_with:conditions.all',
            'actions' => ($requireAll ? 'required' : 'sometimes').'|array|min:1',
            'actions.*.type' => 'required_with:actions|string|in:grant_credit,send_sms,create_smart_action',
            'actions.*.config' => 'nullable|array',
        ];

        return $request->validate($rules);
    }

    protected function serialize(ShopCampaign $c): array
    {
        return [
            'id' => $c->id,
            'name' => $c->name,
            'status' => $c->status,
            'trigger' => $c->trigger,
            'cooldown_days' => (int) $c->cooldown_days,
            'max_recipients_per_run' => $c->max_recipients_per_run,
            'daily_sms_budget' => $c->daily_sms_budget,
            'require_manual_approve' => (bool) $c->require_manual_approve,
            'description' => $c->description,
            'conditions' => $c->rule->conditions ?? ['all' => []],
            'actions' => $c->actions->map(fn (ShopCampaignAction $a) => [
                'id' => $a->id,
                'sort' => (int) $a->sort,
                'type' => $a->type,
                'config' => $a->config ?? [],
            ])->values()->all(),
            'created_at' => optional($c->created_at)->toDateTimeString(),
            'updated_at' => optional($c->updated_at)->toDateTimeString(),
        ];
    }
}

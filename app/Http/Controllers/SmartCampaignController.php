<?php

namespace App\Http\Controllers;

use App\Models\ShopCampaign;
use App\Models\ShopCampaignAction;
use App\Models\ShopCampaignLog;
use App\Models\ShopCampaignRule;
use App\Models\ShopCampaignRun;
use App\Services\SmartCustomer\CampaignRunner;
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
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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

    public function logs(Request $request, int $campaign)
    {
        $atelierId = $this->assertShopFeature(
            $request,
            ShopFeatureFlags::CUSTOMER_CLUB,
            'باشگاه مشتریان برای این فروشگاه فعال نیست.'
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
            'cooldown_days' => 'nullable|integer|min:1|max:30',
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

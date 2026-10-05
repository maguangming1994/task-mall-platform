<?php

namespace Tests\Feature;

use App\Models\User;
use App\Modules\Campus\Models\Campus;
use App\Modules\Operations\Models\CampusFeatureFlag;
use App\Modules\Operations\Support\ModuleRegistry;
use Database\Seeders\LegalDocumentSeeder;
use Database\Seeders\ReleaseControlSeeder;
use Database\Seeders\SystemSettingDefinitionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * 13 模块路由门禁矩阵：关闭校区 403 MODULE_DISABLED，开启校区正常，不受控路由不受影响。
 */
class ModuleRouteGateTest extends TestCase
{
    use RefreshDatabase;

    private Campus $openCampus;

    private Campus $closedCampus;

    private string $openToken;

    private string $closedToken;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(ReleaseControlSeeder::class);
        $this->seed(LegalDocumentSeeder::class);
        $this->seed(SystemSettingDefinitionSeeder::class);

        $this->openCampus = Campus::query()->create(['code' => 'gate-open', 'name' => '全量开放校', 'status' => 'active']);
        $this->closedCampus = Campus::query()->create(['code' => 'gate-closed', 'name' => '全量关闭校', 'status' => 'active']);
        foreach (app(ModuleRegistry::class)->codes() as $code) {
            CampusFeatureFlag::query()->create(['campus_id' => $this->closedCampus->id, 'code' => $code, 'status' => 'disabled']);
        }

        $this->openToken = $this->loginConsentedUser('13900000001', $this->openCampus->id);
        $this->closedToken = $this->loginConsentedUser('13900000002', $this->closedCampus->id);
    }

    #[DataProvider('publicGatedRoutes')]
    public function test_public_routes_return_403_when_campus_module_closed(string $method, string $uri): void
    {
        // setUp 中登录流程残留的 Authorization 默认头必须清掉，保证真正匿名
        $this->flushHeaders();
        $response = $this->json($method, '/api/v1'.$uri, [], [
            'X-Campus-Id' => $this->closedCampus->public_id,
            'X-Client-Platform' => 'wechat-mini',
            'X-Device-Id' => 'gate-device',
        ]);

        $response->assertForbidden()->assertJsonPath('code', 'MODULE_DISABLED');
    }

    #[DataProvider('publicGatedRoutes')]
    public function test_public_routes_are_not_gated_when_campus_module_open(string $method, string $uri): void
    {
        $this->flushHeaders();
        $response = $this->json($method, '/api/v1'.$uri, [], [
            'X-Campus-Id' => $this->openCampus->public_id,
            'X-Client-Platform' => 'wechat-mini',
            'X-Device-Id' => 'gate-device',
        ]);

        // 开放校区绝不能返回模块门禁错误（POST 会话缺参可能 422，但不是 403 MODULE_DISABLED）
        $this->assertNotSame('MODULE_DISABLED', $response->json('code'));
        $this->assertNotSame(403, $response->status());
    }

    public static function publicGatedRoutes(): array
    {
        return [
            '任务大厅列表' => ['GET', '/tasks'],
            '商品列表' => ['GET', '/products'],
            '商品分类' => ['GET', '/product-categories'],
            '闲置列表' => ['GET', '/second-hand/items'],
            '闲置分类' => ['GET', '/second-hand/categories'],
            '社区首页' => ['GET', '/community/home'],
            '社区帖子列表' => ['GET', '/community/posts'],
            '社区活动列表' => ['GET', '/community/activities'],
            '售后原因' => ['GET', '/after-sale-reasons'],
            // 发布中心代表路由：关闭时门禁先于参数校验返回 403
            '发布者会话' => ['POST', '/task-publisher/session'],
        ];
    }

    #[DataProvider('authenticatedGatedRoutes')]
    public function test_authenticated_routes_return_403_for_user_in_closed_campus(string $method, string $uri): void
    {
        $this->json($method, '/api/v1'.$uri, [], [
            'Authorization' => 'Bearer '.$this->closedToken,
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'closed-device',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    #[DataProvider('authenticatedGatedRoutes')]
    public function test_authenticated_routes_pass_gate_for_user_in_open_campus(string $method, string $uri): void
    {
        $response = $this->json($method, '/api/v1'.$uri, [], [
            'Authorization' => 'Bearer '.$this->openToken,
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'open-device',
        ]);

        $this->assertNotSame('MODULE_DISABLED', $response->json('code'), "开放校区被门禁误拦截：{$uri}");
        $this->assertNotSame(403, $response->status(), "开放校区返回 403：{$uri}");
    }

    public static function authenticatedGatedRoutes(): array
    {
        return [
            '我的领取' => ['GET', '/task-claims'],
            '购物车' => ['GET', '/cart'],
            '订单列表' => ['GET', '/orders'],
            '退款列表' => ['GET', '/refunds'],
            '换货列表' => ['GET', '/exchanges'],
            '我的帖子' => ['GET', '/community/my-posts'],
            '钱包账户' => ['GET', '/wallet/account'],
            '充值列表' => ['GET', '/wallet/recharges'],
            '提现列表' => ['GET', '/wallet/withdrawals'],
            '银行卡列表' => ['GET', '/wallet/bank-cards'],
            '邀请推荐' => ['GET', '/growth/referrals'],
            '每日签到' => ['GET', '/growth/check-in'],
            '闲置订单' => ['GET', '/second-hand/orders'],
            '我的闲置' => ['GET', '/second-hand/my-items'],
            'IM 会话列表' => ['GET', '/chat/conversations'],
        ];
    }

    public function test_uncontrolled_public_routes_remain_accessible_with_closed_campus_header(): void
    {
        $headers = [
            'X-Campus-Id' => $this->closedCampus->public_id,
            'X-Client-Platform' => 'wechat-mini',
            'X-Device-Id' => 'gate-device',
        ];

        $this->getJson('/api/v1/client-capabilities', $headers)->assertOk();
        $this->getJson('/api/v1/campuses', $headers)->assertOk();
        $this->getJson('/api/v1/legal-documents', $headers)->assertOk();
        $this->getJson('/api/v1/home-content', $headers)->assertOk();
        $this->getJson('/api/v1/appearance-content', $headers)->assertOk();
        $this->getJson('/api/v1/notifications', ['Authorization' => 'Bearer '.$this->closedToken] + $headers)->assertOk();
        $this->getJson('/api/v1/me', ['Authorization' => 'Bearer '.$this->closedToken] + $headers)->assertOk();
    }

    public function test_logged_in_user_campus_takes_precedence_over_campus_header(): void
    {
        // 用户属于关闭校区，即使请求头伪称开放校区，仍被门禁拦截
        $this->getJson('/api/v1/growth/check-in', [
            'Authorization' => 'Bearer '.$this->closedToken,
            'X-Campus-Id' => $this->openCampus->public_id,
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'spoof-device',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    #[DataProvider('optionalAuthPublicGatedRoutes')]
    public function test_optional_auth_public_routes_follow_user_campus_despite_spoofed_header(string $uri): void
    {
        // 公开浏览路由挂 auth.optional：带 token 时必须以账号校区判定，伪造 X-Campus-Id 不可绕过
        $this->getJson('/api/v1'.$uri, [
            'Authorization' => 'Bearer '.$this->closedToken,
            'X-Campus-Id' => $this->openCampus->public_id,
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'spoof-device',
        ])
            ->assertForbidden()
            ->assertJsonPath('code', 'MODULE_DISABLED');
    }

    public static function optionalAuthPublicGatedRoutes(): array
    {
        return [
            '任务大厅列表' => ['/tasks'],
            '商品列表' => ['/products'],
            '商品分类' => ['/product-categories'],
            '售后原因' => ['/after-sale-reasons'],
        ];
    }

    public function test_client_capabilities_follow_user_campus_despite_spoofed_header(): void
    {
        $response = $this->getJson('/api/v1/client-capabilities', [
            'Authorization' => 'Bearer '.$this->closedToken,
            'X-Campus-Id' => $this->openCampus->public_id,
            'X-Client-Platform' => 'h5-browser',
            'X-Device-Id' => 'spoof-device',
        ])->assertOk();

        // 回显的判定校区必须是账号校区，且模块开关按关闭校区下发
        $response->assertJsonPath('data.campus_id', $this->closedCampus->public_id);
        $this->assertFalse($response->json('data.feature_flags.module_mall'));
        $this->assertFalse($response->json('data.feature_flags.module_tasks'));
    }

    private function loginConsentedUser(string $phone, int $campusId): string
    {
        $headers = ['X-Client-Platform' => 'h5-browser', 'X-Device-Id' => 'gate-'.$phone];
        $this->postJson('/api/v1/auth/phone/verification-codes', ['phone' => $phone, 'purpose' => 'login'], $headers)->assertAccepted();
        $login = $this->postJson('/api/v1/auth/phone/login', ['phone' => $phone, 'verification_code' => '246810'], $headers)->assertOk();
        $token = $login->json('data.access_token');

        User::query()->where('public_id', $login->json('data.user.id'))->update(['campus_id' => $campusId]);

        foreach (['account', 'wallet', 'community', 'task'] as $scope) {
            $pending = $this->withToken($token)->getJson('/api/v1/me/legal-consents/pending?scope='.$scope)->json('data') ?? [];
            foreach ($pending as $index => $document) {
                $this->withToken($token)->withHeaders([
                    'Idempotency-Key' => 'gate-consent-'.$phone.'-'.$scope.'-'.$index,
                    'X-Client-Platform' => 'h5-browser',
                ])->postJson('/api/v1/me/legal-consents', [
                    'legal_document_version_id' => $document['id'], 'action' => 'accept', 'acknowledge_service_restriction' => false,
                ])->assertCreated();
            }
        }

        return $token;
    }
}

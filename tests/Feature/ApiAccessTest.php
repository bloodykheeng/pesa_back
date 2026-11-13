<?php

namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Who may call what (routes/api.php). The mobile app's routes must stay open to customers;
 * everything the admin dashboard uses is staff only.
 */
class ApiAccessTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();
    }

    public function test_the_old_public_test_routes_are_gone(): void
    {
        $this->getJson('/api/testing')->assertNotFound();
        $this->postJson('/api/test-email')->assertNotFound();
        $this->postJson('/api/test-notification')->assertNotFound();
    }

    public function test_guests_cannot_read_statistics_or_export_orders(): void
    {
        $this->getJson('/api/transaction-statistics')->assertUnauthorized();
        $this->postJson('/api/orders-excel-exports')->assertUnauthorized();
    }

    /** Routes the Flutter app calls with a customer's token. */
    public static function appRoutes(): array
    {
        return [
            'products list' => ['GET', '/api/products'],
            'my orders' => ['GET', '/api/my-orders'],
            'my packages' => ['GET', '/api/my-packages'],
            'my payments' => ['GET', '/api/my-payments'],
            'my package payments' => ['GET', '/api/my-package-payments'],
            'overall balance' => ['GET', '/api/calculate-overall-balance'],
            'faqs' => ['GET', '/api/get-faqs'],
            'ads' => ['GET', '/api/app-get-ads'],
            'electronic categories' => ['GET', '/api/app-get-electronic-categories'],
            'messages' => ['GET', '/api/messages?with_user=1'],
            'logged in user' => ['GET', '/api/get-logged-in-user'],
        ];
    }

    #[DataProvider('appRoutes')]
    public function test_customers_still_reach_the_app_routes(string $method, string $uri): void
    {
        Sanctum::actingAs($this->customer());

        $status = $this->json($method, $uri)->status();

        $this->assertNotContains($status, [401, 403, 404], "$method $uri answered $status for a customer");
    }

    /** Routes only the admin dashboard uses. */
    public static function staffRoutes(): array
    {
        return [
            'users list' => ['GET', '/api/users'],
            'delete a user' => ['DELETE', '/api/users/1'],
            'orders list' => ['GET', '/api/orders'],
            'create a product' => ['POST', '/api/products'],
            'delete a product' => ['DELETE', '/api/products/1'],
            'packages list' => ['GET', '/api/packages'],
            'payments list' => ['GET', '/api/payments'],
            'record a payment' => ['POST', '/api/orders/1/record-payment'],
            'send a notification' => ['POST', '/api/send-notification'],
            'transaction statistics' => ['GET', '/api/transaction-statistics'],
            'order statistics' => ['GET', '/api/order-statistics'],
            'export orders' => ['POST', '/api/orders-excel-exports'],
            'roles' => ['GET', '/api/users-roles'],
            'faqs admin' => ['GET', '/api/faqs'],
            'referrals list' => ['GET', '/api/referals'],
        ];
    }

    #[DataProvider('staffRoutes')]
    public function test_customers_are_refused_staff_routes(string $method, string $uri): void
    {
        Sanctum::actingAs($this->customer());

        $this->json($method, $uri)->assertForbidden();
    }

    public function test_staff_reach_the_dashboard_routes(): void
    {
        Sanctum::actingAs($this->admin());

        $this->getJson('/api/users')->assertOk();
        $this->getJson('/api/orders?startDate=2024-01-01&endDate=2026-12-31')->assertOk();
        $this->getJson('/api/referals')->assertOk();
    }

    public function test_a_customer_reads_only_their_own_account(): void
    {
        $me = $this->customer();
        $other = $this->customer();
        Sanctum::actingAs($me);

        $this->getJson("/api/users/{$me->id}")->assertOk();
        $this->getJson("/api/users/{$other->id}")->assertForbidden();
        $this->postJson("/api/change-password/{$other->id}", [])->assertForbidden();
        $this->postJson("/api/profile-update/{$other->id}", [])->assertForbidden();
    }

    public function test_staff_read_any_account(): void
    {
        $customer = $this->customer();
        Sanctum::actingAs($this->admin());

        $this->getJson("/api/users/{$customer->id}")->assertOk();
    }
}

<?php

namespace Tests\Feature\Admin;

use App\Models\Order;
use App\Models\User;
use App\Services\FirebaseService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Tests\TestCase;

abstract class AdminTestCase extends TestCase
{
    use RefreshDatabase;

    /** Push messages "sent" during the test: [token, title, body]. */
    public static array $pushes = [];

    protected string $testPublic;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seedRoles();

        // Photos go to a scratch public/ folder, never the real one.
        $this->testPublic = storage_path('framework/testing/public');
        File::ensureDirectoryExists($this->testPublic);
        $this->app->usePublicPath($this->testPublic);

        self::$pushes = [];
        $this->app->instance(FirebaseService::class, new class extends FirebaseService {
            public function __construct() {}

            public function sendNotification($token, $title, $body, $data = [])
            {
                if ($token === 'broken-token') {
                    throw new \RuntimeException('Requested entity was not found.');
                }
                AdminTestCase::$pushes[] = [$token, $title, $body];
            }
        });
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->testPublic);
        parent::tearDown();
    }

    protected function editor(): User
    {
        return User::factory()->create(['status' => 'active'])->assignRole('Editor');
    }

    protected function order(User $customer, array $attributes = []): Order
    {
        return Order::forceCreate($attributes + [
            'order_number' => Str::upper(Str::random(10)),
            'status' => 'pending',
            'amount' => 50000,
            'charged_amount' => 50000,
            'amount_paid' => 0,
            'balance_due' => 50000,
            'payment_status' => 'pending',
            'payment_mode' => 'bnpl',
            'delivery_status' => 'pending',
            'address' => 'Kampala',
            'created_by' => $customer->id,
            'updated_by' => $customer->id,
        ]);
    }
}

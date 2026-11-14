<?php

use Carbon\CarbonImmutable;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Numbers people read, the house pattern from Alpha and NICE (docs/reference-numbers.md):
 *
 * 1. serial_counters: the last counter handed out under each stem, one row per series and month, raised under a row
 *    lock by App\Support\SerialCounter, so a number is never handed out twice, not even after its record is deleted.
 *
 *      table_name        column_name      stem          last_value
 *      orders            order_number     ORD112026     41    (next: ORD11202642)
 *      payments          receipt_number   PAY112026     12    (next: PAY11202613)
 *
 * 2. packages.package_number gets the unique index orders.order_number always had.
 * 3. payments and package_payments get a receipt number of their own (PAY…, PPAY…), beside the customer's mobile
 *    money or bank reference (transaction_number, typed in). Existing payments are numbered here in the order they were
 *    made, month by month on the Kampala calendar, so every receipt has one; the counters then start after them.
 *
 * Existing order and package numbers (random 10 characters) are left exactly as they are: customers have them in push
 * messages and emails.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('serial_counters', function (Blueprint $table) {
            $table->string('table_name', 64)->comment('The table whose records carry these numbers');
            $table->string('column_name', 64)->comment('The column that holds the number');
            $table->string('stem', 50)->comment('The fixed part before the counter, e.g. ORD112026');
            $table->unsignedBigInteger('last_value')->comment('The last counter handed out; the next number uses last_value + 1');

            $table->timestamps();

            $table->primary(['table_name', 'column_name', 'stem']);
        });

        Schema::table('packages', function (Blueprint $table) {
            $table->unique('package_number');
        });

        foreach (['payments' => 'PAY', 'package_payments' => 'PPAY'] as $table => $prefix) {
            Schema::table($table, function (Blueprint $t) {
                $t->string('receipt_number', 30)->nullable()->unique()->after('id')->comment('Our number for this payment (PAY/PPAY + MMYYYY + counter); transaction_number is the customer\'s reference');
            });

            $counters = [];
            DB::table($table)->orderBy('created_at')->orderBy('id')->select(['id', 'created_at'])->chunk(500, function ($rows) use ($table, $prefix, &$counters) {
                foreach ($rows as $row) {
                    $stem = $prefix.CarbonImmutable::parse($row->created_at ?? 'now', 'UTC')->setTimezone(config('app.business_timezone', 'Africa/Kampala'))->format('mY');
                    $counters[$stem] = ($counters[$stem] ?? 0) + 1;
                    DB::table($table)->where('id', $row->id)->update(['receipt_number' => $stem.$counters[$stem]]);
                }
            });
            foreach ($counters as $stem => $last) {
                DB::table('serial_counters')->insert([
                    'table_name' => $table, 'column_name' => 'receipt_number', 'stem' => $stem, 'last_value' => $last,
                    'created_at' => now(), 'updated_at' => now(),
                ]);
            }
        }
    }

    public function down(): void
    {
        foreach (['payments', 'package_payments'] as $table) {
            Schema::table($table, fn (Blueprint $t) => $t->dropColumn('receipt_number'));
        }
        Schema::table('packages', fn (Blueprint $t) => $t->dropUnique(['package_number']));
        Schema::dropIfExists('serial_counters');
    }
};

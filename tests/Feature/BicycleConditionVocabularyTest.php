<?php

namespace Tests\Feature;

use App\Models\Bicycle;
use App\Models\Rental;
use App\Models\User;
use App\Services\RentalService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\Concerns\CreatesTestData;
use Tests\TestCase;

/**
 * A settlement stamps the inspected condition onto the bicycle row, so the
 * bicycle column has to be able to store every value the Return workflow can
 * record. This is the drift guard: with the original enum, SQLite accepted any
 * value while MySQL rejected `fair` and `needs_maintenance` and rolled the
 * whole settlement back.
 */
class BicycleConditionVocabularyTest extends TestCase
{
    use RefreshDatabase;
    use CreatesTestData;

    public function test_the_bicycle_column_enforces_the_return_condition_vocabulary(): void
    {
        foreach (Rental::CONDITIONS as $condition) {
            $this->assertSame(
                $condition,
                $this->persistedCondition($condition),
                "`{$condition}` is a value the return workflow produces"
            );
        }
    }

    public function test_the_legacy_inspection_value_is_still_storable(): void
    {
        $this->assertSame(
            'needs_inspection',
            $this->persistedCondition('needs_inspection'),
            'the pre-existing enum value is kept so existing inventory rows survive'
        );
    }

    public function test_a_value_outside_the_vocabulary_is_rejected_by_the_database(): void
    {
        $bicycle = $this->makeBicycle();

        $this->expectException(\Illuminate\Database\QueryException::class);

        DB::table('bicycles')->where('id', $bicycle->id)->update(['condition' => 'pristine']);
    }

    public function test_a_fair_return_returns_the_bicycle_to_available(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), [
                'condition' => Rental::CONDITION_FAIR,
                'note' => 'Brakes are soft but rideable.',
            ])
            ->assertSessionHasNoErrors();

        $after = $bicycle->fresh();

        $this->assertSame(
            Rental::CONDITION_FAIR,
            $after->condition,
            'a fair return is recorded as fair rather than failing to persist'
        );
        $this->assertSame(
            Bicycle::STATUS_AVAILABLE,
            $after->status,
            'a fair bicycle is still rentable, so it comes back as available'
        );
    }

    public function test_a_needs_maintenance_return_routes_to_maintenance_and_records_the_condition(): void
    {
        $admin = $this->makeAdmin();
        $rider = $this->makeRider(['verified' => true, 'role' => User::ROLE_RIDER]);
        $bicycle = $this->makeBicycle(['status' => Bicycle::STATUS_AVAILABLE]);
        $rental = app(RentalService::class)->startRental($rider, $bicycle->id, 60, 'cash');

        $this->actingAs($admin)
            ->from(route('admin.bicycles.index'))
            ->put(route('admin.rentals.end-ride', $rental->id), [
                'condition' => Rental::CONDITION_NEEDS_MAINTENANCE,
                'note' => 'Chain needs replacing.',
            ])
            ->assertSessionHasNoErrors();

        $after = $bicycle->fresh();

        $this->assertSame(
            Rental::CONDITION_NEEDS_MAINTENANCE,
            $after->condition,
            'the inspected condition survives the settlement'
        );
        $this->assertSame(
            Bicycle::STATUS_MAINTENANCE,
            $after->status,
            'a bicycle needing maintenance does not go straight back into service'
        );
    }

    /**
     * Write a condition straight to the database and read back what landed,
     * bypassing Eloquent so the column itself is what gets exercised.
     */
    private function persistedCondition(string $condition): string
    {
        $bicycle = $this->makeBicycle();

        DB::table('bicycles')->where('id', $bicycle->id)->update(['condition' => $condition]);

        return (string) DB::table('bicycles')->where('id', $bicycle->id)->value('condition');
    }
}
<?php

/**
 * Creates the `tax_classes` table (engine spec §3.24) and seeds the four
 * default classes every store starts with.
 *
 * @package    ArtisanPack_UI
 * @subpackage Ecommerce
 *
 * @author     Jacob Martella <me@jacobmartella.com>
 *
 * @since      1.0.0
 */

declare( strict_types=1 );

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function up(): void
    {
        Schema::create( 'tax_classes', function ( Blueprint $table ): void {
            $table->bigIncrements( 'id' );
            $table->string( 'key', 60 );
            $table->string( 'label', 120 );
            $table->timestamps();

            $table->unique( 'key', 'tax_classes_key_uk' );
        } );

        $this->seedDefaultClasses();
    }

    /**
     * @since 1.0.0
     *
     * @return void
     */
    public function down(): void
    {
        Schema::dropIfExists( 'tax_classes' );
    }

    /**
     * Seeds `standard`, `reduced`, `zero`, and `digital` so products can be
     * assigned a class before an operator has configured anything. Products
     * without an explicit `tax_class_key` fall back to `standard`.
     *
     * @since 1.0.0
     *
     * @return void
     */
    protected function seedDefaultClasses(): void
    {
        $now = Carbon::now();

        DB::table( 'tax_classes' )->insert( [
            [ 'key' => 'standard', 'label' => 'Standard', 'created_at' => $now, 'updated_at' => $now ],
            [ 'key' => 'reduced', 'label' => 'Reduced rate', 'created_at' => $now, 'updated_at' => $now ],
            [ 'key' => 'zero', 'label' => 'Zero rate', 'created_at' => $now, 'updated_at' => $now ],
            [ 'key' => 'digital', 'label' => 'Digital goods', 'created_at' => $now, 'updated_at' => $now ],
        ] );
    }
};

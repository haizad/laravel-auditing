<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

class CreateAuditsTestTable extends Migration
{
    /**
     * Run the migrations.
     *
     * @return void
     */
    public function up()
    {
        Schema::create('AUDIT_TRAILS', function (Blueprint $table) {
            $table->increments('AUDIT_TRAILS_ID');
            $table->string('USER_MODEL')->nullable();
            $table->unsignedBigInteger('USER_ID')->nullable();
            $table->unsignedBigInteger('GROUP_ID')->nullable();
            $table->string('EVENT');
            $table->morphs('AUDITABLE');
            $table->text('OLD_VALUES')->nullable();
            $table->text('NEW_VALUES')->nullable();
            $table->text('URL')->nullable();
            $table->ipAddress('IP_ADDRESS')->nullable();
            $table->string('BROWSER')->nullable();
            $table->string('TAGS')->nullable();
            $table->timestamps();

            $table->index(['USER_ID', 'USER_MODEL']);
        });
    }

    /**
     * Reverse the migrations.
     *
     * @return void
     */
    public function down()
    {
        Schema::drop('AUDIT_TRAILS');
    }
}

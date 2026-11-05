<?php

declare(strict_types=1);

namespace Zana\Idempotency;

use Zana\Database\Migration\Migration;
use Zana\Database\Schema\Blueprint;
use Zana\Database\Schema\Schema;

/**
 * The idempotency_keys table: one row per key and user, holding the request's
 * fingerprint and, once it is done, the response.
 */
final class IdempotencyTableMigration extends Migration
{
    public function up(Schema $schema): void
    {
        $schema->create(IdempotencyStore::TABLE, static function (Blueprint $t): void {
            $t->string('id', 64)->primary();
            $t->string('fingerprint', 64);
            $t->integer('status')->nullable();
            $t->text('headers')->nullable();
            $t->text('body')->nullable();
            $t->bigInteger('locked_until');
            $t->bigInteger('expires_at');
            $t->bigInteger('created_at');
            $t->index(['expires_at'], 'idempotency_keys_expires_index');
        });
    }

    public function down(Schema $schema): void
    {
        $schema->dropIfExists(IdempotencyStore::TABLE);
    }
}

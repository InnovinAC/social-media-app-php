<?php

declare(strict_types=1);

namespace Phpvin\Tests;

use PHPUnit\Framework\Attributes\Test;
use Phpvin\Database\Model;

/**
 * The parts of Model that the happy path never exercises: what save() reports,
 * how change detection handles nulls and mixed types, and how __isset behaves
 * once relations are involved.
 */
final class ModelInternalsTest extends DatabaseTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->createTable('coded', [
            'code' => 'string',
            'label' => 'string',
        ] + self::TIMESTAMPS);

        $this->createTable('gadgets', [
            'id' => 'id',
            'name' => 'string',
            'notes' => 'text',
            'weight' => 'float',
            'active' => 'bool',
            'meta' => 'text',
        ] + self::TIMESTAMPS);
    }

    #[Test]
    public function save_reports_success_on_insert_and_on_update(): void
    {
        $gadget = new Gadget(['name' => 'first']);

        $this->assertTrue($gadget->save(), 'insert reports success');

        $gadget->name = 'renamed';

        $this->assertTrue($gadget->save(), 'update reports success');
    }

    #[Test]
    public function saving_an_unchanged_model_still_reports_success(): void
    {
        $gadget = Gadget::create(['name' => 'unchanged']);

        $this->assertTrue($gadget->save());
    }

    #[Test]
    public function an_explicit_primary_key_is_not_overwritten_by_the_driver(): void
    {
        $gadget = new Gadget(['name' => 'explicit']);
        $gadget->id = 4242;
        $gadget->save();

        $this->assertSame(4242, (int) $gadget->key());
        $this->assertNotNull(Gadget::find(4242));
    }

    #[Test]
    public function a_meaningless_insert_id_is_not_stored_as_the_key(): void
    {
        // The column exists but has no default and no auto-increment, so each
        // driver reports the new key differently: MySQL says '0', Postgres
        // returns null, SQLite hands back a rowid. Only the last is a key.
        $this->createTable('keyless', ['id' => 'int', 'name' => 'string']);

        $thing = new Keyless(['name' => 'anonymous']);
        $thing->save();

        $this->assertNotSame(0, $thing->key(), 'zero is not an identity');
        $this->assertNotSame('', $thing->key());

        if ($this->db->grammar()->supportsReturning()) {
            // RETURNING reports exactly what this insert produced, which is
            // nothing. lastInsertId() would hand back a stale sequence value
            // from somewhere else in the session and look plausible.
            $this->assertNull($thing->key(), 'no key was produced, so none was invented');
        }
    }

    #[Test]
    public function a_driver_reported_id_never_overwrites_an_explicit_key(): void
    {
        // The key here is a string, so if the driver's row id leaked in it
        // would be plainly visible rather than coincidentally equal.
        $record = new Coded(['label' => 'first']);
        $record->code = 'AB-1';
        $record->save();

        $this->assertSame('AB-1', $record->key());
        $this->assertNotNull(Coded::find('AB-1'));
    }

    // --- change detection edges -------------------------------------------

    #[Test]
    public function setting_a_value_to_null_is_a_change(): void
    {
        $gadget = Gadget::create(['name' => 'named']);
        $gadget->name = null;

        $this->assertTrue($gadget->isDirty());
        $this->assertArrayHasKey('name', $gadget->changes());
    }

    #[Test]
    public function setting_a_null_column_to_a_value_is_a_change(): void
    {
        $gadget = Gadget::create(['name' => 'named']);
        $fresh = Gadget::findOrFail($gadget->key());

        $this->assertNull($fresh->notes);

        $fresh->notes = 'now set';

        $this->assertTrue($fresh->isDirty());
    }

    #[Test]
    public function null_staying_null_is_not_a_change(): void
    {
        $gadget = Gadget::create(['name' => 'named']);
        $fresh = Gadget::findOrFail($gadget->key());

        $fresh->notes = null;

        $this->assertFalse($fresh->isDirty());
    }

    #[Test]
    public function a_bool_compared_against_its_stored_integer_is_not_a_change(): void
    {
        $gadget = new Gadget(['name' => 'flagged']);
        $gadget->active = true;
        $gadget->save();

        $fresh = Gadget::findOrFail($gadget->key());
        $fresh->active = true;

        $this->assertFalse($fresh->isDirty(), 'true matches the stored 1');

        $fresh->active = false;

        $this->assertTrue($fresh->isDirty(), 'false does not');
    }

    #[Test]
    public function a_float_compared_against_its_stored_string_is_not_a_change(): void
    {
        $gadget = new Gadget(['name' => 'heavy']);
        $gadget->weight = 1.5;
        $gadget->save();

        $fresh = Gadget::findOrFail($gadget->key());
        $fresh->weight = 1.5;

        $this->assertFalse($fresh->isDirty());
    }

    #[Test]
    public function a_non_scalar_value_is_always_treated_as_changed(): void
    {
        $gadget = Gadget::create(['name' => 'arrayed']);
        $fresh = Gadget::findOrFail($gadget->key());

        // Two arrays cannot be compared by the scalar path, so the safe answer
        // is "changed" rather than silently skipping the write.
        $fresh->meta = ['a' => 1];

        $this->assertTrue($fresh->isDirty());
    }

    #[Test]
    public function an_array_cast_decodes_to_an_array_not_an_object(): void
    {
        $gadget = new Gadget(['name' => 'meta']);
        $gadget->meta = ['nested' => ['deep' => true]];
        $gadget->save();

        $decoded = Gadget::findOrFail($gadget->key())->meta;

        $this->assertIsArray($decoded);
        $this->assertIsArray($decoded['nested'], 'decoded associatively, all the way down');
        $this->assertTrue($decoded['nested']['deep']);
    }

    #[Test]
    public function malformed_json_in_an_array_column_reads_as_an_empty_array(): void
    {
        $gadget = Gadget::create(['name' => 'broken']);

        $this->db->statement(
            'UPDATE ' . $this->q('gadgets') . ' SET ' . $this->q('meta') . ' = ? WHERE ' . $this->q('id') . ' = ?',
            ['{not json', $gadget->key()],
        );

        $this->assertSame([], Gadget::findOrFail($gadget->key())->meta);
    }

    // --- property access ---------------------------------------------------

    // --- comparison without a declared cast --------------------------------

    #[Test]
    public function clearing_a_cast_column_to_null_is_a_change(): void
    {
        // Guarding nulls before the cast matters: PHP considers null == 0
        // true, so a cast comparison would call this unchanged and skip the
        // write.
        $gadget = new Gadget(['name' => 'counted']);
        $gadget->weight = 0.0;
        $gadget->save();

        $fresh = Gadget::findOrFail($gadget->key());
        $fresh->weight = null;

        $this->assertTrue($fresh->isDirty(), 'null replacing zero is a change');
    }

    #[Test]
    public function an_uncast_bool_matches_its_stored_integer(): void
    {
        $plain = new Plain(['name' => 'flagged']);
        $plain->active = true;
        $plain->save();

        $fresh = Plain::findOrFail($plain->key());
        $fresh->active = true;

        $this->assertFalse($fresh->isDirty(), 'true still matches a stored 1 with no cast declared');

        $fresh->active = false;

        $this->assertTrue($fresh->isDirty());
    }

    #[Test]
    public function an_uncast_number_matches_its_stored_string(): void
    {
        $plain = new Plain(['name' => 'weighed']);
        $plain->weight = 2.5;
        $plain->save();

        $fresh = Plain::findOrFail($plain->key());
        $fresh->weight = 2.5;

        $this->assertFalse($fresh->isDirty());

        $fresh->weight = 2.6;

        $this->assertTrue($fresh->isDirty());
    }

    #[Test]
    public function an_uncast_false_matches_a_stored_zero(): void
    {
        // The bool branch compares as integers on purpose: (string) false is
        // '', which would never match the '0' the driver hands back.
        $plain = new Plain(['name' => 'off']);
        $plain->active = false;
        $plain->save();

        $fresh = Plain::findOrFail($plain->key());
        $fresh->active = false;

        $this->assertFalse($fresh->isDirty(), 'false matches a stored 0');

        $fresh->active = true;

        $this->assertTrue($fresh->isDirty());
    }

    #[Test]
    public function an_array_replacing_a_stored_string_is_a_change(): void
    {
        $plain = new Plain(['name' => 'meta']);
        $plain->meta = 'was a string';
        $plain->save();

        $fresh = Plain::findOrFail($plain->key());
        $fresh->meta = ['now' => 'an array'];

        // Neither the bool nor the scalar comparison applies, and coercing an
        // array to a string would be a warning rather than an answer.
        $this->assertTrue($fresh->isDirty());
    }

    #[Test]
    public function an_uncast_non_scalar_is_always_a_change(): void
    {
        $plain = Plain::create(['name' => 'listy']);
        $fresh = Plain::findOrFail($plain->key());

        // No sane comparison exists, so the safe answer is "changed".
        $fresh->meta = ['a' => 1];

        $this->assertTrue($fresh->isDirty());
    }

    #[Test]
    public function isset_sees_attributes_and_loaded_relations(): void
    {
        $gadget = Gadget::create(['name' => 'named']);

        $this->assertTrue(isset($gadget->name));
        $this->assertFalse(isset($gadget->nothing));

        $gadget->setRelation('parts', []);

        $this->assertTrue(isset($gadget->parts), 'a loaded relation counts as set');
    }

    #[Test]
    public function unset_removes_an_attribute(): void
    {
        $gadget = Gadget::create(['name' => 'named']);

        unset($gadget->name);

        $this->assertFalse(isset($gadget->name));
        $this->assertNull($gadget->name);
    }

    #[Test]
    public function raw_attributes_are_available_uncast(): void
    {
        $gadget = new Gadget(['name' => 'raw']);
        $gadget->active = true;
        $gadget->save();

        $raw = Gadget::findOrFail($gadget->key())->rawAttributes();

        $this->assertArrayHasKey('active', $raw);
        $this->assertNotSame(true, $raw['active'], 'raw is whatever the driver stored');
    }
}

class Coded extends Model
{
    protected static string $table = 'coded';

    protected static string $primaryKey = 'code';

    protected static array $fillable = ['label'];
}

class Plain extends Model
{
    protected static string $table = 'gadgets';

    protected static array $fillable = ['name', 'notes'];
}

class Keyless extends Model
{
    protected static string $table = 'keyless';

    protected static array $fillable = ['name'];

    protected static bool $timestamps = false;
}

class Gadget extends Model
{
    protected static string $table = 'gadgets';

    protected static array $fillable = ['name', 'notes'];

    protected static array $casts = ['active' => 'bool', 'weight' => 'float', 'meta' => 'array'];
}

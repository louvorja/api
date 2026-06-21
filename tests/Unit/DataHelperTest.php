<?php

namespace Tests\Unit;

use Tests\TestCase;
use App\Helpers\Data;

class DataHelperTest extends TestCase
{
    // --- Data::value() ---

    public function test_value_converts_true_string()
    {
        $this->assertTrue(Data::value('true'));
    }

    public function test_value_converts_false_string()
    {
        $this->assertFalse(Data::value('false'));
    }

    public function test_value_passes_through_regular_string()
    {
        $this->assertEquals('hello', Data::value('hello'));
    }

    public function test_value_passes_through_integer_as_string()
    {
        $this->assertEquals('42', Data::value('42'));
    }

    // --- Data::whereExplode() ---

    public function test_where_explode_default_equals_operator()
    {
        $result = Data::whereExplode('some_value');

        $this->assertEquals('=', $result['op']);
        $this->assertEquals('some_value', $result['value']);
        $this->assertEquals('and', $result['sep']);
    }

    public function test_where_explode_like_operator()
    {
        $result = Data::whereExplode('like:search_term');

        $this->assertEquals('like', $result['op']);
        $this->assertEquals('search_term', $result['value']);
        $this->assertEquals('and', $result['sep']);
    }

    public function test_where_explode_orlike_operator()
    {
        $result = Data::whereExplode('orlike:search_term');

        $this->assertEquals('like', $result['op']);
        $this->assertEquals('or', $result['sep']);
    }

    public function test_where_explode_or_separator()
    {
        $result = Data::whereExplode('or:search_term');

        $this->assertEquals('or', $result['sep']);
        $this->assertEquals('search_term', $result['value']);
    }

    public function test_where_explode_wildcard_conversion()
    {
        $result = Data::whereExplode('like:foo*');

        $this->assertEquals('foo%', $result['value']);
    }

    public function test_where_explode_converts_true_false_values()
    {
        $result = Data::whereExplode('true');

        $this->assertTrue($result['value']);
        $this->assertEquals('=', $result['op']);
    }

    // --- Data::arrayFilter() ---

    public function test_array_filter_returns_matching_fields()
    {
        $all = ['name' => 'Test', 'email' => 'test@test.com', 'extra' => 'ignore'];
        $fillable = ['name', 'email', 'nonexistent'];

        $result = Data::arrayFilter($all, $fillable);

        $this->assertArrayHasKey('name', $result);
        $this->assertArrayHasKey('email', $result);
        $this->assertArrayNotHasKey('nonexistent', $result);
        $this->assertEquals('Test', $result['name']);
    }

    public function test_array_filter_handles_aliased_fields()
    {
        $all = ['title' => 'My Title'];
        $fillable = ['musics.title'];

        $result = Data::arrayFilter($all, $fillable);

        $this->assertArrayHasKey('musics.title', $result);
        $this->assertEquals('My Title', $result['musics.title']);
    }
}

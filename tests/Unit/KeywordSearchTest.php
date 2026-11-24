<?php

namespace Tests\Unit;

use App\Support\KeywordSearch;
use PHPUnit\Framework\TestCase;

class KeywordSearchTest extends TestCase
{
    public function test_patterns_normalize_case_whitespace_and_duplicate_words(): void
    {
        $this->assertSame(['%oak%', '%table%'], KeywordSearch::patterns("  OAK \t table oak\n"));
        $this->assertSame([], KeywordSearch::patterns(" \t "));
    }

    public function test_patterns_escape_sql_like_metacharacters(): void
    {
        $this->assertSame(['%100!%%', '%a!_b%', '%wow!!%'], KeywordSearch::patterns('100% a_b wow!'));
    }
}

<?php

namespace App\Support;

use Illuminate\Support\Facades\Schema;

final class NewsReview
{
    public static function enabled(): bool
    {
        return (bool) config('network.news_candidate_review') && Schema::hasTable('news_candidate_reviews');
    }
}

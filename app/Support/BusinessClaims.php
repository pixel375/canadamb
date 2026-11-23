<?php

namespace App\Support;

use App\Models\Site;
use Illuminate\Database\Query\Builder;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\DB;

final class BusinessClaims
{
    private const SEALED_PREFIX = 'sealed:v1:';

    public const EVIDENCE_RULES = [
        'contact_email' => 'required|email|max:254',
        'evidence' => 'required|string|min:30|max:2400',
    ];

    public const PROOF_METHODS = [
        'official_authority' => 'Official record and independent proof of authority',
        'independent_contact' => 'Independently sourced business contact and verified authority',
        'in_person' => 'In-person document and authority verification',
    ];

    public static function forApplicant(Site $site, int $user): Builder
    {
        return DB::table('business_claims')->where('business_claims.user_id', $user)
            ->when($site->location_id !== null, fn ($query) => $query->whereExists(fn (Builder $coverage) => $coverage
                ->selectRaw('1')->from('business_locations')->whereColumn('business_id', 'business_claims.business_id')
                ->where('location_id', $site->location_id)));
    }

    public static function seal(string $evidence): string
    {
        return self::SEALED_PREFIX.Crypt::encryptString($evidence);
    }

    public static function read(string $evidence): string
    {
        return str_starts_with($evidence, self::SEALED_PREFIX)
            ? Crypt::decryptString(substr($evidence, strlen(self::SEALED_PREFIX))) : $evidence;
    }
}

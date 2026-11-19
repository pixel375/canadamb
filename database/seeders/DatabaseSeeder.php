<?php

namespace Database\Seeders;

use App\Models\ListingCategory;
use App\Models\Location;
use App\Models\Site;
use App\Support\Marketplace;
use App\Support\NetworkSettings;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        DB::transaction(function () {
            $source = DB::table('location_sources')->where('name', 'CanadaMB editorial starter set')->value('id')
                ?? DB::table('location_sources')->insertGetId([
                    'name' => 'CanadaMB editorial starter set',
                    'license' => 'Manually entered place names. No external dataset incorporated.',
                    'imported_at' => now(),
                ]);
            $province = Location::firstOrCreate(['slug' => 'manitoba'], [
                'name' => 'Manitoba', 'type' => 'province', 'source_id' => $source, 'selectable' => false,
            ]);
            foreach ([
                ['brandon', 'Brandon', 'city'], ['winnipeg', 'Winnipeg', 'city'], ['steinbach', 'Steinbach', 'city'],
                ['virden', 'Virden', 'town'], ['elkhorn', 'Elkhorn', 'rural community'],
                ['dauphin', 'Dauphin', 'city'], ['portage-la-prairie', 'Portage la Prairie', 'city'],
                ['thompson', 'Thompson', 'city'], ['winkler', 'Winkler', 'city'], ['flin-flon', 'Flin Flon', 'city'],
            ] as [$slug, $name, $type]) {
                Location::firstOrCreate(['slug' => $slug], [
                    'name' => $name, 'type' => $type, 'source_id' => $source, 'parent_id' => $province->id,
                ]);
            }

            $this->site('network', null, 'CanadaMB', 'Manitoba Community Network', 'Your Manitoba. Your community.',
                'Discover local listings and explore communities across Manitoba.', config('network.development_hosts') && app()->isLocal() ? 'canadamb.localhost' : 'canadamb.ca', 'gradient',
                ['#E85D75', '#F6A64A', '#E8CF63', '#46B982', '#3C9EE7', '#8071D9', '#B16ACB']);
            $this->site('brandon', 'brandon', 'BrandonMB', 'Brandon Community Portal', 'Your Brandon. Your community.',
                'Find local listings and connect with neighbours in Brandon, Manitoba.', config('network.development_hosts') && app()->isLocal() ? 'brandonmb.localhost' : 'brandonmb.ca', 'solid', ['#22A06B']);
            $this->site('steinbach', 'steinbach', 'SteinbachMB', 'Steinbach Community Portal', 'Your Steinbach. Your community.',
                'A community portal for Steinbach, Manitoba.', 'steinbachmb.ca', 'solid', ['#C53F52'], 'draft');
            $this->site('winnipeg', 'winnipeg', 'WpgMB', 'Winnipeg Community Portal', 'Your Winnipeg. Your community.',
                'A community portal for Winnipeg, Manitoba.', 'wpgmb.ca', 'solid', ['#2162C1'], 'draft');

            foreach (Marketplace::CATEGORIES as $slug => $label) {
                ListingCategory::firstOrCreate(['slug' => $slug], ['name' => $label]);
            }

            if (! DB::table('network_configuration')->where('id', 1)->exists()) {
                $revision = DB::table('configuration_revisions')->insertGetId([
                    'settings' => json_encode(NetworkSettings::defaults(), JSON_THROW_ON_ERROR),
                    'created_at' => now(), 'updated_at' => now(),
                ]);
                DB::table('network_configuration')->insert(['id' => 1, 'published_id' => $revision]);
            }
        });
    }

    private function site(string $code, ?string $location, string $name, string $subtitle, string $tagline, string $description, string $host, string $type, array $colors, string $status = 'active'): void
    {
        $site = Site::firstOrCreate(['code' => $code], [
            'location_id' => $location ? Location::where('slug', $location)->value('id') : null,
            'name' => $name, 'subtitle' => $subtitle, 'tagline' => $tagline,
            'description' => $description, 'status' => $status,
            'accent' => ['type' => $type, 'colors' => $colors, 'thickness' => 4, 'direction' => 90],
        ]);
        $site->domain()->firstOrCreate([], ['host' => $host]);
    }
}

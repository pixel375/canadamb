# Manitoba locations

The starter seeder creates a Manitoba parent and a small set of cities, towns and named rural communities. Content, publishing scope, dedicated portals and geographic lookups use canonical location IDs. Brandon publishing binds the Brandon ID and rejects a conflicting value. Rural content keeps its own location.

To expand selectable geography, download the Manitoba English CSV from the Canadian Geographical Names Database, review the terms and entries, and extract it privately. The importer defaults to validation and recognizes inhabited places, towns, rural municipalities, northern communities and First Nation administrative or reserve names. It excludes roads, lakes, railway points, parks and other unsupported feature types.

    php artisan locations:import-cgn /private/path/cgn_mb_csv_eng.csv
    php artisan locations:import-cgn /private/path/cgn_mb_csv_eng.csv --apply

Source keys and imported coordinates are attributed to the Canadian Geographical Names Database under the Open Government Licence – Canada. The importer preserves seeded location IDs when matching known CGNDB identifiers. Coordinates identify named places. Boundary data, municipality relationships below province level and physical-address verification require separate reviewed data. Coverage of all valid Manitoba locations remains to be verified.

[Download geographical names data](https://natural-resources.canada.ca/maps-tools-publications/maps/geographical-names-canada/download-geographical-names-data) · [Open Government Licence – Canada](https://open.canada.ca/en/open-government-licence-canada)

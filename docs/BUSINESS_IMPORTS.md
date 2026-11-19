# Business imports

Use a private UTF-8 CSV from a source that permits the intended reuse. Review rights and the content before running the command. Keep the import file outside public_html. Columns are:

    external_key,name,category,location,address,website

The location value is a selectable CanadaMB location slug. The category uses a directory category key. Supply an HTTPS source reference and explicit license label.

    php artisan businesses:import /private/path/businesses.csv --source="Source name" --license="License or permission reference" --url="https://source.example/data"

A batch supports up to 1,000 entries, adds unclaimed active profiles, and rolls back its business changes if a row fails. Source identifiers and normalized name/address keys within a location deduplicate repeat rows. Existing profile data is preserved on reimport. Admin → Business review shows import status and counts. Multi-branch grouping across imported records requires a reviewed source identifier mapping.

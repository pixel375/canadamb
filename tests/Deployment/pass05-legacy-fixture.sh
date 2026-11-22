#!/usr/bin/env bash

# Shared synthetic prelaunch content from Pass 04, including real private WebP files.
media_root="$app/shared/storage/app/private"
listing_photo=listings/00000000-0000-4000-8000-000000008501.webp
listing_preview=listings/00000000-0000-4000-8000-000000008501-preview.webp
attachment=message-attachments/00000000-0000-4000-8000-000000008501.webp
media=("$listing_photo" "$listing_preview" "$attachment")
mkdir -p "$media_root/listings" "$media_root/message-attachments"
php -r '
    foreach (array_slice($argv, 1) as $index => $path) {
        $image = imagecreatetruecolor(24 - 4 * $index, 24 - 4 * $index);
        if (! imagefill($image, 0, 0, imagecolorallocate($image, 60 + 10 * $index, 100, 150)) || ! imagewebp($image, $path)) {
            exit(1);
        }
    }
' "$media_root/$listing_photo" "$media_root/$listing_preview" "$media_root/$attachment"
for path in "${media[@]}"; do
    test -s "$media_root/$path"
done
{
    printf 'SET @fixture_attachment_bytes := %s;\n' "$(stat -c '%s' "$media_root/$attachment")"
    cat "$repo/tests/Deployment/pass05-moderation.sql"
} | database_sql

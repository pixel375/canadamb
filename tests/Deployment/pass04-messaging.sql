INSERT INTO users (id, name, email, password, email_verified_at, created_at, updated_at) VALUES
    (8401, 'Buyer', 'ci-pass04-buyer@example.invalid', 'disabled-fixture', '2026-01-01 09:00:00', '2026-01-01 09:00:00', '2026-01-01 09:00:00'),
    (8402, 'Seller', 'ci-pass04-seller@example.invalid', 'disabled-fixture', '2026-01-01 09:00:00', '2026-01-01 09:00:00', '2026-01-01 09:00:00'),
    (8403, 'Blocked member', 'ci-pass04-blocked@example.invalid', 'disabled-fixture', NULL, '2026-01-01 09:00:00', '2026-01-01 09:00:00');

INSERT INTO listings (id, user_id, location_id, category, title, description, price_cents, `condition`, status, published_at)
    SELECT 8401, 8402, id, 'other', 'Legacy item', 'Disposable fixture.', 1000, 'good', 'sold', '2026-01-01 09:00:00'
    FROM locations WHERE slug = 'brandon';
INSERT INTO listings (id, user_id, location_id, category, title, description, price_cents, `condition`, status, published_at, expires_at)
    SELECT 8402, 8402, id, 'other', 'Available item', 'Concurrent send fixture.', 1000, 'good', 'active', NOW(), DATE_ADD(NOW(), INTERVAL 2 DAY)
    FROM locations WHERE slug = 'brandon';

INSERT INTO conversations (id, listing_id, buyer_id, seller_id, buyer_last_read_id, seller_last_read_id, created_at, updated_at) VALUES
    (8401, 8401, 8401, 8402, 8401, 8402, '2026-01-01 09:10:00', '2026-01-01 09:20:00');
INSERT INTO messages (id, conversation_id, sender_id, body, created_at, updated_at) VALUES
    (8401, 8401, 8401, 'Previous message.', '2026-01-01 09:10:00', '2026-01-01 09:10:00'),
    (8402, 8401, 8402, 'Unread reply.', '2026-01-01 09:20:00', '2026-01-01 09:20:00');
INSERT INTO user_blocks (user_id, blocked_id, created_at) VALUES (8401, 8403, '2026-01-01 09:25:00');

INSERT INTO member_settings (user_id, notify_marketplace_messages, show_listings) VALUES (8401, false, false);
INSERT INTO notifications (id, user_id, event, source_id, resource_id, read_at, created_at) VALUES
    (8401, 8402, 'message.received', 8401, 8401, '2026-01-01 09:15:00', '2026-01-01 09:10:00'),
    (8402, 8401, 'message.received', 8402, 8402, NULL, '2026-01-01 09:20:00');
INSERT INTO notification_locations (notification_id, location_id)
    SELECT 8401, id FROM locations WHERE slug = 'brandon';
INSERT INTO notification_locations (notification_id, location_id)
    SELECT 8402, id FROM locations WHERE slug = 'brandon';

INSERT INTO businesses (id, slug, name, category, status) VALUES (8401, 'rehearsal-business', 'Rehearsal business', 'retail', 'approved');
INSERT INTO business_locations (id, business_id, location_id, address)
    SELECT 8401, 8401, id, '1 Test Street' FROM locations WHERE slug = 'brandon';
INSERT INTO job_postings (id, business_location_id, author_id, title, description, employment_type, workplace, apply_url, closing_on)
    VALUES (8401, 8401, 8402, 'Legacy job', 'Disposable fixture.', 'full-time', 'onsite', 'https://example.invalid/apply', '2027-01-01');

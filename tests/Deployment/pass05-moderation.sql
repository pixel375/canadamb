INSERT INTO users (id, name, email, password, role, email_verified_at, created_at, updated_at) VALUES
    (8501, 'Legacy owner', 'ci-pass05-owner@example.invalid', 'disabled-fixture', 'member', '2026-01-01 09:00:00', '2026-01-01 09:00:00', '2026-01-01 09:00:00'),
    (8502, 'Legacy reporter', 'ci-pass05-reporter@example.invalid', 'disabled-fixture', 'member', '2026-01-01 09:00:00', '2026-01-01 09:00:00', '2026-01-01 09:00:00'),
    (8503, 'First reviewer', 'ci-pass05-first@example.invalid', 'disabled-fixture', 'network_admin', '2026-01-01 09:00:00', '2026-01-01 09:00:00', '2026-01-01 09:00:00'),
    (8504, 'Second reviewer', 'ci-pass05-second@example.invalid', 'disabled-fixture', 'super_admin', '2026-01-01 09:00:00', '2026-01-01 09:00:00', '2026-01-01 09:00:00');

SET @brandon := (SELECT id FROM locations WHERE slug = 'brandon');

INSERT INTO listings (id, user_id, location_id, category, title, description, price_cents, `condition`, status, published_at, expires_at) VALUES
    (8501, 8501, @brandon, 'other', 'Legacy listing', 'A previously approved Marketplace listing.', 1000, 'good', 'active', '2026-01-01 09:00:00', DATE_ADD(NOW(), INTERVAL 10 DAY)),
    (8502, 8501, @brandon, 'other', 'Review race listing', 'Two editors will decide from the same revision.', 2000, 'new', 'pending', NULL, NULL);
INSERT INTO listing_status_history (id, listing_id, actor_id, previous_status, status, reason, created_at) VALUES
    (8501, 8501, 8501, NULL, 'pending', NULL, '2026-01-01 08:59:00'),
    (8502, 8501, 8503, 'pending', 'active', NULL, '2026-01-01 09:00:00');
INSERT INTO listing_media (id, listing_id, image_path, thumbnail_path, position) VALUES
    (8501, 8501, 'listings/00000000-0000-4000-8000-000000008501.webp', 'listings/00000000-0000-4000-8000-000000008501-preview.webp', 0);
INSERT INTO listing_favourites (user_id, listing_id, created_at) VALUES (8502, 8501, '2026-01-01 09:05:00');
INSERT INTO listing_reports (id, listing_id, reporter_id, reason, resolved_at, created_at, updated_at) VALUES
    (8501, 8501, 8502, 'Unresolved legacy report.', NULL, '2026-01-01 09:06:00', '2026-01-01 09:06:00'),
    (8502, 8501, 8504, 'Resolved legacy report.', '2026-01-01 09:08:00', '2026-01-01 09:07:00', '2026-01-01 09:08:00');

INSERT INTO businesses (id, slug, name, category, status) VALUES
    (8501, 'pass05-legacy-business', 'Legacy business', 'retail', 'active'),
    (8502, 'pass05-pending-business', 'Pending business', 'retail', 'pending');
INSERT INTO business_locations (id, business_id, location_id, address, message_contact_user_id) VALUES
    (8501, 8501, @brandon, '1 Rehearsal Street', 8501),
    (8502, 8502, @brandon, '2 Rehearsal Street', NULL);
INSERT INTO business_memberships (business_id, user_id, role) VALUES (8501, 8501, 'owner'), (8502, 8502, 'owner');
INSERT INTO business_claims (id, business_id, user_id, contact_email, evidence, status, reviewer_id, notes, reviewed_at) VALUES
    (8501, 8501, 8501, 'ci-pass05-owner@example.invalid', 'Synthetic claim evidence.', 'approved', 8503, 'Prior private review note.', '2026-01-01 09:10:00'),
    (8502, 8502, 8502, 'ci-pass05-reporter@example.invalid', 'Synthetic pending claim.', 'pending', NULL, NULL, NULL);
INSERT INTO business_updates (id, business_location_id, author_id, type, title, body, status, published_at) VALUES
    (8501, 8501, 8501, 'update', 'Legacy business update', 'A previously approved business update.', 'approved', '2026-01-01 09:11:00');
INSERT INTO job_postings (id, business_location_id, author_id, title, description, employment_type, workplace, apply_url, closing_on, status, published_at, message_contact_user_id) VALUES
    (8501, 8501, 8501, 'Legacy job', 'A previously approved job.', 'full-time', 'on-site', 'https://example.invalid/apply', DATE_ADD(CURRENT_DATE(), INTERVAL 10 DAY), 'approved', '2026-01-01 09:12:00', 8501);

INSERT INTO community_threads (id, author_id, location_id, title, body, category, status, pinned, locked, last_activity_at) VALUES
    (8501, 8501, @brandon, 'Legacy discussion', 'A previously approved discussion.', 'general', 'approved', TRUE, FALSE, '2026-01-01 09:20:00');
INSERT INTO community_replies (id, thread_id, author_id, body, status) VALUES
    (8501, 8501, 8502, 'Previously read reply.', 'approved'),
    (8502, 8501, 8502, 'Previously unread reply.', 'approved');
INSERT INTO community_thread_follows (thread_id, user_id, created_at) VALUES (8501, 8501, '2026-01-01 09:13:00');
INSERT INTO community_notifications (id, user_id, reply_id, read_at, created_at) VALUES
    (8501, 8501, 8501, '2026-01-01 09:18:00', '2026-01-01 09:15:00'),
    (8502, 8501, 8502, NULL, '2026-01-01 09:20:00');
INSERT INTO community_events (id, author_id, location_id, title, body, category, organizer, venue, starts_at, ends_at, status) VALUES
    (8501, 8501, @brandon, 'Legacy event', 'A previously approved event.', 'community', 'Rehearsal group', 'Rehearsal hall', DATE_ADD(NOW(), INTERVAL 2 DAY), DATE_ADD(NOW(), INTERVAL 3 DAY), 'approved');

INSERT INTO conversations (id, listing_id, buyer_id, seller_id, buyer_last_read_id, seller_last_read_id, context_type, business_location_id, job_posting_id, location_id, created_at, updated_at) VALUES
    (8501, 8501, 8502, 8501, 8501, 8501, 'marketplace', NULL, NULL, @brandon, '2026-01-01 09:21:00', '2026-01-01 09:21:00'),
    (8502, NULL, 8502, 8501, 8502, 8502, 'business', 8501, NULL, @brandon, '2026-01-01 09:22:00', '2026-01-01 09:24:00'),
    (8503, NULL, 8502, 8501, 8504, 8504, 'job', NULL, 8501, @brandon, '2026-01-01 09:25:00', '2026-01-01 09:25:00');
INSERT INTO messages (id, conversation_id, sender_id, body, idempotency_key, created_at, updated_at) VALUES
    (8501, 8501, 8502, 'Legacy Marketplace message.', NULL, '2026-01-01 09:21:00', '2026-01-01 09:21:00'),
    (8502, 8502, 8501, 'Legacy reported message.', '00000000-0000-4000-8000-000000008502', '2026-01-01 09:22:00', '2026-01-01 09:22:00'),
    (8503, 8502, 8501, 'Unrelated private message.', NULL, '2026-01-01 09:24:00', '2026-01-01 09:24:00'),
    (8504, 8503, 8502, 'Legacy job message.', NULL, '2026-01-01 09:25:00', '2026-01-01 09:25:00');
INSERT INTO message_attachments (id, message_id, path, original_name, mime_type, size_bytes, created_at) VALUES
    (8501, 8502, 'message-attachments/00000000-0000-4000-8000-000000008501.webp', 'attached.webp', 'image/webp', @fixture_attachment_bytes, '2026-01-01 09:22:00');
INSERT INTO conversation_reports (id, conversation_id, reporter_id, message_id, reason, created_at) VALUES
    (8501, 8502, 8502, 8502, 'Specific prior conversation report.', '2026-01-01 09:23:00'),
    (8502, 8503, 8502, NULL, 'Prior conversation report without specified message.', '2026-01-01 09:26:00');
INSERT INTO message_reads (message_id, user_id, read_at) VALUES (8502, 8502, '2026-01-01 09:23:00');
INSERT INTO message_send_replays (sender_id, idempotency_key, message_id) VALUES (8501, '00000000-0000-4000-8000-000000008503', 8502);
INSERT INTO member_settings (user_id, notify_marketplace_messages, notify_business_messages, notify_job_messages) VALUES (8502, FALSE, TRUE, FALSE);
INSERT INTO notifications (id, user_id, event, source_id, resource_id, read_at, created_at) VALUES
    (8501, 8501, 'listing.approved', 8502, 8501, '2026-01-01 09:02:00', '2026-01-01 09:00:00'),
    (8502, 8502, 'message.received', 8502, 8502, '2026-01-01 09:23:00', '2026-01-01 09:22:00'),
    (8503, 8502, 'message.received', 8503, 8503, NULL, '2026-01-01 09:24:00');
INSERT INTO notification_locations (notification_id, location_id) VALUES (8501, @brandon), (8502, @brandon), (8503, @brandon);
INSERT INTO audit_logs (id, actor_id, action, context, created_at) VALUES
    (8501, 8503, 'listing.active', '{"listing":8501}', '2026-01-01 09:00:00'),
    (8502, 8503, 'listing.report-resolved', '{"report":8502,"note":"Historical decision"}', '2026-01-01 09:08:00'),
    (8503, 8502, 'message.reported', '{"conversation_id":8502,"report_id":8501,"message_id":8502}', '2026-01-01 09:23:00');
